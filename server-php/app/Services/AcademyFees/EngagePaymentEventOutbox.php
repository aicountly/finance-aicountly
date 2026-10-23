<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Security\AicS2s;
use App\Support\Money;
use App\Support\Ulid;

/**
 * Finance → Engage payment events for Academy fee links (table fin_engage_payment_events), AIC-S2S v1 signed:
 *
 *   POST {ENGAGE_API_BASE_URL}/v1/inbound/finance/payment-events
 *   X-AIC-Client: finance · X-AIC-Key-Id: {FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID} · X-AIC-Environment: {FINANCE_S2S_ENVIRONMENT}
 *   X-AIC-Signature: v1=HMAC-SHA256({FINANCE_OUTBOUND_ENGAGE_S2S_SECRET}, canonical)   (Engage: filter aic-s2s:finance, issue key)
 *   Idempotency-Key: {event_id}
 *   {event_id, event_type: payment.succeeded | payment.refunded, occurred_at, lead_code, payment_ref, provider, product_code,
 *    amount, amount_paise, currency, paid_at, method, mode, stream, academy_ref, seller_code, link_id, finance_payment_id,
 *    fee{…}, refund|null}   (no payer data: Engage already has the lead; see payload())
 *
 * Events are written in the transaction that verifies the payment (or refund), so one is never lost or sent for a
 * change that rolled back. bin/academy-fees-cron.php delivers them: delivered only on 2xx with Engage's receipt
 * ({success:true, data:{event_id}} — or {ok:true, data:{event_id}}); 400 / 404 / 409 / 422 = Engage refused the event →
 * dead at once (fix, then a finance admin re-queues it); anything else → retry 2, 4, 8 … minutes (at most 6 h apart),
 * dead after 12 attempts. Events of one link are delivered strictly in order. Unconfigured → nothing is sent.
 * Only links that carry an Engage lead code (source_ref) produce events.
 */
final class EngagePaymentEventOutbox
{
    public const PATH         = '/v1/inbound/finance/payment-events';
    public const MAX_ATTEMPTS = 12;
    public const MAX_DELAY    = 360;

    /** @var callable(string,array<string,string>,string):array{status:int, error:?string, body?:?string} */
    private $post;

    public function __construct(private \PDO $pdo, ?callable $post = null)
    {
        $this->post = $post ?? [\App\Services\Payouts\OutboxService::class, 'curlPost'];
    }

    public static function isConfigured(): bool
    {
        return finance_env('ENGAGE_API_BASE_URL') !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', finance_env('FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID')) === 1
            && strlen(finance_env('FINANCE_OUTBOUND_ENGAGE_S2S_SECRET')) >= AicS2s::MIN_SECRET;
    }

    /**
     * Queue payment.succeeded for a verified payment (once per payment). Call inside the verifying transaction.
     *
     * @param array<string,mixed> $link fin_payment_links row
     * @param array<string,mixed> $payment fin_gateway_payments row (verified)
     */
    public function enqueueSucceeded(array $link, array $payment): ?string
    {
        if (($link['source_ref'] ?? null) === null) {
            return null;
        }

        return $this->insert('payment.succeeded', $link, $payment, null);
    }

    /** @param array<string,mixed> $refund fin_gateway_refunds row (verified) */
    public function enqueueRefunded(array $link, array $payment, array $refund): ?string
    {
        if (($link['source_ref'] ?? null) === null) {
            return null;
        }

        return $this->insert('payment.refunded', $link, $payment, $refund);
    }

    /** @return string the event id (an existing one when this payment / refund was already queued) */
    private function insert(string $type, array $link, array $payment, ?array $refund): string
    {
        $eventId = 'fin_evt_' . Ulid::generate();
        $payload = self::payload($eventId, $type, $link, $payment, $refund);
        $stmt = $this->pdo->prepare(
            'INSERT INTO fin_engage_payment_events (event_id, event_type, aggregate_key, link_id, gateway_payment_id, refund_id, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?::json) ON CONFLICT DO NOTHING RETURNING event_id',
        );
        $stmt->execute([$eventId, $type, 'link:' . (int) $link['id'], (int) $link['id'], (int) $payment['id'], $refund === null ? null : (int) $refund['id'],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $got = $stmt->fetchColumn();
        if (is_string($got)) {
            return $got;
        }
        $q = $refund === null
            ? $this->pdo->prepare("SELECT event_id FROM fin_engage_payment_events WHERE gateway_payment_id = ? AND event_type = 'payment.succeeded'")
            : $this->pdo->prepare('SELECT event_id FROM fin_engage_payment_events WHERE refund_id = ?');
        $q->execute([$refund === null ? (int) $payment['id'] : (int) $refund['id']]);

        return (string) $q->fetchColumn();
    }

    /**
     * The event body (contract with Engage, docs/ACADEMY_PAYMENTS.md § "Finance → Engage"). Flat, so Engage can confirm
     * the payment on the lead directly:
     *
     *   payment_ref   the raw gateway payment id exactly as staff would type it — Razorpay pay_… (never plink_…), Cashfree
     *                 the bare cf_payment_id as a string. Engage de-duplicates on (lead, payment_ref), so a payment staff
     *                 already confirmed by hand on launch day is not recorded twice.
     *   lead_code     the Engage lead reference Engage sent as source_ref when it asked for the link
     *   amount        INR, decimal string with two places and no grouping ("11800.00"); amount_paise the same in paise
     *   event_id      stable across retries (also the Idempotency-Key header)
     *
     * @return array<string,mixed>
     */
    public static function payload(string $eventId, string $type, array $link, array $payment, ?array $refund): array
    {
        $amount = (int) $payment['amount_paise'];

        return [
            'event_id'           => $eventId,
            'event_type'         => $type,
            'occurred_at'        => self::iso($refund['refunded_at'] ?? $payment['paid_at']),
            'lead_code'          => $link['source_ref'],
            'payment_ref'        => (string) $payment['gateway_payment_ref'],
            'provider'           => (string) $payment['provider'],
            'product_code'       => (string) $link['product_code'],
            'amount'             => Money::format($amount),
            'amount_paise'       => $amount,
            'currency'           => 'INR',
            'paid_at'            => self::iso($payment['paid_at']),
            'method'             => $payment['method'],
            'mode'               => (string) $payment['mode'],
            'stream'             => (string) $link['stream'],
            'academy_ref'        => (string) $link['academy_ref'],
            'seller_code'        => $link['seller_code'],
            'link_id'            => (string) $link['link_id'],
            'finance_payment_id' => (string) $payment['payment_ref'],
            'fee'                => [
                'taxable_paise' => (int) $link['fee_taxable_paise'], 'cgst_paise' => (int) $link['fee_cgst_paise'], 'sgst_paise' => (int) $link['fee_sgst_paise'],
                'igst_paise' => (int) $link['fee_igst_paise'], 'sac' => (string) $link['fee_sac'], 'gst_rate' => (string) $link['fee_gst_rate'],
                'price_basis' => (string) $link['fee_price_basis'],
            ],
            'refund'             => $refund === null ? null : [
                'provider_refund_id'   => (string) $refund['provider_refund_id'],
                'amount'               => Money::format((int) $refund['amount_paise']),
                'amount_paise'         => (int) $refund['amount_paise'],
                'refunded_at'          => self::iso($refund['refunded_at']),
                'total_refunded_paise' => (int) $payment['refunded_paise'],
                'fully_refunded'       => (int) $payment['refunded_paise'] >= $amount,
            ],
        ];
    }

    /**
     * @return array{configured:bool, attempted:int, delivered:int, retrying:int, dead:int}
     */
    public function deliver(int $limit = 100): array
    {
        $summary = ['configured' => self::isConfigured(), 'attempted' => 0, 'delivered' => 0, 'retrying' => 0, 'dead' => 0];
        if (!$summary['configured']) {
            return $summary;
        }
        $base   = rtrim(finance_env('ENGAGE_API_BASE_URL'), '/');
        $keyId  = finance_env('FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID');
        $secret = finance_env('FINANCE_OUTBOUND_ENGAGE_S2S_SECRET');
        $env    = AicS2s::localEnvironment();

        while ($summary['attempted'] < $limit) {
            $batch = $this->claim(min(50, $limit - $summary['attempted']));
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $summary['attempted']++;
                $body    = (string) $row['payload'];
                $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Idempotency-Key' => (string) $row['event_id']]
                    + AicS2s::sign($secret, 'finance', $keyId, $env, 'POST', self::PATH, '', $body);
                $res      = ($this->post)($base . self::PATH, $headers, $body);
                $attempts = (int) $row['attempts'] + 1;
                $status   = (int) $res['status'];
                $is2xx    = $status >= 200 && $status < 300;
                if ($is2xx && !self::isEngageReceipt($res['body'] ?? null, (string) $row['event_id'])) {
                    $res['error'] = 'HTTP ' . $status . " but the answer is not Engage's payment-event receipt (JSON {success:true, data:{event_id}})"
                        . ' — check ENGAGE_API_BASE_URL (production: https://engage.aicountly.org/api, with /api)';
                    $is2xx = false;
                }
                if ($is2xx) {
                    $this->pdo->prepare("UPDATE fin_engage_payment_events SET status = 'delivered', attempts = ?, last_http_status = ?, last_error = NULL, delivered_at = now() WHERE id = ?")
                        ->execute([$attempts, $status, $row['id']]);
                    $summary['delivered']++;
                    continue;
                }
                $error   = mb_substr((string) ($res['error'] ?? ('HTTP ' . $status)), 0, 500);
                $refused = in_array($status, [400, 404, 409, 422], true);
                if ($refused || $attempts >= self::MAX_ATTEMPTS) {
                    $this->pdo->prepare("UPDATE fin_engage_payment_events SET status = 'dead', attempts = ?, last_http_status = ?, last_error = ? WHERE id = ?")
                        ->execute([$attempts, $status ?: null, ($refused ? 'Engage refused the event: ' : '') . $error, $row['id']]);
                    $summary['dead']++;
                    continue;
                }
                $delay = min(self::MAX_DELAY, 2 ** $attempts);
                $this->pdo->prepare("UPDATE fin_engage_payment_events SET status = 'pending', attempts = ?, last_http_status = ?, last_error = ?,
                                            next_attempt_at = now() + make_interval(mins => ?) WHERE id = ?")
                    ->execute([$attempts, $status ?: null, $error, $delay, $row['id']]);
                $summary['retrying']++;
            }
        }

        return $summary;
    }

    /** @return list<array<string,mixed>> due events, earliest undelivered per link only, leased for 10 minutes */
    private function claim(int $n): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE fin_engage_payment_events SET next_attempt_at = now() + interval '10 minutes'
             WHERE id IN (
                SELECT o.id FROM fin_engage_payment_events o
                 WHERE o.status = 'pending' AND o.next_attempt_at <= now()
                   AND NOT EXISTS (SELECT 1 FROM fin_engage_payment_events p
                                    WHERE p.aggregate_key = o.aggregate_key AND p.id < o.id AND p.status <> 'delivered')
                 ORDER BY o.id
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED)
            RETURNING id, event_id, payload::text AS payload, attempts
        SQL);
        $stmt->execute([$n]);
        $rows = $stmt->fetchAll();
        usort($rows, static fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    public function requeue(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE fin_engage_payment_events SET status = 'pending', attempts = 0, next_attempt_at = now() WHERE id = ? AND status = 'dead'");
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /** @return array{counts:array<string,int>, recent:list<array<string,mixed>>, configured:bool} */
    public function overview(int $limit = 50, ?int $linkId = null): array
    {
        $counts = ['pending' => 0, 'delivered' => 0, 'dead' => 0];
        foreach ($this->pdo->query('SELECT status, count(*) AS n FROM fin_engage_payment_events GROUP BY status')->fetchAll() as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.event_id, e.event_type, e.status, e.attempts, e.next_attempt_at, e.last_http_status, e.last_error, e.created_at, e.delivered_at,
                    e.link_id, l.link_id AS link_ref, l.source_ref, l.academy_ref, (e.payload->'payment'->>'amount_paise')::bigint AS amount_paise
               FROM fin_engage_payment_events e JOIN fin_payment_links l ON l.id = e.link_id
              WHERE (?::bigint IS NULL OR e.link_id = ?::bigint)
              ORDER BY (e.status = 'delivered'), e.id DESC LIMIT ?",
        );
        $stmt->execute([$linkId, $linkId, $limit]);
        $rows = array_map(static function (array $r): array {
            foreach (['id', 'attempts', 'link_id', 'amount_paise'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }

            return $r;
        }, $stmt->fetchAll());

        return ['counts' => $counts, 'recent' => $rows, 'configured' => self::isConfigured()];
    }

    public static function isEngageReceipt(?string $body, string $eventId): bool
    {
        $json = is_string($body) ? json_decode($body, true) : null;

        return is_array($json) && (($json['success'] ?? null) === true || ($json['ok'] ?? null) === true) && is_array($json['data'] ?? null)
            && ($json['data']['event_id'] ?? null) === $eventId;
    }

    private static function iso(mixed $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }

        return (new \DateTimeImmutable((string) $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format(\DateTimeInterface::ATOM);
    }
}
