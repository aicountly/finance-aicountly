<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Services\AuditLog;
use App\Services\Invoicing\InvoiceService;
use App\Support\ApiException;
use App\Support\Gstin;
use App\Support\Ulid;

/**
 * Aicountly Academy fee payment links (table fin_payment_links): Engage asks for one server to server, or staff raise
 * one in Finance; Finance creates it at the provider (Razorpay / Cashfree) on Aicountly's own merchant account.
 *
 *   Engage   createFromEngage(): Idempotency-Key required. Same key + same body → the same link (200); same key + another
 *            body → 409 idempotency_conflict; a failed provider call is retried by sending the same request again.
 *   Staff    createByStaff() (maker, gate edit) → pending_approval → approve() by someone else (gate approve, four-eyes,
 *            also a database constraint) → created at the provider; or reject() with a reason.
 *   Both     one open and one paid link per academy_ref (409 open_link_exists / already_paid); cancel() at the provider;
 *            refresh() re-reads the provider (GatewayPaymentService::reconcile).
 *
 * The provider call happens after the row is committed as 'creating'; a crash in between is resumed by the cron, which
 * first looks the link up by our id at the provider so it is never created twice.
 */
final class PaymentLinkService
{
    private AuditLog $audit;
    private GatewayPaymentService $payments;

    /** @param (\Closure():int)|null $now unix time (tests) */
    public function __construct(private \PDO $pdo, private GatewayConnectionService $connections, ?GatewayPaymentService $payments = null,
        private ?\Closure $now = null, private ?InvoiceService $invoices = null)
    {
        $this->audit    = new AuditLog($pdo);
        $this->payments = $payments ?? new GatewayPaymentService($pdo, $connections, $invoices);
    }

    private function now(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }

    private function sellerState(): string
    {
        return (string) ($this->invoices ?? new InvoiceService($this->pdo))->sellerRow()['state_code'];
    }

    // ------------------------------------------------------------------ Engage (AIC-S2S)

    /**
     * @param array<string,mixed> $body
     * @param array{actor:string, name?:?string, ip?:?string} $actor service:engage
     * @return array{link:array<string,mixed>, created:bool}
     */
    public function createFromEngage(array $body, string $idempotencyKey, array $actor): array
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $idempotencyKey)) {
            throw ApiException::coded(400, 'idempotency_key_required', 'Send an Idempotency-Key header (8–100 letters, digits or _ . : -), unique per fee demand.');
        }
        $hash = hash('sha256', json_encode(self::sortKeys($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $existing = $this->byIdempotency('engage', $idempotencyKey);
        if ($existing !== null) {
            return ['link' => $this->resumeIfNeeded($existing, $hash), 'created' => false];
        }
        $v    = PaymentLinkInput::validate($body, $this->sellerState(), true, $this->now());
        $conn = $this->connections->resolve($v['provider']);
        try {
            $id = $this->insert($v, $conn, 'engage', $idempotencyKey, $hash, 'creating', $actor);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'fin_payment_links_source_app_idempotency_key_key')) {
                $again = $this->byIdempotency('engage', $idempotencyKey);
                if ($again !== null) {
                    return ['link' => $this->resumeIfNeeded($again, $hash), 'created' => false];
                }
            }
            throw $e;
        }

        return ['link' => $this->createAtProvider($id), 'created' => true];
    }

    /** Same request again: return it, or retry a failed / stalled provider call. */
    private function resumeIfNeeded(array $row, string $hash): array
    {
        if (!hash_equals((string) $row['request_sha256'], $hash)) {
            throw ApiException::coded(409, 'idempotency_conflict', 'This Idempotency-Key was already used for a different payment link request.',
                ['link_id' => $row['link_id']]);
        }
        $stalled = $row['status'] === 'creating' && strtotime((string) $row['updated_at']) < $this->now() - 120;
        if ($row['status'] === 'failed' || $stalled) {
            return $this->createAtProvider((int) $row['id'], true);
        }

        return $row;
    }

    // ------------------------------------------------------------------ staff (maker / checker)

    /** GST split and amount payable for the staff form (nothing stored). */
    public function preview(array $body): array
    {
        $v = PaymentLinkInput::validate($body, $this->sellerState(), false, $this->now());

        return ['amount_paise' => $v['amount_paise'], 'fee' => $v['fee'], 'place_of_supply' => $v['place_of_supply'], 'place_of_supply_name' => Gstin::stateName($v['place_of_supply']),
            'seller_state' => $v['seller_state'], 'supply_type' => $v['supply_type'], 'expire_at' => date(DATE_ATOM, $v['expire_at'])];
    }

    /** @return array<string,mixed> the link row (pending_approval) */
    public function createByStaff(array $body, array $actor): array
    {
        $v    = PaymentLinkInput::validate($body, $this->sellerState(), false, $this->now());
        $conn = $this->connections->resolve($v['provider']);
        $id   = $this->insert($v, $conn, 'finance', null, null, 'pending_approval', $actor);

        return $this->row($id);
    }

    public function approve(int $id, array $actor): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->row($id, true);
            if ($row['status'] !== 'pending_approval') {
                throw ApiException::conflict('Only a link awaiting approval can be approved (this one is ' . $row['status'] . ').');
            }
            if (strcasecmp((string) $row['created_by'], $actor['actor']) === 0) {
                throw ApiException::forbidden('Four-eyes: someone other than the person who raised the link must approve it.');
            }
            if ((int) $row['expire_at_epoch'] < $this->now() + PaymentLinkInput::MIN_EXPIRY) {
                throw ApiException::conflict('The link\'s expiry has (nearly) passed: reject it and raise a new one.');
            }
            $this->pdo->prepare("UPDATE fin_payment_links SET status = 'creating', approved_by = ?, approved_at = now(), updated_at = now() WHERE id = ?")
                ->execute([$actor['actor'], $id]);
            $this->audit->record($actor, 'payment_link.approved', 'payment_link', $id, ['status' => 'pending_approval'], ['status' => 'creating', 'link_id' => $row['link_id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->createAtProvider($id);
    }

    public function reject(int $id, string $reason, array $actor): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApiException::validation('Say why the link is rejected.', ['reason' => 'Required.']);
        }

        return $this->tx(function () use ($id, $reason, $actor): array {
            $row = $this->row($id, true);
            if ($row['status'] !== 'pending_approval') {
                throw ApiException::conflict('Only a link awaiting approval can be rejected.');
            }
            $this->pdo->prepare("UPDATE fin_payment_links SET status = 'rejected', rejected_by = ?, rejected_at = now(), reject_reason = ?, updated_at = now() WHERE id = ?")
                ->execute([$actor['actor'], mb_substr($reason, 0, 500), $id]);
            $this->audit->record($actor, 'payment_link.rejected', 'payment_link', $id, ['status' => 'pending_approval'], ['status' => 'rejected', 'reason' => $reason]);

            return $this->row($id);
        });
    }

    /** Retry a failed provider call (staff, gate approve). */
    public function retry(int $id, array $actor): array
    {
        $row = $this->row($id);
        if ($row['status'] !== 'failed') {
            throw ApiException::conflict('Only a link whose creation failed can be retried.');
        }
        $this->audit->record($actor, 'payment_link.retried', 'payment_link', $id, null, ['link_id' => $row['link_id']]);

        return $this->createAtProvider($id, true);
    }

    /**
     * Cancel a link: at the provider when it exists there, locally otherwise. A link the provider reports as paid
     * cannot be cancelled (409 already_paid, after recording the payment).
     */
    public function cancel(int $id, ?string $reason, array $actor): array
    {
        $row = $this->row($id);
        if (!in_array($row['status'], ['created', 'partially_paid', 'failed', 'pending_approval'], true)) {
            throw ApiException::coded(409, $row['status'] === 'paid' ? 'already_paid' : 'not_cancellable', 'A ' . $row['status'] . ' link cannot be cancelled.');
        }
        $providerId = $row['provider_link_id'];
        if ($providerId === null && $row['status'] === 'failed') {
            // A failed create may still have reached the provider (timeout): cancel it there too.
            try {
                $found      = $this->connections->adapter($this->connections->row((int) $row['connection_id']))->findLink((string) $row['link_id']);
                $providerId = $found['provider_link_id'] ?? null;
            } catch (GatewayException $e) {
                throw ApiException::coded(502, 'provider_error', 'Could not check whether the provider holds this link: ' . $e->getMessage(), ['retryable' => true]);
            }
        }
        if ($providerId !== null) {
            try {
                $data = $this->connections->adapter($this->connections->row((int) $row['connection_id']))
                    ->cancelLink((string) $providerId, (string) $row['link_id']);
            } catch (GatewayException $e) {
                // Paid in the meantime? Re-read before giving up.
                try {
                    $sync = $this->payments->reconcile($id);
                } catch (\Throwable) {
                    $sync = null;
                }
                if (($sync['status'] ?? null) === 'paid') {
                    throw ApiException::coded(409, 'already_paid', 'The student has already paid this link.');
                }
                throw ApiException::coded(502, 'provider_error', $e->getMessage(), ['retryable' => $e->retryable()]);
            }
            if ($data['status'] === 'paid') {
                $this->payments->reconcile($id);
                throw ApiException::coded(409, 'already_paid', 'The student has already paid this link.');
            }
        }

        return $this->tx(function () use ($id, $reason, $actor): array {
            $row = $this->row($id, true);
            if ($row['status'] === 'paid') {
                throw ApiException::coded(409, 'already_paid', 'The student has already paid this link.');
            }
            $this->pdo->prepare("UPDATE fin_payment_links SET status = 'cancelled', cancelled_by = ?, cancelled_at = now(), cancel_reason = ?,
                                        provider_status = CASE WHEN provider_link_id IS NULL THEN provider_status ELSE 'cancelled' END, updated_at = now() WHERE id = ?")
                ->execute([$actor['actor'], $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500), $id]);
            $this->audit->record($actor, 'payment_link.cancelled', 'payment_link', $id, ['status' => $row['status']], ['status' => 'cancelled', 'reason' => $reason]);

            return $this->row($id);
        });
    }

    /** Re-read the link (and its payments) from the provider. */
    public function refresh(int $id, array $actor): array
    {
        $row = $this->row($id);
        if ($row['provider_link_id'] === null) {
            throw ApiException::conflict('This link does not exist at the provider yet.');
        }
        try {
            $sync = $this->payments->reconcile($id);
        } catch (GatewayException $e) {
            throw ApiException::coded(502, 'provider_error', $e->getMessage(), ['retryable' => $e->retryable()]);
        }
        $this->audit->record($actor, 'payment_link.refreshed', 'payment_link', $id, null, $sync);

        return $this->row($id);
    }

    // ------------------------------------------------------------------ provider creation

    /**
     * Create the link at the provider (status creating / failed → created). $resume: look it up by our id first (a
     * previous attempt may have reached the provider without Finance hearing the answer).
     */
    public function createAtProvider(int $id, bool $resume = false): array
    {
        $this->tx(function () use ($id): void {
            $row = $this->row($id, true);
            if (!in_array($row['status'], ['creating', 'failed'], true)) {
                throw ApiException::conflict('This link is ' . $row['status'] . ', not waiting to be created.');
            }
            $this->pdo->prepare("UPDATE fin_payment_links SET status = 'creating', create_attempts = create_attempts + 1, updated_at = now() WHERE id = ?")->execute([$id]);
        });
        $row     = $this->row($id);
        $adapter = $this->connections->adapter($this->connections->row((int) $row['connection_id']));
        try {
            $data = $resume ? $adapter->findLink((string) $row['link_id']) : null;
            $data ??= $adapter->createLink([
                'link_id'      => (string) $row['link_id'],
                'amount_paise' => (int) $row['amount_paise'],
                'description'  => (string) $row['description'],
                'payer'        => ['name' => (string) $row['payer_name'], 'email' => $row['payer_email'], 'mobile' => (string) $row['payer_mobile']],
                'expire_at'    => (int) $row['expire_at_epoch'],
                'notify_sms'   => (bool) $row['notify_sms'],
                'notify_email' => (bool) $row['notify_email'],
                'notes'        => array_filter(['fin_link_id' => (string) $row['link_id'], 'academy_ref' => (string) $row['academy_ref'], 'stream' => (string) $row['stream'],
                    'source_ref' => (string) ($row['source_ref'] ?? '')], static fn (string $v) => $v !== ''),
                'return_url'   => self::httpsUrl(finance_env('FINANCE_ACADEMY_RETURN_URL')),
                'notify_url'   => $row['provider'] === 'cashfree' && ($b = self::httpsUrl(finance_env('BASE_URL'))) !== null ? rtrim($b, '/') . '/v1/webhooks/cashfree' : null,
            ]);
        } catch (GatewayException $e) {
            $this->pdo->prepare("UPDATE fin_payment_links SET status = 'failed', last_error = ?, updated_at = now() WHERE id = ?")->execute([$e->getMessage(), $id]);
            $this->audit->record(GatewayPaymentService::SYSTEM, 'payment_link.provider_failed', 'payment_link', $id, null,
                ['provider' => $row['provider'], 'http_status' => $e->httpStatus(), 'error' => $e->getMessage()]);
            throw ApiException::coded(502, 'provider_error', $e->getMessage() . ' Send the same request again to retry.',
                ['link_id' => $row['link_id'], 'status' => 'failed', 'retryable' => $e->retryable()]);
        }
        if ($data['provider_link_id'] === '' || $data['url'] === '') {
            $this->pdo->prepare("UPDATE fin_payment_links SET status = 'failed', last_error = ?, updated_at = now() WHERE id = ?")
                ->execute(['The provider answered without a link id or URL.', $id]);
            throw ApiException::coded(502, 'provider_error', 'The provider answered without a link id or URL.', ['link_id' => $row['link_id'], 'status' => 'failed', 'retryable' => true]);
        }
        $status = in_array($data['status'], ['created', 'partially_paid', 'expired', 'cancelled'], true) ? $data['status'] : 'created';
        $this->pdo->prepare('UPDATE fin_payment_links SET status = ?, provider_link_id = ?, link_url = ?, provider_status = ?, last_error = NULL, last_synced_at = now(),
                                    updated_at = now() WHERE id = ?')
            ->execute([$status, $data['provider_link_id'], $data['url'], $data['provider_status'] ?: null, $id]);
        $this->audit->record(GatewayPaymentService::SYSTEM, 'payment_link.created', 'payment_link', $id, ['status' => 'creating'],
            ['status' => $status, 'link_id' => $row['link_id'], 'provider' => $row['provider'], 'mode' => $row['mode'], 'provider_link_id' => $data['provider_link_id']]);
        if ($data['status'] === 'paid') {
            $this->payments->reconcile($id);
        }

        return $this->row($id);
    }

    // ------------------------------------------------------------------ storage

    /** @param array<string,mixed> $v PaymentLinkInput::validate() */
    private function insert(array $v, array $conn, string $sourceApp, ?string $idempotencyKey, ?string $hash, string $status, array $actor): int
    {
        return $this->tx(function () use ($v, $conn, $sourceApp, $idempotencyKey, $hash, $status, $actor): int {
            $clash = $this->pdo->prepare("SELECT link_id, status FROM fin_payment_links WHERE academy_ref = ? AND status IN ('pending_approval','creating','created','partially_paid','paid')
                                           ORDER BY (status = 'paid') DESC LIMIT 1");
            $clash->execute([$v['academy_ref']]);
            if ($c = $clash->fetch()) {
                if ($c['status'] === 'paid') {
                    throw ApiException::coded(409, 'already_paid', "The fee {$v['academy_ref']} is already paid.", ['link_id' => $c['link_id']]);
                }
                throw ApiException::coded(409, 'open_link_exists', "A payment link for {$v['academy_ref']} is already open: cancel it first or use it.",
                    ['link_id' => $c['link_id'], 'status' => $c['status']]);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO fin_payment_links (link_id, source_app, idempotency_key, request_sha256, source_ref, academy_ref, stream, product_code, seller_code, description,
                     payer_name, payer_email, payer_mobile, payer_state_code, payer_address, payer_pincode, amount_paise, fee_taxable_paise, fee_cgst_paise,
                     fee_sgst_paise, fee_igst_paise, fee_sac, fee_gst_rate, fee_price_basis, fee_breakdown, seller_state_code, place_of_supply, supply_type,
                     connection_id, provider, mode, expire_at, notify_sms, notify_email, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, to_timestamp(?), ?, ?, ?, ?) RETURNING id',
            );
            $f = $v['fee'];
            $stmt->execute([
                'fin_pl_' . Ulid::generate(), $sourceApp, $idempotencyKey, $hash, $v['source_ref'], $v['academy_ref'], $v['stream'], $v['product_code'], $v['seller_code'],
                $v['description'], $v['payer']['name'], $v['payer']['email'], $v['payer']['mobile'], $v['payer']['state_code'], $v['payer']['address'], $v['payer']['pincode'],
                $v['amount_paise'], $f['taxable_paise'], $f['cgst_paise'], $f['sgst_paise'], $f['igst_paise'], $f['sac'], $f['gst_rate'], $f['price_basis'],
                json_encode($v['fee_breakdown'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $v['seller_state'], $v['place_of_supply'],
                $v['supply_type'], (int) $conn['id'], $conn['provider'], $conn['mode'], $v['expire_at'], $v['notify_sms'] ? 'true' : 'false',
                $v['notify_email'] ? 'true' : 'false', $status, $actor['actor'],
            ]);
            $id  = (int) $stmt->fetchColumn();
            $row = $this->row($id);
            $this->audit->record($actor, $sourceApp === 'engage' ? 'payment_link.requested' : 'payment_link.raised', 'payment_link', $id, null, [
                'link_id' => $row['link_id'], 'source_app' => $sourceApp, 'source_ref' => $row['source_ref'], 'academy_ref' => $row['academy_ref'], 'stream' => $row['stream'],
                'amount_paise' => (int) $row['amount_paise'], 'provider' => $row['provider'], 'mode' => $row['mode'], 'status' => $status,
            ]);

            return $id;
        });
    }

    /** @return array<string,mixed> */
    public function row(int $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT *, extract(epoch FROM expire_at)::bigint AS expire_at_epoch FROM fin_payment_links WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Payment link not found.');
        }

        return $row;
    }

    public function byLinkId(string $linkId): ?array
    {
        if (!preg_match('/^fin_pl_[0-9A-HJKMNP-TV-Z]{26}$/', $linkId)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM fin_payment_links WHERE link_id = ?');
        $stmt->execute([$linkId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->row((int) $id);
    }

    private function byIdempotency(string $sourceApp, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM fin_payment_links WHERE source_app = ? AND idempotency_key = ?');
        $stmt->execute([$sourceApp, $key]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->row((int) $id);
    }

    // ------------------------------------------------------------------ views

    /** What Engage sees (GET / create / cancel answers). */
    public function engageView(array $row): array
    {
        return [
            'link_id'           => (string) $row['link_id'],
            'status'            => (string) $row['status'],
            'provider'          => (string) $row['provider'],
            'mode'              => (string) $row['mode'],
            'url'               => $row['link_url'],
            'amount_paise'      => (int) $row['amount_paise'],
            'amount_paid_paise' => (int) $row['amount_paid_paise'],
            'currency'          => 'INR',
            'expire_at'         => self::iso($row['expire_at']),
            'source_ref'        => $row['source_ref'],
            'academy_ref'       => (string) $row['academy_ref'],
            'stream'            => (string) $row['stream'],
            'product_code'      => (string) $row['product_code'],
            'seller_code'       => $row['seller_code'],
            'fee'               => self::fee($row),
            'created_at'        => self::iso($row['created_at']),
            'paid_at'           => self::iso($row['paid_at']),
            'cancelled_at'      => self::iso($row['cancelled_at']),
            'last_error'        => $row['status'] === 'failed' ? $row['last_error'] : null,
            'payments'          => array_map(static fn (array $p) => [
                'payment_ref' => $p['gateway_payment_ref'], 'finance_payment_id' => $p['payment_ref'], 'provider_payment_id' => $p['provider_payment_id'],
                'amount_paise' => $p['amount_paise'], 'method' => $p['method'],
                'paid_at' => $p['paid_at'], 'verification_status' => $p['verification_status'], 'refunded_paise' => $p['refunded_paise'],
                'invoice' => $p['invoice'] === null ? null : ['document_id' => $p['invoice']['document_id'], 'doc_no' => $p['invoice']['doc_no']],
            ], $this->paymentsOf((int) $row['id'])),
        ];
    }

    /** Staff detail / list row. */
    public function staffView(array $row, array $roles = [], ?string $actor = null): array
    {
        $out = $this->engageView($row) + [
            'id'              => (int) $row['id'],
            'source_app'      => (string) $row['source_app'],
            'description'     => (string) $row['description'],
            'payer'           => ['name' => (string) $row['payer_name'], 'email' => $row['payer_email'], 'mobile' => (string) $row['payer_mobile'],
                'state_code' => (string) $row['payer_state_code'], 'state_name' => Gstin::stateName((string) $row['payer_state_code']),
                'address' => $row['payer_address'], 'pincode' => $row['payer_pincode']],
            'place_of_supply' => (string) $row['place_of_supply'],
            'supply_type'     => (string) $row['supply_type'],
            'provider_link_id' => $row['provider_link_id'],
            'provider_status' => $row['provider_status'],
            'created_by'      => (string) $row['created_by'],
            'approved_by'     => $row['approved_by'],
            'approved_at'     => self::iso($row['approved_at']),
            'rejected_by'     => $row['rejected_by'],
            'reject_reason'   => $row['reject_reason'],
            'cancelled_by'    => $row['cancelled_by'],
            'cancel_reason'   => $row['cancel_reason'],
            'last_synced_at'  => self::iso($row['last_synced_at']),
            'last_error'      => $row['last_error'],
            'create_attempts' => (int) $row['create_attempts'],
        ];
        $out['payments'] = $this->paymentsOf((int) $row['id']);
        $maker = $actor !== null && strcasecmp((string) $row['created_by'], $actor) === 0;
        $approve = \App\Services\FinanceAccessPolicy::allows($roles, 'approve');
        $out['can'] = [
            'approve'        => $approve && $row['status'] === 'pending_approval' && !$maker,
            'reject'         => $approve && $row['status'] === 'pending_approval',
            'cancel'         => $approve && in_array($row['status'], ['created', 'partially_paid', 'failed', 'pending_approval'], true),
            'retry'          => $approve && $row['status'] === 'failed',
            'refresh'        => \App\Services\FinanceAccessPolicy::allows($roles, 'edit') && $row['provider_link_id'] !== null,
            'blocked_reason' => $row['status'] === 'pending_approval' && $maker ? 'You raised this link: someone else must approve it.' : null,
        ];

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function paymentsOf(int $linkId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, i.id AS invoice_id, i.document_id AS invoice_document_id, i.doc_no AS invoice_doc_no
               FROM fin_gateway_payments p LEFT JOIN fin_invoices i ON i.source_ref = 'payment:' || p.id
              WHERE p.link_id = ? ORDER BY p.id",
        );
        $stmt->execute([$linkId]);

        return array_map([self::class, 'paymentView'], $stmt->fetchAll());
    }

    public static function paymentView(array $p): array
    {
        return [
            'id'                    => (int) $p['id'],
            'payment_ref'           => (string) $p['payment_ref'],
            'provider'              => (string) $p['provider'],
            'mode'                  => (string) $p['mode'],
            'provider_payment_id'   => (string) $p['provider_payment_id'],
            'gateway_payment_ref'   => $p['gateway_payment_ref'],
            'provider_txn_ref'      => $p['provider_txn_ref'],
            'amount_paise'          => (int) $p['amount_paise'],
            'method'                => $p['method'],
            'paid_at'               => self::iso($p['paid_at']),
            'verification_status'   => (string) $p['verification_status'],
            'verification_error'    => $p['verification_error'],
            'verified_at'           => self::iso($p['verified_at']),
            'refunded_paise'        => (int) $p['refunded_paise'],
            'invoice_state'         => (string) $p['invoice_state'],
            'invoice_error'         => $p['invoice_error'],
            'invoice'               => isset($p['invoice_id']) && $p['invoice_id'] !== null
                ? ['id' => (int) $p['invoice_id'], 'document_id' => (string) $p['invoice_document_id'], 'doc_no' => (string) $p['invoice_doc_no']] : null,
            'created_at'            => self::iso($p['created_at']),
        ];
    }

    /**
     * Staff list.
     *
     * @param array{status?:?string, stream?:?string, q?:?string} $f
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, counts:array<string,int>}
     */
    public function list(array $f, int $page = 1, int $perPage = 50): array
    {
        $where  = [];
        $params = [];
        if (!empty($f['status'])) {
            $where[]  = 'l.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['stream'])) {
            $where[]  = 'l.stream = ?';
            $params[] = $f['stream'];
        }
        if (!empty($f['q'])) {
            $like     = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
            $where[]  = '(l.link_id ILIKE ? OR l.academy_ref ILIKE ? OR l.source_ref ILIKE ? OR l.payer_name ILIKE ? OR l.payer_mobile ILIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql     = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $count   = $this->pdo->prepare("SELECT count(*) FROM fin_payment_links l {$sql}");
        $count->execute($params);
        $stmt = $this->pdo->prepare("SELECT l.*, extract(epoch FROM l.expire_at)::bigint AS expire_at_epoch FROM fin_payment_links l {$sql}
                                      ORDER BY (l.status = 'pending_approval') DESC, l.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($params);
        $counts = [];
        foreach ($this->pdo->query('SELECT status, count(*) AS n FROM fin_payment_links GROUP BY status')->fetchAll() as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $rows = array_map(fn (array $r) => [
            'id' => (int) $r['id'], 'link_id' => (string) $r['link_id'], 'status' => (string) $r['status'], 'source_app' => (string) $r['source_app'],
            'source_ref' => $r['source_ref'], 'academy_ref' => (string) $r['academy_ref'], 'stream' => (string) $r['stream'], 'payer_name' => (string) $r['payer_name'],
            'description' => (string) $r['description'], 'amount_paise' => (int) $r['amount_paise'], 'amount_paid_paise' => (int) $r['amount_paid_paise'],
            'provider' => (string) $r['provider'], 'mode' => (string) $r['mode'], 'url' => $r['link_url'], 'expire_at' => self::iso($r['expire_at']),
            'created_by' => (string) $r['created_by'], 'created_at' => self::iso($r['created_at']), 'paid_at' => self::iso($r['paid_at']),
        ], $stmt->fetchAll());

        return ['rows' => $rows, 'total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => $perPage, 'counts' => $counts];
    }

    // ------------------------------------------------------------------ helpers

    public static function fee(array $row): array
    {
        return ['taxable_paise' => (int) $row['fee_taxable_paise'], 'cgst_paise' => (int) $row['fee_cgst_paise'], 'sgst_paise' => (int) $row['fee_sgst_paise'],
            'igst_paise' => (int) $row['fee_igst_paise'], 'sac' => (string) $row['fee_sac'], 'gst_rate' => (string) $row['fee_gst_rate'],
            'price_basis' => (string) $row['fee_price_basis']];
    }

    public static function iso(mixed $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }

        return (new \DateTimeImmutable((string) $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format(\DateTimeInterface::ATOM);
    }

    private static function httpsUrl(string $v): ?string
    {
        return preg_match('#^https://[^\s]+$#', $v) ? $v : null;
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (!array_is_list($v)) {
            ksort($v);
        }

        return array_map([self::class, 'sortKeys'], $v);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $out = $fn();
            if ($own) {
                $this->pdo->commit();
            }

            return $out;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
