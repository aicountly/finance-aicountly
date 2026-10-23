<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

/**
 * Payment-gateway webhooks: POST /api/v1/webhooks/razorpay and /api/v1/webhooks/cashfree.
 *
 *  1. The signature is checked over the raw body against every stored secret of that provider (test and live
 *     connections); none matches → 401 and a capped "rejected" log row without payload.
 *  2. The delivery is stored once per fingerprint (provider event id, else body hash): a replay or retry of a delivery
 *     already processed answers 200 duplicate and changes nothing.
 *  3. The event is applied to the link it names (our fin_pl_… id or the provider's link id). Nothing is trusted from the
 *     body alone: payments and refunds are re-read from the provider's API before they count (GatewayPaymentService),
 *     link status changes are re-read too. Events about links Finance did not create (e.g. made by hand in the
 *     provider dashboard) are logged as ignored.
 *  4. 200 once the delivery is stored and applied (verification may still be pending: the cron finishes it);
 *     500 only when Finance itself failed, so the provider retries.
 *
 * The stored payload is scrubbed of personal and instrument data (EventScrubber).
 */
final class WebhookService
{
    public const MAX_BODY         = 524_288;
    public const REJECTED_PER_HOUR = 200;
    public const VERIFY_TIMEOUT   = 4;

    /** @var array<string, class-string<GatewayAdapter>> */
    public const ADAPTERS = ['razorpay' => RazorpayAdapter::class, 'cashfree' => CashfreeAdapter::class];

    public function __construct(private \PDO $pdo, private GatewayConnectionService $connections, private GatewayPaymentService $payments)
    {
    }

    /**
     * @param array<string,string> $headers lower-case name => value
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(string $provider, string $raw, array $headers): array
    {
        $adapter = self::ADAPTERS[$provider] ?? null;
        if ($adapter === null) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'Unknown provider.']];
        }
        if (strlen($raw) > self::MAX_BODY) {
            return ['status' => 413, 'body' => ['ok' => false, 'error' => 'Body too large.']];
        }
        $secrets = $this->connections->webhookSecrets($provider);
        $connId  = null;
        foreach ($secrets as $s) {
            if ($adapter::verifySignature($headers, $raw, $s['secret'])) {
                $connId = $s['connection_id'];
                break;
            }
        }
        if ($connId === null) {
            $this->logRejected($provider, $raw, $secrets === [] ? 'No webhook secret is configured for this provider (Settings → Payment gateways).' : 'Signature does not verify.');

            return ['status' => $secrets === [] ? 503 : 401, 'body' => ['ok' => false, 'error' => $secrets === [] ? 'Webhooks are not configured.' : 'Invalid signature.']];
        }

        $event       = $adapter::parseWebhook($raw, $headers);
        $fingerprint = $adapter::fingerprint($raw, $headers);
        $scrubbed    = EventScrubber::fromBody($raw);
        $ins = $this->pdo->prepare(
            "INSERT INTO fin_gateway_webhook_events (provider, fingerprint, body_sha256, event_type, provider_event_id, connection_id, signature_valid, outcome, payload)
             VALUES (?, ?, ?, ?, ?, ?, true, 'received', ?::jsonb)
             ON CONFLICT (fingerprint) DO UPDATE SET deliveries = fin_gateway_webhook_events.deliveries + 1
             RETURNING id, outcome, (xmax = 0) AS inserted, received_at < now() - interval '2 minutes' AS stale",
        );
        $ins->execute([$provider, $fingerprint, hash('sha256', $raw), mb_substr((string) $event['event_type'], 0, 80), $event['event_id'], $connId,
            $scrubbed === null ? null : json_encode($scrubbed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $row = $ins->fetch();
        $id  = (int) $row['id'];
        if (!$row['inserted'] && !($row['outcome'] === 'error' || ($row['outcome'] === 'received' && $row['stale']))) {
            return ['status' => 200, 'body' => ['ok' => true, 'duplicate' => true]];
        }

        try {
            [$outcome, $detail, $linkId, $paymentId] = $this->apply($provider, $connId, $event, $scrubbed);
        } catch (\App\Support\ApiException $e) {
            // A signed event Finance cannot use as it stands (no usable id / amount, another link's payment): retrying will
            // not help, so it is acknowledged and logged for staff.
            if ($e->status() >= 500) {
                $this->finish($id, 'error', mb_substr($e->getMessage(), 0, 300), null, null);

                return ['status' => 503, 'body' => ['ok' => false, 'error' => 'Finance is not ready; retry later.']];
            }
            $this->finish($id, 'ignored', mb_substr('Not applied: ' . $e->getMessage(), 0, 300), null, null);

            return ['status' => 200, 'body' => ['ok' => true, 'outcome' => 'ignored']];
        } catch (\Throwable $e) {
            error_log("[finance] {$provider} webhook {$id} failed: " . $e->getMessage());
            $this->finish($id, 'error', mb_substr($e->getMessage(), 0, 300), null, null);

            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'Finance could not process the event; retry later.']];
        }
        $this->finish($id, $outcome, $detail, $linkId, $paymentId);

        return ['status' => 200, 'body' => ['ok' => true, 'outcome' => $outcome]];
    }

    /**
     * @param array<string,mixed> $event
     * @return array{0:string, 1:?string, 2:?int, 3:?int} outcome, detail, link id, gateway payment id
     */
    private function apply(string $provider, int $connId, array $event, ?array $scrubbed): array
    {
        if ($event['kind'] === 'ignored') {
            return ['ignored', $event['note'] ?? 'Not an event Finance uses.', null, null];
        }
        if ($event['kind'] === 'refund') {
            $r   = $event['refund'];
            $pay = $this->payments->byProviderId($provider, (string) $r['provider_payment_id']);
            if ($pay === null) {
                return ['ignored', 'Refund of a payment Finance does not hold (not one of Finance\'s links).', null, null];
            }
            $ref    = $this->payments->recordRefund($pay, $r, $scrubbed);
            $status = $this->payments->verifyRefund((int) $ref['id'], self::VERIFY_TIMEOUT);

            return ['processed', 'Refund ' . $r['provider_refund_id'] . ': ' . $status . '.', (int) $pay['link_id'], (int) $pay['id']];
        }

        $link = $this->findLink($provider, $event);
        if ($link === null) {
            return ['ignored', 'Not a Finance link (e.g. created by hand in the provider dashboard).', null, null];
        }
        if ((int) $link['connection_id'] !== $connId) {
            return ['ignored', 'Signed with the secret of another connection (test vs live?) than the link\'s own.', (int) $link['id'], null];
        }

        if ($event['kind'] === 'payment') {
            $pay    = $this->payments->record($link, $event['payment'], $scrubbed);
            $status = $this->payments->verify((int) $pay['id'], self::VERIFY_TIMEOUT);

            return ['processed', 'Payment ' . $pay['provider_payment_id'] . ': ' . $status . ($status === 'pending' ? ' (re-checked by the cron)' : '') . '.',
                (int) $link['id'], (int) $pay['id']];
        }

        // kind link: status change (expired / cancelled / paid without payment detail) — re-read the provider.
        try {
            $sync = $this->payments->reconcile((int) $link['id'], self::VERIFY_TIMEOUT);
        } catch (GatewayException $e) {
            return ['processed', 'Link status re-read failed (' . $e->getMessage() . '); the cron re-reads expired links.', (int) $link['id'], null];
        }

        return ['processed', 'Link re-read from the provider: ' . $sync['status'] . '.', (int) $link['id'], null];
    }

    /** @param array<string,mixed> $event */
    private function findLink(string $provider, array $event): ?array
    {
        if (is_string($event['reference'] ?? null)) {
            $stmt = $this->pdo->prepare('SELECT * FROM fin_payment_links WHERE link_id = ?');
            $stmt->execute([$event['reference']]);
            if ($row = $stmt->fetch()) {
                return $row;
            }
        }
        if (is_string($event['provider_link_id'] ?? null) && $event['provider_link_id'] !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM fin_payment_links WHERE provider = ? AND provider_link_id = ?');
            $stmt->execute([$provider, $event['provider_link_id']]);

            return $stmt->fetch() ?: null;
        }

        return null;
    }

    private function finish(int $id, string $outcome, ?string $detail, ?int $linkId, ?int $paymentId): void
    {
        $this->pdo->prepare('UPDATE fin_gateway_webhook_events SET outcome = ?, detail = ?, link_id = coalesce(?, link_id), gateway_payment_id = coalesce(?, gateway_payment_id),
                                    processed_at = now() WHERE id = ?')
            ->execute([$outcome, $detail, $linkId, $paymentId, $id]);
    }

    private function logRejected(string $provider, string $raw, string $why): void
    {
        error_log("[finance] {$provider} webhook rejected: {$why}");
        $n = $this->pdo->prepare("SELECT count(*) FROM fin_gateway_webhook_events WHERE provider = ? AND NOT signature_valid AND received_at > now() - interval '1 hour'");
        $n->execute([$provider]);
        if ((int) $n->fetchColumn() >= self::REJECTED_PER_HOUR) {
            return;
        }
        $this->pdo->prepare("INSERT INTO fin_gateway_webhook_events (provider, fingerprint, body_sha256, signature_valid, outcome, detail, processed_at)
                             VALUES (?, NULL, ?, false, 'rejected', ?, now())")
            ->execute([$provider, hash('sha256', $raw), $why]);
    }

    /** @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int} */
    public function log(array $f, int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        foreach (['provider', 'outcome'] as $k) {
            if (!empty($f[$k])) {
                $where[]  = "e.{$k} = ?";
                $params[] = $f[$k];
            }
        }
        if (!empty($f['link_id'])) {
            $where[]  = 'e.link_id = ?';
            $params[] = (int) $f['link_id'];
        }
        $sql     = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $count   = $this->pdo->prepare("SELECT count(*) FROM fin_gateway_webhook_events e {$sql}");
        $count->execute($params);
        $stmt = $this->pdo->prepare("SELECT e.id, e.provider, e.event_type, e.provider_event_id, e.signature_valid, e.outcome, e.detail, e.deliveries, e.received_at,
                                            e.processed_at, e.link_id, l.link_id AS link_ref, e.gateway_payment_id, e.payload
                                       FROM fin_gateway_webhook_events e LEFT JOIN fin_payment_links l ON l.id = e.link_id {$sql}
                                      ORDER BY e.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($params);
        $rows = array_map(static function (array $r): array {
            $r['id']                 = (int) $r['id'];
            $r['deliveries']         = (int) $r['deliveries'];
            $r['signature_valid']    = (bool) $r['signature_valid'];
            $r['link_id']            = $r['link_id'] === null ? null : (int) $r['link_id'];
            $r['gateway_payment_id'] = $r['gateway_payment_id'] === null ? null : (int) $r['gateway_payment_id'];
            $r['payload']            = $r['payload'] === null ? null : json_decode((string) $r['payload'], true);

            return $r;
        }, $stmt->fetchAll());

        return ['rows' => $rows, 'total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => $perPage];
    }
}
