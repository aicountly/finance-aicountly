<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Services\AuditLog;
use App\Services\Invoicing\InvoiceService;
use App\Support\ApiException;
use App\Support\Ulid;

/**
 * Gateway payments and refunds of Academy fee links: record what a provider reports, verify it by re-reading the
 * provider's own API, then — once, in one transaction — mark the payment verified, move the link to paid, and queue
 * payment.succeeded for Engage. The GST invoice is issued right after that commit (InvoiceService::issueForPayment over
 * GatewayPaymentInvoiceSource); a configuration gap leaves the payment blocked_config for the cron to retry.
 *
 *   reported (webhook / reconcile) → pending ──re-read confirms──▶ verified ─▶ link paid, Engage event, invoice
 *                                           └─not confirmed after MAX_VERIFY_ATTEMPTS──▶ mismatch (staff look at it)
 *
 * A second payment on a link that is already paid is verified (the money did arrive) but never invoiced or sent to
 * Engage: it is shown as a duplicate for staff to refund.
 */
final class GatewayPaymentService
{
    public const SYSTEM            = ['actor' => 'system:academy-fees', 'name' => 'Finance Academy fees', 'ip' => null];
    public const MAX_VERIFY_ATTEMPTS = 48;

    private AuditLog $audit;
    private EngagePaymentEventOutbox $outbox;

    public function __construct(private \PDO $pdo, private GatewayConnectionService $connections, private ?InvoiceService $invoices = null, ?EngagePaymentEventOutbox $outbox = null)
    {
        $this->audit  = new AuditLog($pdo);
        $this->outbox = $outbox ?? new EngagePaymentEventOutbox($pdo);
    }

    private function invoices(): InvoiceService
    {
        return $this->invoices ??= new InvoiceService($this->pdo);
    }

    // ------------------------------------------------------------------ recording

    /**
     * Store a payment the provider reported for a link (idempotent on provider + provider payment id).
     *
     * @param array<string,mixed> $link fin_payment_links row
     * @param array<string,mixed> $p normalised payment (GatewayAdapter)
     * @return array<string,mixed> the fin_gateway_payments row
     */
    public function record(array $link, array $p, ?array $rawEvent): array
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,100}$/', (string) $p['provider_payment_id']) || (int) $p['amount_paise'] <= 0) {
            throw ApiException::validation('The provider reported a payment without a usable id or amount.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO fin_gateway_payments (payment_ref, provider, connection_id, mode, provider_payment_id, provider_txn_ref, link_id, amount_paise, method, paid_at, raw_event)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb) ON CONFLICT (provider, provider_payment_id) DO NOTHING RETURNING id',
        );
        $stmt->execute(['fin_pay_' . Ulid::generate(), $link['provider'], (int) $link['connection_id'], $link['mode'], (string) $p['provider_payment_id'],
            $p['provider_txn_ref'] ?? null, (int) $link['id'], (int) $p['amount_paise'], $p['method'] ?? null, $p['paid_at'] ?? date(DATE_ATOM),
            $rawEvent === null ? null : json_encode($rawEvent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $id  = $stmt->fetchColumn();
        $row = $this->byProviderId((string) $link['provider'], (string) $p['provider_payment_id']);
        if ((int) $row['link_id'] !== (int) $link['id']) {
            throw ApiException::conflict('This provider payment is already recorded against another link.');
        }
        if ($id !== false) {
            $this->audit->record(self::SYSTEM, 'gateway_payment.reported', 'gateway_payment', (int) $row['id'], null, [
                'link_id' => $link['link_id'], 'provider' => $link['provider'], 'provider_payment_id' => $row['provider_payment_id'],
                'amount_paise' => (int) $row['amount_paise'],
            ]);
        }

        return $row;
    }

    /**
     * Re-read one pending payment from the provider; verify it when the provider confirms it as a captured payment of
     * its link. Returns the payment's verification status afterwards. Provider trouble leaves it pending (retried).
     */
    public function verify(int $paymentId, ?int $timeout = null): string
    {
        $pay = $this->row($paymentId);
        if ($pay['verification_status'] !== 'pending') {
            return (string) $pay['verification_status'];
        }
        $link = $this->link((int) $pay['link_id']);
        try {
            $conf = $this->connections->adapter($this->connections->row((int) $pay['connection_id']), $timeout)
                ->confirmPayment((string) $link['provider_link_id'], (string) $link['link_id'], (string) $pay['provider_payment_id']);
        } catch (GatewayException $e) {
            $this->notConfirmed($paymentId, $e->getMessage());

            return 'pending';
        }
        if ($conf === null) {
            return $this->notConfirmed($paymentId, 'The provider does not (yet) show this payment as captured on the link.');
        }

        return $this->markVerified($paymentId, $conf);
    }

    private function notConfirmed(int $paymentId, string $why): string
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fin_gateway_payments SET verification_attempts = verification_attempts + 1, verification_error = ?,
                    verification_status = CASE WHEN verification_attempts + 1 >= ? THEN 'mismatch' ELSE 'pending' END, updated_at = now()
              WHERE id = ? AND verification_status = 'pending' RETURNING verification_status",
        );
        $stmt->execute([mb_substr($why, 0, 300), self::MAX_VERIFY_ATTEMPTS, $paymentId]);
        $status = (string) ($stmt->fetchColumn() ?: 'pending');
        if ($status === 'mismatch') {
            error_log("[finance] academy fee payment {$paymentId} could not be confirmed with the provider: {$why}");
            $this->audit->record(self::SYSTEM, 'gateway_payment.unconfirmed', 'gateway_payment', $paymentId, null, ['reason' => $why]);
        }

        return $status;
    }

    /**
     * The provider confirmed the payment ($conf, read from its API): verify it, move the link, queue Engage's event, then
     * issue the invoice. Idempotent.
     *
     * @param array<string,mixed> $conf normalised payment as the provider's API shows it
     */
    public function markVerified(int $paymentId, array $conf): string
    {
        $issue = false;
        $this->pdo->beginTransaction();
        try {
            $pay  = $this->row($paymentId, true);
            $link = $this->link((int) $pay['link_id'], true);
            if ($pay['verification_status'] !== 'pending') {
                $this->pdo->commit();

                return (string) $pay['verification_status'];
            }
            $ref = (string) ($conf['gateway_payment_ref'] ?? '');
            if ($ref === '' || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $ref)) {
                $this->pdo->commit();

                return $this->notConfirmed($paymentId, 'The provider\'s answer has no payment id to report to Engage.');
            }
            if ((int) $conf['amount_paise'] !== (int) $pay['amount_paise'] || !$conf['captured']) {
                $this->pdo->prepare("UPDATE fin_gateway_payments SET verification_status = 'mismatch', verification_error = ?, verification_attempts = verification_attempts + 1,
                                            updated_at = now() WHERE id = ?")
                    ->execute(['The provider shows ' . (int) $conf['amount_paise'] . ' paise' . ($conf['captured'] ? '' : ' (not captured)')
                        . ' where the webhook reported ' . (int) $pay['amount_paise'] . ' paise.', $paymentId]);
                $this->audit->record(self::SYSTEM, 'gateway_payment.mismatch', 'gateway_payment', $paymentId, null,
                    ['reported_paise' => (int) $pay['amount_paise'], 'provider_paise' => (int) $conf['amount_paise'], 'captured' => (bool) $conf['captured']]);
                $this->pdo->commit();

                return 'mismatch';
            }
            $this->pdo->prepare("UPDATE fin_gateway_payments SET verification_status = 'verified', verified_at = clock_timestamp(), verification_error = NULL,
                                        verification_attempts = verification_attempts + 1, method = coalesce(?, method),
                                        gateway_payment_ref = coalesce(gateway_payment_ref, ?), updated_at = now() WHERE id = ?")
                ->execute([$conf['method'] ?? null, $ref, $paymentId]);
            $pay       = $this->row($paymentId);
            $duplicate = GatewayPaymentInvoiceSource::isDuplicate($this->pdo, $pay);
            $sum       = (int) $this->pdo->query("SELECT coalesce(sum(amount_paise), 0) FROM fin_gateway_payments WHERE link_id = " . (int) $link['id']
                . " AND verification_status = 'verified'")->fetchColumn();
            $full      = $sum >= (int) $link['amount_paise'];
            $this->pdo->prepare("UPDATE fin_payment_links SET amount_paid_paise = ?, status = ?, paid_at = CASE WHEN ? THEN coalesce(paid_at, ?::timestamptz) ELSE paid_at END,
                                        updated_at = now() WHERE id = ?")
                ->execute([$sum, $full ? 'paid' : 'partially_paid', $full ? 'true' : 'false', $pay['paid_at'], (int) $link['id']]);
            if ($duplicate) {
                $this->pdo->prepare("UPDATE fin_gateway_payments SET invoice_state = 'blocked_data', invoice_error = ? WHERE id = ?")
                    ->execute(['Duplicate payment: the link was already paid by an earlier payment. Not invoiced and not sent to Engage — refund it in the provider dashboard.', $paymentId]);
            }
            $this->audit->record(self::SYSTEM, 'gateway_payment.verified', 'gateway_payment', $paymentId, ['verification_status' => 'pending'], [
                'verification_status' => 'verified', 'link_id' => $link['link_id'], 'amount_paise' => (int) $pay['amount_paise'], 'link_status' => $full ? 'paid' : 'partially_paid',
                'duplicate' => $duplicate,
            ]);
            if (!$duplicate) {
                $eventId = $this->outbox->enqueueSucceeded($this->link((int) $link['id']), $pay);
                if ($eventId !== null) {
                    $this->audit->record(self::SYSTEM, 'engage_event.queued', 'payment_link', (int) $link['id'], null, ['event_id' => $eventId, 'event_type' => 'payment.succeeded']);
                }
                $issue = true;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($issue) {
            $this->issueInvoice($paymentId);
        }

        return 'verified';
    }

    /**
     * Issue (or retry) the GST invoice of a verified payment. Never throws for configuration or data problems: the
     * payment records blocked_config / blocked_data and the reason.
     *
     * @return array{state:string, invoice:?array, error:?string}
     */
    public function issueInvoice(int $paymentId): array
    {
        $pay = $this->row($paymentId);
        if ($pay['verification_status'] !== 'verified') {
            return ['state' => (string) $pay['invoice_state'], 'invoice' => null, 'error' => 'Not verified.'];
        }
        if ($pay['invoice_state'] === 'blocked_data' && str_starts_with((string) $pay['invoice_error'], 'Duplicate payment')) {
            return ['state' => 'blocked_data', 'invoice' => null, 'error' => (string) $pay['invoice_error']];
        }
        try {
            $inv = $this->invoices()->issueForPayment($paymentId, new GatewayPaymentInvoiceSource($this->pdo), InvoiceService::SYSTEM);
            $this->pdo->prepare("UPDATE fin_gateway_payments SET invoice_state = 'issued', invoice_error = NULL, invoice_attempts = invoice_attempts + 1, updated_at = now() WHERE id = ?")
                ->execute([$paymentId]);

            return ['state' => 'issued', 'invoice' => $inv, 'error' => null];
        } catch (ApiException $e) {
            $state = in_array($e->errorCode(), InvoiceService::CONFIG_CODES, true) ? 'blocked_config' : 'blocked_data';
            $msg   = $e->getMessage() . ($e->errors() !== [] ? ' ' . json_encode($e->errors(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
            $this->pdo->prepare('UPDATE fin_gateway_payments SET invoice_state = ?, invoice_error = ?, invoice_attempts = invoice_attempts + 1, updated_at = now()
                                  WHERE id = ? AND invoice_state <> \'issued\'')
                ->execute([$state, mb_substr($msg, 0, 500), $paymentId]);
            error_log("[finance] academy fee invoice {$state} for gateway payment {$paymentId}: {$e->getMessage()}");

            return ['state' => $state, 'invoice' => null, 'error' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------ reconcile (refresh from the provider)

    /**
     * Re-read a link from its provider: record and verify every captured payment it lists, confirm pending ones when
     * the provider does not list payments, and follow expiry / cancellation. The safety net for missed webhooks.
     *
     * @return array{status:string, provider_status:?string, payments_verified:int}
     */
    public function reconcile(int $linkId, ?int $timeout = null): array
    {
        $link = $this->link($linkId);
        if ($link['provider_link_id'] === null) {
            return ['status' => (string) $link['status'], 'provider_status' => null, 'payments_verified' => 0];
        }
        $adapter = $this->connections->adapter($this->connections->row((int) $link['connection_id']), $timeout);
        $data    = $adapter->fetchLink((string) $link['provider_link_id'], (string) $link['link_id']);
        $verified = 0;
        // Payments the provider lists as captured are recorded (a missed webhook); every pending one is then confirmed
        // through confirmPayment(), which also reads the payment id staff see in the dashboard.
        foreach ((array) ($data['payments'] ?? []) as $p) {
            if ($p['captured'] && $p['provider_payment_id'] !== '') {
                $this->record($link, $p, null);
            }
        }
        $pending = $this->pdo->prepare("SELECT id FROM fin_gateway_payments WHERE link_id = ? AND verification_status = 'pending' ORDER BY id");
        $pending->execute([$linkId]);
        foreach (array_column($pending->fetchAll(), 'id') as $pid) {
            if ($this->verify((int) $pid, $timeout) === 'verified') {
                $verified++;
            }
        }
        $link = $this->link($linkId);
        $next = (string) $link['status'];
        if (in_array($next, ['created', 'creating', 'failed'], true) && in_array($data['status'], ['expired', 'cancelled'], true)) {
            $next = (string) $data['status'];
        }
        $this->pdo->prepare('UPDATE fin_payment_links SET provider_status = ?, status = ?, last_synced_at = now(),
                                    cancelled_at = CASE WHEN ? = \'cancelled\' AND cancelled_at IS NULL THEN now() ELSE cancelled_at END, updated_at = now() WHERE id = ?')
            ->execute([$data['provider_status'] ?: null, $next, $next, $linkId]);
        if ($next !== $link['status']) {
            $this->audit->record(self::SYSTEM, 'payment_link.synced', 'payment_link', $linkId, ['status' => $link['status']], ['status' => $next, 'provider_status' => $data['provider_status']]);
        }

        return ['status' => $next, 'provider_status' => $data['provider_status'] ?: null, 'payments_verified' => $verified];
    }

    // ------------------------------------------------------------------ refunds

    /** @param array<string,mixed> $r normalised refund */
    public function recordRefund(array $payment, array $r, ?array $rawEvent): array
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,100}$/', (string) $r['provider_refund_id']) || (int) $r['amount_paise'] <= 0) {
            throw ApiException::validation('The provider reported a refund without a usable id or amount.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO fin_gateway_refunds (provider, provider_refund_id, payment_id, amount_paise, refunded_at, raw_event)
             VALUES (?, ?, ?, ?, ?, ?::jsonb) ON CONFLICT (provider, provider_refund_id) DO NOTHING RETURNING id',
        );
        $stmt->execute([$payment['provider'], (string) $r['provider_refund_id'], (int) $payment['id'], (int) $r['amount_paise'], $r['refunded_at'] ?? date(DATE_ATOM),
            $rawEvent === null ? null : json_encode($rawEvent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $sel = $this->pdo->prepare('SELECT * FROM fin_gateway_refunds WHERE provider = ? AND provider_refund_id = ?');
        $sel->execute([$payment['provider'], (string) $r['provider_refund_id']]);

        return $sel->fetch();
    }

    /** Re-read a pending refund from the provider; verified refunds raise refunded_paise and queue payment.refunded. */
    public function verifyRefund(int $refundId, ?int $timeout = null): string
    {
        $sel = $this->pdo->prepare('SELECT * FROM fin_gateway_refunds WHERE id = ?');
        $sel->execute([$refundId]);
        $ref = $sel->fetch();
        if (!$ref || $ref['verification_status'] !== 'pending') {
            return (string) ($ref['verification_status'] ?? 'unknown');
        }
        $pay = $this->row((int) $ref['payment_id']);
        try {
            $conf = $this->connections->adapter($this->connections->row((int) $pay['connection_id']), $timeout)
                ->fetchRefund((string) $pay['provider_payment_id'], (string) $ref['provider_refund_id']);
        } catch (GatewayException $e) {
            $this->pdo->prepare('UPDATE fin_gateway_refunds SET verification_attempts = verification_attempts + 1, verification_error = ?, updated_at = now() WHERE id = ?')
                ->execute([$e->getMessage(), $refundId]);

            return 'pending';
        }
        if ($conf === null || !$conf['processed'] || (int) $conf['amount_paise'] !== (int) $ref['amount_paise']) {
            $this->pdo->prepare("UPDATE fin_gateway_refunds SET verification_attempts = verification_attempts + 1, verification_error = ?,
                                        verification_status = CASE WHEN verification_attempts + 1 >= ? THEN 'mismatch' ELSE 'pending' END, updated_at = now() WHERE id = ?")
                ->execute(['The provider does not show this refund as processed for this amount.', self::MAX_VERIFY_ATTEMPTS, $refundId]);

            return 'pending';
        }

        $this->pdo->beginTransaction();
        try {
            $pay  = $this->row((int) $ref['payment_id'], true);
            $link = $this->link((int) $pay['link_id'], true);
            $upd  = $this->pdo->prepare("UPDATE fin_gateway_refunds SET verification_status = 'verified', verified_at = now(), verification_error = NULL,
                                                verification_attempts = verification_attempts + 1, updated_at = now() WHERE id = ? AND verification_status = 'pending'");
            $upd->execute([$refundId]);
            if ($upd->rowCount() === 1) {
                $total = (int) $this->pdo->query("SELECT coalesce(sum(amount_paise), 0) FROM fin_gateway_refunds WHERE payment_id = " . (int) $pay['id']
                    . " AND verification_status = 'verified'")->fetchColumn();
                if ($total > (int) $pay['amount_paise']) {
                    throw new \RuntimeException("Refunds of gateway payment {$pay['id']} exceed the payment.");
                }
                $this->pdo->prepare('UPDATE fin_gateway_payments SET refunded_paise = ?, updated_at = now() WHERE id = ?')->execute([$total, (int) $pay['id']]);
                $pay = $this->row((int) $pay['id']);
                $sel->execute([$refundId]);
                $ref = $sel->fetch();
                $this->audit->record(self::SYSTEM, 'gateway_refund.verified', 'gateway_payment', (int) $pay['id'], null, [
                    'provider_refund_id' => $ref['provider_refund_id'], 'amount_paise' => (int) $ref['amount_paise'], 'refunded_paise' => $total,
                    'note' => 'Raise the credit note on the invoice (Invoices → the invoice → Request credit note).',
                ]);
                if ($pay['verification_status'] === 'verified' && !GatewayPaymentInvoiceSource::isDuplicate($this->pdo, $pay)) {
                    $this->outbox->enqueueRefunded($link, $pay, $ref);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return 'verified';
    }

    // ------------------------------------------------------------------ cron

    /**
     * Housekeeping (bin/academy-fees-cron.php): re-verify pending payments and refunds, retry invoices waiting on
     * configuration, resume link creations interrupted mid-way, and re-read links past their expiry.
     *
     * @return array<string,int>
     */
    public function housekeeping(PaymentLinkService $links, int $limit = 50): array
    {
        $out = ['verified' => 0, 'still_pending' => 0, 'refunds_verified' => 0, 'invoices_issued' => 0, 'invoices_blocked' => 0, 'links_resumed' => 0, 'links_synced' => 0, 'errors' => 0];
        foreach ($this->ids("SELECT id FROM fin_gateway_payments WHERE verification_status = 'pending' AND updated_at < now() - interval '1 minute' ORDER BY id LIMIT {$limit}") as $id) {
            $this->verify($id) === 'verified' ? $out['verified']++ : $out['still_pending']++;
        }
        foreach ($this->ids("SELECT id FROM fin_gateway_refunds WHERE verification_status = 'pending' ORDER BY id LIMIT {$limit}") as $id) {
            if ($this->verifyRefund($id) === 'verified') {
                $out['refunds_verified']++;
            }
        }
        foreach ($this->ids("SELECT id FROM fin_gateway_payments WHERE verification_status = 'verified' AND invoice_state IN ('not_issued','blocked_config') ORDER BY id LIMIT {$limit}") as $id) {
            $this->issueInvoice($id)['state'] === 'issued' ? $out['invoices_issued']++ : $out['invoices_blocked']++;
        }
        foreach ($this->ids("SELECT id FROM fin_payment_links WHERE status = 'creating' AND updated_at < now() - interval '10 minutes' ORDER BY id LIMIT {$limit}") as $id) {
            try {
                $links->createAtProvider($id, true);
                $out['links_resumed']++;
            } catch (\Throwable $e) {
                $out['errors']++;
            }
        }
        foreach ($this->ids("SELECT id FROM fin_payment_links WHERE status IN ('created','partially_paid') AND expire_at < now() - interval '5 minutes'
                               AND (last_synced_at IS NULL OR last_synced_at < now() - interval '1 hour') ORDER BY id LIMIT {$limit}") as $id) {
            try {
                $this->reconcile($id);
                $out['links_synced']++;
            } catch (\Throwable $e) {
                $this->pdo->prepare('UPDATE fin_payment_links SET last_synced_at = now(), last_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 300), $id]);
                $out['errors']++;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ reads

    /** @return array<string,mixed> */
    public function row(int $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fin_gateway_payments WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Gateway payment not found.');
        }

        return $row;
    }

    public function byProviderId(string $provider, string $providerPaymentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fin_gateway_payments WHERE provider = ? AND provider_payment_id = ?');
        $stmt->execute([$provider, $providerPaymentId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed> */
    private function link(int $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fin_payment_links WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Payment link not found.');
        }

        return $row;
    }

    /** @return list<int> */
    private function ids(string $sql): array
    {
        return array_map('intval', array_column($this->pdo->query($sql)->fetchAll(), 'id'));
    }
}
