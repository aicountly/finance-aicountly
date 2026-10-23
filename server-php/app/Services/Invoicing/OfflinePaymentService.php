<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Services\AuditLog;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Money;

/**
 * Offline payments (bank transfer, UPI, cheque) recorded by Finance staff (contract §3.5), four-eyes:
 *
 *   record (maker, edit gate)  →  verify (checker, approve gate, a different person)  →  tax invoice issued
 *                              ↘  reject (approve gate, with a reason; the UTR can then be recorded again)
 *
 * What is recorded never changes (database trigger). The amount received must equal the invoice total computed
 * from the lines (GST added for exclusive prices); short or excess payments are not invoiced here.
 */
final class OfflinePaymentService
{
    public const METHODS = ['neft' => 'NEFT', 'rtgs' => 'RTGS', 'imps' => 'IMPS', 'upi' => 'UPI', 'cheque' => 'Cheque'];

    private AuditLog $audit;

    public function __construct(private \PDO $pdo, private ?InvoiceService $invoices = null)
    {
        $this->audit    = new AuditLog($pdo);
        $this->invoices ??= new InvoiceService($pdo);
    }

    /**
     * Tax computed for a draft (nothing stored): what the invoice would show.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(array $input): array
    {
        $errors = [];
        $buyer  = InvoiceInput::buyer(is_array($input['buyer'] ?? null) ? $input['buyer'] : [], $errors);
        $lines  = InvoiceInput::lines($input['lines'] ?? null, $errors);
        InvoiceInput::throwIf($errors);

        return $this->computeFor($buyer, $lines);
    }

    /**
     * @param array<string,mixed> $input
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function record(array $input, array $actor): array
    {
        $errors = [];
        $cmpId  = $input['cmp_id'] ?? null;
        $cmpId  = is_int($cmpId) ? $cmpId : (is_string($cmpId) && ctype_digit(trim($cmpId)) ? (int) trim($cmpId) : 0);
        if ($cmpId <= 0) {
            $errors['cmp_id'] = 'The Manage company id (cmp_id) the payment is for.';
        }
        $uuid = is_string($input['buyer_uuid'] ?? null) ? trim($input['buyer_uuid']) : '';
        if ($uuid !== '' && !preg_match('/^[A-Za-z0-9-]{1,64}$/', $uuid)) {
            $errors['buyer_uuid'] = 'A my.aicountly user id.';
        }
        $buyer  = InvoiceInput::buyer(is_array($input['buyer'] ?? null) ? $input['buyer'] : [], $errors);
        $lines  = InvoiceInput::lines($input['lines'] ?? null, $errors);
        $amount = Money::parse($input['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'The amount received in rupees (e.g. "11800.00").';
        }
        $method = is_string($input['method'] ?? null) ? strtolower(trim($input['method'])) : '';
        if (!isset(self::METHODS[$method])) {
            $errors['method'] = 'One of: ' . implode(', ', array_keys(self::METHODS)) . '.';
        }
        $utr = strtoupper((string) preg_replace('/\s+/', '', is_string($input['utr'] ?? null) ? $input['utr'] : ''));
        if (!preg_match('/^[A-Z0-9]{6,35}$/', $utr)) {
            $errors['utr'] = 'The UTR / transaction reference / cheque number: 6–35 letters and digits.';
        }
        $received = $input['received_on'] ?? null;
        if (!FiscalYear::isDate($received)) {
            $errors['received_on'] = 'The date the money reached the bank account (YYYY-MM-DD).';
        } elseif ($received > FiscalYear::today()) {
            $errors['received_on'] = 'Cannot be in the future.';
        }
        $note = InvoiceInput::text($input['note'] ?? null, 500);
        InvoiceInput::throwIf($errors);

        $calc = $this->computeFor($buyer, $lines);
        if ($calc['totals']['total_paise'] !== $amount) {
            throw ApiException::coded(422, 'amount_mismatch', 'The amount received does not equal the invoice total.', [
                'amount' => 'The invoice total is ' . Money::format($calc['totals']['total_paise']) . ' (including GST); the amount received is ' . Money::format((int) $amount) . '.',
            ]);
        }

        $this->pdo->beginTransaction();
        try {
            $dup = $this->pdo->prepare("SELECT id FROM fin_offline_payments WHERE utr = ? AND status <> 'rejected'");
            $dup->execute([$utr]);
            if ($dup->fetch()) {
                throw ApiException::coded(409, 'duplicate_utr', "UTR {$utr} is already recorded.");
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO fin_offline_payments (cmp_id, buyer_uuid, buyer_legal_name, buyer_gstin, buyer_state_code, buyer_address, buyer_pincode, buyer_country,
                     buyer_email, lines, amount_paise, method, utr, received_on, note, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?) RETURNING id',
            );
            // An ordinary line is stored exactly as before (no discount keys); a discounted one keeps list / discount / ref.
            $stored = array_map(static fn (array $l): array => array_filter(array_diff_key($l, ['rate_bp' => 1]),
                static fn ($v, string $k): bool => $v !== null || !in_array($k, ['list_amount_paise', 'discount_paise', 'discount_ref'], true), ARRAY_FILTER_USE_BOTH), $lines);
            $stmt->execute([$cmpId, $uuid === '' ? null : $uuid, $buyer['legal_name'], $buyer['gstin'], $buyer['state_code'], $buyer['address'], $buyer['pincode'],
                $buyer['country'], $buyer['email'], json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $amount, $method,
                $utr, $received, $note === '' ? null : $note, $actor['actor']]);
            $id = (int) $stmt->fetchColumn();
            $this->audit->record($actor, 'offline_payment.recorded', 'offline_payment', $id, null, [
                'cmp_id' => $cmpId, 'amount_paise' => $amount, 'method' => $method, 'utr' => $utr, 'received_on' => $received, 'buyer' => $buyer['legal_name'],
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id);
    }

    /**
     * The checker confirms the money is in the bank statement; the invoice is issued in the same transaction
     * (or the payment waits as blocked_config when invoicing is not configured).
     *
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function verify(int $id, array $actor): array
    {
        $this->pdo->beginTransaction();
        try {
            $p = $this->lockRecorded($id);
            if (strtolower(trim((string) $p['recorded_by'])) === strtolower(trim($actor['actor']))) {
                throw ApiException::coded(403, 'four_eyes', 'Four-eyes rule: you recorded this payment, so someone else must verify it.');
            }
            $this->pdo->prepare("UPDATE fin_offline_payments SET status = 'verified', verified_by = ?, verified_at = now(), updated_at = now() WHERE id = ?")
                ->execute([$actor['actor'], $id]);
            $this->audit->record($actor, 'offline_payment.verified', 'offline_payment', $id, ['status' => 'recorded'], [
                'status' => 'verified', 'utr' => $p['utr'], 'amount_paise' => (int) $p['amount_paise'], 'recorded_by' => $p['recorded_by'],
            ]);
            $result = $this->invoices->issueForOfflinePayment($id, $actor);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        if ($result['invoice'] !== null) {
            $this->invoices->storePdf('tax_invoice', $result['invoice']['id']);
        }

        return $this->get($id);
    }

    /**
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function reject(int $id, mixed $reason, array $actor): array
    {
        $reason = InvoiceInput::text($reason, 500);
        if ($reason === '') {
            throw ApiException::coded(422, 'validation_failed', 'A reason is required.', ['reason' => 'Required.']);
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockRecorded($id);
            $this->pdo->prepare("UPDATE fin_offline_payments SET status = 'rejected', rejected_by = ?, rejected_at = now(), reject_reason = ?, updated_at = now() WHERE id = ?")
                ->execute([$actor['actor'], $reason, $id]);
            $this->audit->record($actor, 'offline_payment.rejected', 'offline_payment', $id, ['status' => 'recorded'], ['status' => 'rejected', 'reason' => $reason]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($id);
    }

    /**
     * Retry the invoice of a verified payment that was blocked by configuration.
     *
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function retryInvoice(int $id, array $actor): array
    {
        $p = $this->get($id);
        if ($p['status'] !== 'verified' || $p['invoice_state'] === 'issued') {
            throw ApiException::coded(409, 'nothing_to_retry', 'Only a verified payment whose invoice is blocked can be retried.');
        }
        $this->invoices->issueForOfflinePayment($id, $actor);

        return $this->get($id);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE p.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Offline payment not found.');
        }

        return self::publicRow($row);
    }

    /**
     * @param array{status?:?string, invoice_state?:?string} $f
     * @return list<array<string,mixed>>
     */
    public function list(array $f, int $limit = 200): array
    {
        $where  = [];
        $params = [];
        if (!empty($f['status'])) {
            $where[]  = 'p.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['invoice_state'])) {
            $where[]  = 'p.invoice_state = ?';
            $params[] = $f['invoice_state'];
        }
        $stmt = $this->pdo->prepare(self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY p.id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute($params);

        return array_map([self::class, 'publicRow'], $stmt->fetchAll());
    }

    private const SELECT = 'SELECT p.*, i.id AS invoice_id, i.document_id AS invoice_document_id, i.doc_no AS invoice_doc_no
                              FROM fin_offline_payments p LEFT JOIN fin_invoices i ON i.offline_payment_id = p.id';

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function publicRow(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'cmp_id'         => (int) $r['cmp_id'],
            'buyer_uuid'     => $r['buyer_uuid'],
            'buyer'          => [
                'legal_name' => (string) $r['buyer_legal_name'], 'gstin' => $r['buyer_gstin'], 'state_code' => (string) $r['buyer_state_code'],
                'address' => (string) $r['buyer_address'], 'pincode' => (string) $r['buyer_pincode'], 'country' => (string) $r['buyer_country'],
                'email' => $r['buyer_email'],
            ],
            'lines'          => json_decode((string) $r['lines'], true),
            'amount_paise'   => (int) $r['amount_paise'],
            'method'         => (string) $r['method'],
            'method_label'   => self::METHODS[$r['method']] ?? (string) $r['method'],
            'utr'            => (string) $r['utr'],
            'received_on'    => (string) $r['received_on'],
            'note'           => $r['note'],
            'status'         => (string) $r['status'],
            'invoice_state'  => (string) $r['invoice_state'],
            'invoice_error'  => $r['invoice_error'],
            'recorded_by'    => (string) $r['recorded_by'],
            'recorded_at'    => (string) $r['recorded_at'],
            'verified_by'    => $r['verified_by'],
            'verified_at'    => $r['verified_at'],
            'rejected_by'    => $r['rejected_by'],
            'rejected_at'    => $r['rejected_at'],
            'reject_reason'  => $r['reject_reason'],
            'invoice'        => $r['invoice_id'] === null ? null : [
                'id' => (int) $r['invoice_id'], 'document_id' => (string) $r['invoice_document_id'], 'doc_no' => (string) $r['invoice_doc_no'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $buyer
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    private function computeFor(array $buyer, array $lines): array
    {
        $seller = $this->invoices->sellerRow();
        $pos    = GstCalculator::placeOfSupply($buyer['gstin'], $buyer['state_code']);
        $calc   = InvoiceInput::compute($lines, (string) $seller['state_code'], $pos);

        return [
            'place_of_supply' => $pos,
            'supply_type'     => GstCalculator::supplyType((string) $seller['state_code'], $pos),
            'seller_state'    => (string) $seller['state_code'],
            'lines'           => array_map(static fn (array $l) => [
                'description' => $l['description'], 'sac' => $l['sac'], 'gst_rate' => $l['gst_rate'], 'price_basis' => $l['price_basis'],
                'amount_paise' => $l['amount_paise'], 'taxable_paise' => $l['taxable'], 'cgst_paise' => $l['cgst'], 'sgst_paise' => $l['sgst'],
                'igst_paise' => $l['igst'], 'total_paise' => $l['total'],
            ] + ($l['list_amount_paise'] !== null ? [
                'list_amount_paise' => $l['list_amount_paise'], 'discount_paise' => $l['discount_paise'], 'discount_ref' => $l['discount_ref'],
            ] : []), $calc['lines']),
            'totals'          => [
                'taxable_paise' => $calc['totals']['taxable'], 'cgst_paise' => $calc['totals']['cgst'], 'sgst_paise' => $calc['totals']['sgst'],
                'igst_paise' => $calc['totals']['igst'], 'total_paise' => $calc['totals']['total'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function lockRecorded(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fin_offline_payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            throw ApiException::notFound('Offline payment not found.');
        }
        if ($p['status'] !== 'recorded') {
            throw ApiException::coded(409, 'not_pending', 'This payment is already ' . $p['status'] . '.');
        }

        return $p;
    }
}
