<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Services\AuditLog;
use App\Support\AmountInWords;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Gstin;
use App\Support\Ulid;

/**
 * Issues GST tax invoices (contract §1.1, §3). Finance is the only issuer: an invoice exists only for a payment
 * Finance itself has verified — today a four-eyes-verified offline payment (issueForOfflinePayment), from phase B a
 * gateway payment re-read from the gateway (issueForPayment). Engage and Manage cannot ask for one.
 *
 *  - Idempotent: one invoice per payment (UNIQUE fin_invoices.source_ref / payment_id / offline_payment_id); asking
 *    again returns the same invoice.
 *  - Gapless numbering in the issuing transaction (DocumentNumbering); immutable once issued (database triggers).
 *  - No seller profile or no series for the FY → the invoice is not created and the payment is marked
 *    invoice_state = blocked_config (error_log alert, /api/health invoicing.checks.issuance_blocked); the cron
 *    (bin/invoicing-cron.php) retries.
 *  - The printable HTML is stored in the issuing transaction; the PDF is rendered from exactly that HTML right after
 *    commit when GOTENBERG_URL is set (else later by the cron) and stored with its sha256. Reads never re-render.
 */
final class InvoiceService
{
    public const SYSTEM = ['actor' => 'system:invoicing', 'name' => 'Finance invoicing', 'ip' => null];

    /** Configuration problems: the payment waits (blocked_config) and is retried. */
    public const CONFIG_CODES = ['seller_not_configured', 'series_not_configured', 'series_exhausted'];

    /** Buyer kinds (fin_invoices.buyer_kind): a Manage company (cmp_id), or an Aicountly Academy student (no cmp_id, no GSTIN). */
    public const BUYER_COMPANY = 'company';
    public const BUYER_ACADEMY_STUDENT = 'academy_student';

    /**
     * CGST Rule 46(e): an invoice to an unregistered recipient whose taxable value is ₹50,000 or more carries the
     * recipient's name and address (and the state). Below it an Academy student's address is optional (Rule 46(f)).
     */
    public const UNREGISTERED_ADDRESS_THRESHOLD_PAISE = 5_000_000;

    private AuditLog $audit;
    private DocumentQueries $queries;

    /**
     * @param (\Closure():string)|null $today IST calendar date of issue (tests move it across an FY end)
     */
    public function __construct(private \PDO $pdo, private ?\Closure $today = null, private ?GotenbergClient $pdf = null, private bool $pdfFromEnv = true)
    {
        $this->audit   = new AuditLog($pdo);
        $this->queries = new DocumentQueries($pdo);
    }

    public function today(): string
    {
        return $this->today !== null ? ($this->today)() : FiscalYear::today();
    }

    // ------------------------------------------------------------------ entry points

    /**
     * Issue the invoice for a verified offline payment. Runs inside the caller's transaction when there is one (the
     * verification), else in its own. A configuration problem does not fail the caller: the payment is marked
     * blocked_config and the reason returned.
     *
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array{state:string, invoice:?array{id:int, document_id:string, doc_no:string, created:bool}, error:?string, code:?string}
     */
    public function issueForOfflinePayment(int $offlinePaymentId, array $actor): array
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM fin_offline_payments WHERE id = ? FOR UPDATE');
            $stmt->execute([$offlinePaymentId]);
            $p = $stmt->fetch();
            if (!$p) {
                throw ApiException::notFound('Offline payment not found.');
            }
            if ($p['status'] !== 'verified') {
                throw ApiException::coded(409, 'payment_not_verified', 'Only a verified payment is invoiced (four-eyes: record, then verify).');
            }
            $result = $this->issueGuarded([
                'source'       => 'offline_payment',
                'source_id'    => (int) $p['id'],
                'cmp_id'       => (int) $p['cmp_id'],
                'buyer_uuid'   => $p['buyer_uuid'],
                'buyer'        => [
                    'legal_name' => $p['buyer_legal_name'], 'gstin' => $p['buyer_gstin'], 'state_code' => $p['buyer_state_code'],
                    'address' => $p['buyer_address'], 'pincode' => $p['buyer_pincode'], 'country' => $p['buyer_country'], 'email' => $p['buyer_email'],
                ],
                'lines'        => json_decode((string) $p['lines'], true),
                'amount_paise' => (int) $p['amount_paise'],
                'payment'      => ['method' => (string) $p['method'], 'gateway' => null, 'reference' => (string) $p['utr'], 'paid_on' => (string) $p['received_on']],
            ], $actor);
            $this->pdo->prepare('UPDATE fin_offline_payments SET invoice_state = ?, invoice_error = ?, invoice_attempts = invoice_attempts + 1, updated_at = now() WHERE id = ?')
                ->execute([$result['state'], $result['error'], $offlinePaymentId]);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($own && $result['invoice'] !== null) {
            $this->storePdf('tax_invoice', $result['invoice']['id']);
        }

        return $result;
    }

    /**
     * Phase B entry point (not wired yet: nothing in production implements PaymentInvoiceSource): issue the invoice for
     * a gateway payment Finance has verified. Idempotent on payment_id. Configuration problems are thrown with their
     * code (seller_not_configured / series_not_configured) after an ops alert, so the caller can mark the payment
     * invoice_state = blocked_config; incomplete buyer data → 422 blocked_data.
     *
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array{id:int, document_id:string, doc_no:string, created:bool}
     */
    public function issueForPayment(int $paymentId, PaymentInvoiceSource $source, array $actor = self::SYSTEM): array
    {
        $snap = $source->forPayment($paymentId);
        if ($snap === null) {
            throw ApiException::notFound('Payment not found.');
        }
        if (($snap['status'] ?? null) !== 'verified') {
            throw ApiException::coded(409, 'payment_not_verified', 'Only a payment Finance has verified is invoiced.');
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $existing = $this->existing('payment:' . $paymentId);
            if ($existing !== null) {
                $out = $existing;
            } else {
                try {
                    $kind = (string) ($snap['buyer_kind'] ?? self::BUYER_COMPANY);
                    $out = $this->issueInTransaction([
                        'source'       => 'payment',
                        'source_id'    => $paymentId,
                        'buyer_kind'   => $kind,
                        'cmp_id'       => $kind === self::BUYER_ACADEMY_STUDENT ? null : (int) ($snap['cmp_id'] ?? 0),
                        'buyer_uuid'   => $snap['buyer_uuid'] ?? null,
                        'buyer'        => (array) ($snap['buyer'] ?? []),
                        'lines'        => $snap['lines'] ?? [],
                        'amount_paise' => (int) ($snap['amount_paise'] ?? 0),
                        'payment'      => ['method' => 'gateway', 'gateway' => (string) ($snap['gateway'] ?? ''), 'reference' => (string) ($snap['reference'] ?? ''),
                            'paid_on' => (string) ($snap['paid_on'] ?? '')],
                    ], $actor);
                } catch (ApiException $e) {
                    if (in_array($e->errorCode(), self::CONFIG_CODES, true)) {
                        error_log("[finance] invoicing blocked_config for payment {$paymentId}: {$e->getMessage()}");
                    } elseif ($e->errorCode() === 'validation_failed' || $e->errorCode() === 'amount_mismatch') {
                        throw ApiException::coded(422, 'blocked_data', 'The payment\'s order cannot be invoiced as it stands: ' . $e->getMessage(), $e->errors());
                    }
                    throw $e;
                }
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($own && $out['created']) {
            $this->storePdf('tax_invoice', $out['id']);
        }

        return $out;
    }

    /**
     * issueInTransaction() inside a savepoint: a configuration or data problem rolls back only the attempt.
     *
     * @param array<string,mixed> $order
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array{state:string, invoice:?array{id:int, document_id:string, doc_no:string, created:bool}, error:?string, code:?string}
     */
    private function issueGuarded(array $order, array $actor): array
    {
        $this->pdo->exec('SAVEPOINT fin_issue');
        try {
            $inv = $this->issueInTransaction($order, $actor);
            $this->pdo->exec('RELEASE SAVEPOINT fin_issue');

            return ['state' => 'issued', 'invoice' => $inv, 'error' => null, 'code' => null];
        } catch (ApiException $e) {
            $code = $e->errorCode();
            if (in_array($code, self::CONFIG_CODES, true)) {
                $state = 'blocked_config';
            } elseif ($code === 'validation_failed' || $code === 'amount_mismatch') {
                $state = 'blocked_data';
            } else {
                throw $e;
            }
            $this->pdo->exec('ROLLBACK TO SAVEPOINT fin_issue');
            error_log("[finance] invoicing {$state} for {$order['source']} {$order['source_id']}: {$e->getMessage()}");

            return ['state' => $state, 'invoice' => null, 'error' => $e->getMessage(), 'code' => $code];
        }
    }

    // ------------------------------------------------------------------ the issue itself

    /**
     * Create the invoice for one verified payment. Must run inside a transaction.
     *
     * buyer_kind (default company): a company needs its Manage cmp_id; an academy_student has none (null), no GSTIN, only
     * comes from a gateway payment, and needs an address only from the Rule 46(e) value up.
     *
     * @param array{source:string, source_id:int, buyer_kind?:string, cmp_id:?int, buyer_uuid:?string, buyer:array<string,mixed>, lines:mixed,
     *              amount_paise:int, payment:array{method:string, gateway:?string, reference:string, paid_on:string}} $order
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array{id:int, document_id:string, doc_no:string, created:bool}
     */
    public function issueInTransaction(array $order, array $actor): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('issueInTransaction() needs a transaction.');
        }
        $ref = ($order['source'] === 'payment' ? 'payment:' : 'offline:') . $order['source_id'];
        if (($existing = $this->existing($ref)) !== null) {
            return $existing;
        }

        $errors  = [];
        $kind    = $order['buyer_kind'] ?? self::BUYER_COMPANY;
        $student = $kind === self::BUYER_ACADEMY_STUDENT;
        if (!in_array($kind, [self::BUYER_COMPANY, self::BUYER_ACADEMY_STUDENT], true)) {
            $errors['buyer_kind'] = 'company or academy_student.';
        } elseif ($student) {
            if ($order['cmp_id'] !== null) {
                $errors['cmp_id'] = 'An Aicountly Academy student has no Manage company id.';
            }
            if ($order['source'] !== 'payment') {
                $errors['source'] = 'Academy students are invoiced only for gateway payments.';
            }
        } elseif ((int) $order['cmp_id'] <= 0) {
            $errors['cmp_id'] = 'The Manage company id is required.';
        }
        $buyer = InvoiceInput::buyer($order['buyer'], $errors, 'buyer.', !$student);
        if ($student && $buyer['gstin'] !== null) {
            $errors['buyer.gstin'] = 'An Academy student is invoiced as an unregistered individual (no GSTIN).';
        }
        $lines = InvoiceInput::lines($order['lines'], $errors);
        if ($order['payment']['reference'] === '' || !FiscalYear::isDate($order['payment']['paid_on'])) {
            $errors['payment'] = 'The payment reference and date are required.';
        }
        InvoiceInput::throwIf($errors, 'The order cannot be invoiced: some fields are invalid.');

        $seller = $this->sellerRow();
        $pos    = GstCalculator::placeOfSupply($buyer['gstin'], $buyer['state_code']);
        $calc   = InvoiceInput::compute($lines, (string) $seller['state_code'], $pos);
        $total  = $calc['totals']['total'];
        if ($student) {
            if ($calc['totals']['taxable'] >= self::UNREGISTERED_ADDRESS_THRESHOLD_PAISE && ($buyer['address'] === '' || $buyer['pincode'] === '')) {
                InvoiceInput::throwIf(['buyer.address' => 'The student\'s address and PIN are required from a taxable value of ₹50,000 (CGST Rule 46(e)).'],
                    'The order cannot be invoiced: some fields are invalid.');
            }
            if ($buyer['address'] === '') {
                $buyer['address'] = Gstin::stateName($buyer['state_code']) . ', India';
            }
        }
        if ($total !== $order['amount_paise']) {
            throw ApiException::coded(422, 'amount_mismatch', 'The amount paid does not equal the invoice total.',
                ['expected_paise' => $total, 'paid_paise' => $order['amount_paise']]);
        }

        $today = $this->today();
        $num   = (new DocumentNumbering($this->pdo))->next(FiscalYear::forDate($today), 'tax_invoice');

        $stmt = $this->pdo->prepare(
            'INSERT INTO fin_invoices (document_id, buyer_kind, cmp_id, buyer_uuid, series_id, fy, seq_no, doc_no, doc_date, source, source_ref, payment_id,
                 offline_payment_id, seller_profile_id, seller, seller_gstin, seller_state_code, buyer_legal_name, buyer_gstin, buyer_state_code, buyer_address,
                 buyer_pincode, buyer_country, buyer_email, place_of_supply, supply_type, taxable_paise, cgst_paise, sgst_paise, igst_paise, total_paise,
                 amount_in_words, payment_method, payment_gateway, payment_reference, paid_on, issued_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
        );
        $stmt->execute([
            'fin_inv_' . Ulid::generate(), $kind, $student ? null : (int) $order['cmp_id'], $order['buyer_uuid'], $num['series_id'], $num['fy'], $num['seq_no'], $num['doc_no'], $today,
            $order['source'], $ref, $order['source'] === 'payment' ? $order['source_id'] : null, $order['source'] === 'offline_payment' ? $order['source_id'] : null,
            (int) $seller['id'], json_encode(self::sellerSnapshot($seller), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $seller['gstin'], $seller['state_code'], $buyer['legal_name'], $buyer['gstin'], $buyer['state_code'], $buyer['address'], $buyer['pincode'] ?: null,
            $buyer['country'], $buyer['email'], $pos, GstCalculator::supplyType((string) $seller['state_code'], $pos),
            $calc['totals']['taxable'], $calc['totals']['cgst'], $calc['totals']['sgst'], $calc['totals']['igst'], $total, AmountInWords::inr($total),
            $order['payment']['method'], $order['payment']['gateway'] ?: null, $order['payment']['reference'], $order['payment']['paid_on'], $actor['actor'],
        ]);
        $id = (int) $stmt->fetchColumn();

        $ins = $this->pdo->prepare(
            'INSERT INTO fin_invoice_lines (invoice_id, line_no, item_code, item_kind, description, sac, gst_rate, price_basis, period_start, period_end, quantity,
                 amount_paise, taxable_paise, cgst_paise, sgst_paise, igst_paise, total_paise)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($calc['lines'] as $i => $l) {
            $ins->execute([$id, $i + 1, $l['item_code'], $l['item_kind'], $l['description'], $l['sac'], $l['gst_rate'], $l['price_basis'], $l['period_start'],
                $l['period_end'], $l['quantity'], $l['amount_paise'], $l['taxable'], $l['cgst'], $l['sgst'], $l['igst'], $l['total']]);
        }

        $doc = $this->queries->invoice($id);
        $this->storeHtml('invoice_id', $id, DocumentHtml::render($doc));
        $this->audit->record($actor, 'invoice.issued', 'invoice', $id, null, [
            'document_id' => $doc['document_id'], 'doc_no' => $doc['doc_no'], 'doc_date' => $doc['doc_date'], 'cmp_id' => $doc['cmp_id'],
            'buyer_kind' => $kind, 'source_ref' => $ref, 'total_paise' => $total, 'place_of_supply' => $pos, 'supply_type' => $doc['supply_type'],
        ]);

        return ['id' => $id, 'document_id' => $doc['document_id'], 'doc_no' => (string) $doc['doc_no'], 'created' => true];
    }

    /** @return array{id:int, document_id:string, doc_no:string, created:bool}|null */
    private function existing(string $sourceRef): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, document_id, doc_no FROM fin_invoices WHERE source_ref = ?');
        $stmt->execute([$sourceRef]);
        $row = $stmt->fetch();

        return $row ? ['id' => (int) $row['id'], 'document_id' => (string) $row['document_id'], 'doc_no' => (string) $row['doc_no'], 'created' => false] : null;
    }

    /** @return array<string,mixed> the active seller profile row, or 422 seller_not_configured */
    public function sellerRow(): array
    {
        $row = $this->pdo->query('SELECT * FROM fin_seller_profile WHERE active ORDER BY id DESC LIMIT 1')->fetch();
        if (!$row) {
            throw ApiException::coded(422, 'seller_not_configured',
                'The seller profile (legal name, GSTIN, address, signatory) is not set up yet (Settings → Invoicing).');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed> what every document prints of the seller
     */
    public static function sellerSnapshot(array $row): array
    {
        $p = SellerProfileService::publicProfile($row);

        return array_intersect_key($p, array_flip(['legal_name', 'trade_name', 'gstin', 'pan', 'address_line1', 'address_line2', 'city', 'pincode',
            'state_code', 'state_name', 'email', 'phone', 'signatory_name', 'signatory_designation']));
    }

    public function storeHtml(string $column, int $id, string $html): void
    {
        $this->pdo->prepare("INSERT INTO fin_doc_pdfs ({$column}, html, html_sha256) VALUES (?, ?, ?)")
            ->execute([$id, $html, hash('sha256', $html)]);
    }

    // ------------------------------------------------------------------ PDF (after commit)

    /**
     * Render the stored HTML of one document to PDF and store it (once). No-op when already stored.
     *
     * @return array{stored:bool, error:?string}
     */
    public function storePdf(string $docType, int $id): array
    {
        $client = $this->pdf ?? ($this->pdfFromEnv ? GotenbergClient::fromEnv() : null);
        $col    = $docType === 'credit_note' ? 'credit_note_id' : 'invoice_id';
        $stmt   = $this->pdo->prepare("SELECT p.id, p.html, p.pdf IS NOT NULL AS done FROM fin_doc_pdfs p WHERE p.{$col} = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['stored' => false, 'error' => 'No stored document.'];
        }
        if ($row['done']) {
            return ['stored' => true, 'error' => null];
        }
        if ($client === null) {
            return ['stored' => false, 'error' => 'No PDF renderer is configured (GOTENBERG_URL).'];
        }
        $table = $docType === 'credit_note' ? 'fin_credit_notes' : 'fin_invoices';
        $no    = $this->pdo->prepare("SELECT doc_no FROM {$table} WHERE id = ?");
        $no->execute([$id]);
        $filename = self::pdfFilename((string) $no->fetchColumn());

        $res = $client->render((string) $row['html'], $filename);
        if (!$res['ok']) {
            $this->pdo->prepare('UPDATE fin_doc_pdfs SET render_attempts = render_attempts + 1, last_error = ? WHERE id = ? AND pdf IS NULL')
                ->execute([$res['error'], (int) $row['id']]);
            error_log("[finance] PDF for {$docType} {$id} not rendered yet: {$res['error']}");

            return ['stored' => false, 'error' => $res['error']];
        }
        $sha = hash('sha256', $res['body']);
        $upd = $this->pdo->prepare("UPDATE fin_doc_pdfs SET pdf = :pdf, pdf_sha256 = :sha, renderer = 'gotenberg', rendered_at = now(),
                                        render_attempts = render_attempts + 1, last_error = NULL WHERE id = :id AND pdf IS NULL");
        $body = $res['body'];
        $upd->bindParam(':pdf', $body, \PDO::PARAM_LOB);
        $upd->bindValue(':sha', $sha);
        $upd->bindValue(':id', (int) $row['id'], \PDO::PARAM_INT);
        $upd->execute();
        $this->audit->record(self::SYSTEM, 'document.pdf_stored', $docType === 'credit_note' ? 'credit_note' : 'invoice', $id, null,
            ['pdf_sha256' => $sha, 'bytes' => strlen($body), 'renderer' => 'gotenberg']);

        return ['stored' => true, 'error' => null];
    }

    /** "AIC/26-27/000123" → "AIC-26-27-000123.pdf" (contract §1.4). */
    public static function pdfFilename(string $docNo): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '-', $docNo) . '.pdf';
    }

    /**
     * Cron: render documents whose PDF is still missing.
     *
     * @return array{stored:int, failed:int}
     */
    public function renderPending(int $limit = 25): array
    {
        $rows = $this->pdo->query("SELECT invoice_id, credit_note_id FROM fin_doc_pdfs WHERE pdf IS NULL AND render_attempts < 100 ORDER BY id LIMIT {$limit}")->fetchAll();
        $out  = ['stored' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $res = $r['invoice_id'] !== null ? $this->storePdf('tax_invoice', (int) $r['invoice_id']) : $this->storePdf('credit_note', (int) $r['credit_note_id']);
            $out[$res['stored'] ? 'stored' : 'failed']++;
        }

        return $out;
    }

    /**
     * Cron: retry verified offline payments waiting on configuration.
     *
     * @return array{issued:int, blocked:int}
     */
    public function retryBlocked(int $limit = 50): array
    {
        $ids = array_map('intval', array_column($this->pdo->query(
            "SELECT id FROM fin_offline_payments WHERE status = 'verified' AND invoice_state = 'blocked_config' ORDER BY id LIMIT {$limit}",
        )->fetchAll(), 'id'));
        $out = ['issued' => 0, 'blocked' => 0];
        foreach ($ids as $id) {
            $res = $this->issueForOfflinePayment($id, self::SYSTEM);
            $out[$res['state'] === 'issued' ? 'issued' : 'blocked']++;
        }

        return $out;
    }
}
