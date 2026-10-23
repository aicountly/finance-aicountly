<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Services\Payouts\BankFileFormatter;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Gstin;
use App\Support\Money;

/**
 * Read models for tax invoices and credit notes: the document "face" (contract §1.3, also the input of the PDF
 * template), the staff register, Manage's cursor-paged list (§1.2) and the GSTR-1 friendly CSV.
 *
 * Every Manage query is filtered by cmp_id; a document of another company is reported exactly like a missing one.
 */
final class DocumentQueries
{
    /** GSTR-1 B2CL: inter-state supply to an unregistered person above ₹1,00,000 (Notification 12/2024-CT, from 1 Aug 2024). */
    public const B2CL_THRESHOLD_PAISE = 10_000_000;

    public const METHOD_LABELS = [
        'neft'    => 'NEFT',
        'rtgs'    => 'RTGS',
        'imps'    => 'IMPS',
        'upi'     => 'UPI',
        'cheque'  => 'Cheque',
        'gateway' => 'Online payment',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ document face

    /** @return array<string,mixed>|null */
    public function invoice(int $id, ?int $cmpId = null): ?array
    {
        $sql = 'SELECT * FROM fin_invoices WHERE id = ?' . ($cmpId !== null ? ' AND cmp_id = ?' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($cmpId !== null ? [$id, $cmpId] : [$id]);
        $row = $stmt->fetch();

        return $row ? $this->invoiceShape($row) : null;
    }

    /** @return array<string,mixed>|null issued, pending or rejected */
    public function creditNote(int $id, ?int $cmpId = null, bool $issuedOnly = false): ?array
    {
        $sql = 'SELECT * FROM fin_credit_notes WHERE id = ?' . ($cmpId !== null ? ' AND cmp_id = ?' : '') . ($issuedOnly ? " AND status = 'issued'" : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($cmpId !== null ? [$id, $cmpId] : [$id]);
        $row = $stmt->fetch();

        return $row ? $this->creditNoteShape($row) : null;
    }

    /**
     * Manage: an issued document of this company by its public id, else null (also for another company's).
     *
     * @return array<string,mixed>|null
     */
    public function byDocumentId(string $documentId, int $cmpId): ?array
    {
        if (preg_match('/^fin_inv_[0-9A-HJKMNP-TV-Z]{26}$/', $documentId)) {
            $stmt = $this->pdo->prepare('SELECT id FROM fin_invoices WHERE document_id = ? AND cmp_id = ?');
            $stmt->execute([$documentId, $cmpId]);
            $id = $stmt->fetchColumn();

            return $id === false ? null : $this->invoice((int) $id, $cmpId);
        }
        if (preg_match('/^fin_cn_[0-9A-HJKMNP-TV-Z]{26}$/', $documentId)) {
            $stmt = $this->pdo->prepare("SELECT id FROM fin_credit_notes WHERE document_id = ? AND cmp_id = ? AND status = 'issued'");
            $stmt->execute([$documentId, $cmpId]);
            $id = $stmt->fetchColumn();

            return $id === false ? null : $this->creditNote((int) $id, $cmpId, true);
        }

        return null;
    }

    /**
     * The stored rendering of a document.
     *
     * @return array{html:string, html_sha256:string, pdf:?string, pdf_sha256:?string, renderer:?string, rendered_at:?string, last_error:?string}|null
     */
    public function rendering(string $docType, int $id): ?array
    {
        $col  = $docType === 'credit_note' ? 'credit_note_id' : 'invoice_id';
        $stmt = $this->pdo->prepare("SELECT html, html_sha256, pdf, pdf_sha256, renderer, rendered_at, last_error FROM fin_doc_pdfs WHERE {$col} = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (is_resource($row['pdf'])) {
            $row['pdf'] = stream_get_contents($row['pdf']);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function invoiceShape(array $r): array
    {
        $lines = $this->pdo->prepare('SELECT * FROM fin_invoice_lines WHERE invoice_id = ? ORDER BY line_no');
        $lines->execute([(int) $r['id']]);
        $lineRows = $lines->fetchAll();

        $cn = $this->pdo->prepare("SELECT id, document_id, doc_no, doc_date, total_paise, status, kind FROM fin_credit_notes WHERE invoice_id = ? ORDER BY id");
        $cn->execute([(int) $r['id']]);
        $creditNotes = $cn->fetchAll();

        $doc = $this->common($r, 'tax_invoice', $lineRows);
        $doc['status']         = (string) $r['status'];
        $doc['credited_paise'] = (int) $r['credited_paise'];
        $doc['payment']        = [
            'method'       => (string) $r['payment_method'],
            'method_label' => self::METHOD_LABELS[$r['payment_method']] ?? (string) $r['payment_method'],
            'gateway'      => $r['payment_gateway'],
            'reference'    => (string) $r['payment_reference'],
            'paid_on'      => (string) $r['paid_on'],
            'amount_paise' => (int) $r['total_paise'],
        ];
        $doc['original_invoice'] = null;
        $doc['source']           = ['kind' => (string) $r['source'], 'offline_payment_id' => $r['offline_payment_id'] === null ? null : (int) $r['offline_payment_id'],
            'payment_id' => $r['payment_id'] === null ? null : (int) $r['payment_id']];
        $doc['issued_by']        = (string) $r['issued_by'];
        $doc['issued_at']        = (string) $r['issued_at'];
        $doc['related']          = array_values(array_map(static fn (array $c) => [
            'document_id' => (string) $c['document_id'], 'doc_type' => 'credit_note', 'doc_no' => $c['doc_no'],
            'doc_date' => $c['doc_date'], 'total_paise' => (int) $c['total_paise'],
        ], array_filter($creditNotes, static fn (array $c) => $c['status'] === 'issued')));
        $doc['credit_notes']     = array_map(static fn (array $c) => [
            'id' => (int) $c['id'], 'document_id' => (string) $c['document_id'], 'doc_no' => $c['doc_no'], 'doc_date' => $c['doc_date'],
            'total_paise' => (int) $c['total_paise'], 'status' => (string) $c['status'], 'kind' => (string) $c['kind'],
        ], $creditNotes);
        $doc['remaining_paise']  = $this->remainingByLine((int) $r['id']);

        return $doc;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function creditNoteShape(array $r): array
    {
        $lines = $this->pdo->prepare(
            'SELECT c.*, l.item_code, l.item_kind, l.period_start, l.period_end, l.price_basis, l.line_no AS invoice_line_no
               FROM fin_credit_note_lines c JOIN fin_invoice_lines l ON l.id = c.invoice_line_id
              WHERE c.credit_note_id = ? ORDER BY c.line_no',
        );
        $lines->execute([(int) $r['id']]);
        $lineRows = $lines->fetchAll();

        $inv = $this->pdo->prepare('SELECT id, document_id, doc_no, doc_date, fy, total_paise FROM fin_invoices WHERE id = ?');
        $inv->execute([(int) $r['invoice_id']]);
        $orig = $inv->fetch();

        $doc = $this->common($r, 'credit_note', $lineRows);
        $doc['status']           = (string) $r['status'];
        $doc['kind']             = (string) $r['kind'];
        $doc['reason']           = (string) $r['reason'];
        $doc['payment']          = null;
        $doc['original_invoice'] = $orig ? [
            'id' => (int) $orig['id'], 'document_id' => (string) $orig['document_id'], 'doc_no' => (string) $orig['doc_no'],
            'doc_date' => (string) $orig['doc_date'], 'fy' => (string) $orig['fy'], 'total_paise' => (int) $orig['total_paise'],
        ] : null;
        $doc['related']      = $orig ? [['document_id' => (string) $orig['document_id'], 'doc_type' => 'tax_invoice', 'doc_no' => (string) $orig['doc_no'],
            'doc_date' => (string) $orig['doc_date'], 'total_paise' => (int) $orig['total_paise']]] : [];
        $doc['requested_by'] = (string) $r['requested_by'];
        $doc['requested_at'] = (string) $r['requested_at'];
        $doc['approved_by']  = $r['approved_by'];
        $doc['approved_at']  = $r['approved_at'];
        $doc['rejected_by']  = $r['rejected_by'];
        $doc['reject_reason'] = $r['reject_reason'];
        $doc['credit_deadline'] = $orig ? CreditNoteService::deadline((string) $orig['fy']) : null;

        return $doc;
    }

    /**
     * @param array<string,mixed> $r
     * @param list<array<string,mixed>> $lineRows
     * @return array<string,mixed>
     */
    private function common(array $r, string $docType, array $lineRows): array
    {
        $seller = is_string($r['seller'] ?? null) ? (json_decode($r['seller'], true) ?: null) : null;
        $lines  = array_map(static function (array $l): array {
            $bp   = Money::parseRate((string) $l['gst_rate'], 9999) ?? 0;
            $half = $bp % 2 === 0 ? Money::formatRate(intdiv($bp, 2)) : sprintf('%d.%03d', intdiv($bp, 200), ($bp % 200) * 5);

            return [
                'line_no'       => (int) $l['line_no'],
                'item_code'     => $l['item_code'] ?? null,
                'item_kind'     => $l['item_kind'] ?? null,
                'description'   => (string) $l['description'],
                'sac'           => (string) $l['sac'],
                'gst_rate'      => (string) $l['gst_rate'],
                'price_basis'   => $l['price_basis'] ?? null,
                'period_start'  => $l['period_start'] ?? null,
                'period_end'    => $l['period_end'] ?? null,
                'quantity'      => isset($l['quantity']) ? self::qty((string) $l['quantity']) : null,
                'amount_paise'  => isset($l['amount_paise']) ? (int) $l['amount_paise'] : null,
                'taxable_paise' => (int) $l['taxable_paise'],
                'cgst_rate'     => $half,
                'cgst_paise'    => (int) $l['cgst_paise'],
                'sgst_rate'     => $half,
                'sgst_paise'    => (int) $l['sgst_paise'],
                'igst_rate'     => (string) $l['gst_rate'],
                'igst_paise'    => (int) $l['igst_paise'],
                'total_paise'   => (int) $l['total_paise'],
                'invoice_line_id' => isset($l['invoice_line_id']) ? (int) $l['invoice_line_id'] : null,
                'id'            => (int) $l['id'],
            ];
        }, $lineRows);
        $starts = array_filter(array_column($lines, 'period_start'));
        $ends   = array_filter(array_column($lines, 'period_end'));
        $first  = $lines[0]['description'] ?? '';

        return [
            'id'              => (int) $r['id'],
            'document_id'     => (string) $r['document_id'],
            'doc_type'        => $docType,
            'doc_no'          => $r['doc_no'],
            'doc_date'        => $r['doc_date'],
            'fy'              => $r['fy'],
            'cmp_id'          => $r['cmp_id'] === null ? null : (int) $r['cmp_id'],
            'buyer_kind'      => (string) ($r['buyer_kind'] ?? 'company'),
            'seller'          => $seller,
            'buyer'           => [
                'legal_name' => (string) $r['buyer_legal_name'],
                'gstin'      => $r['buyer_gstin'],
                'state_code' => (string) $r['buyer_state_code'],
                'state_name' => Gstin::stateName((string) $r['buyer_state_code']),
                'address'    => (string) $r['buyer_address'],
                'pincode'    => $r['buyer_pincode'],
                'country'    => (string) $r['buyer_country'],
                'email'      => $r['buyer_email'],
            ],
            'place_of_supply' => ['code' => (string) $r['place_of_supply'], 'name' => Gstin::stateName((string) $r['place_of_supply'])],
            'supply_type'     => (string) $r['supply_type'],
            'reverse_charge'  => !empty($r['reverse_charge']),
            'currency'        => (string) $r['currency'],
            'lines'           => $lines,
            'totals'          => [
                'taxable_paise' => (int) $r['taxable_paise'],
                'cgst_paise'    => (int) $r['cgst_paise'],
                'sgst_paise'    => (int) $r['sgst_paise'],
                'igst_paise'    => (int) $r['igst_paise'],
                'tax_paise'     => (int) $r['cgst_paise'] + (int) $r['sgst_paise'] + (int) $r['igst_paise'],
                'total_paise'   => (int) $r['total_paise'],
            ],
            'total_paise'     => (int) $r['total_paise'],
            'amount_in_words' => (string) $r['amount_in_words'],
            'summary'         => $first . (count($lines) > 1 ? ' + ' . (count($lines) - 1) . ' more' : ''),
            'period_start'    => $starts === [] ? null : min($starts),
            'period_end'      => $ends === [] ? null : max($ends),
            'irn'             => null,
            'ack_no'          => null,
            'ack_date'        => null,
            'pdf_sha256'      => $this->pdfSha($docType, (int) $r['id']),
        ];
    }

    private function pdfSha(string $docType, int $id): ?string
    {
        $col  = $docType === 'credit_note' ? 'credit_note_id' : 'invoice_id';
        $stmt = $this->pdo->prepare("SELECT pdf_sha256 FROM fin_doc_pdfs WHERE {$col} = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();

        return is_string($v) ? $v : null;
    }

    /**
     * What is still creditable per invoice line (issued and pending credit notes deducted).
     *
     * @return array<int, array{taxable:int, cgst:int, sgst:int, igst:int, total:int}> keyed by invoice line id
     */
    public function remainingByLine(int $invoiceId, ?int $excludeCreditNoteId = null, bool $issuedOnly = false): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.id, l.taxable_paise - coalesce(sum(c.taxable_paise), 0) AS taxable, l.cgst_paise - coalesce(sum(c.cgst_paise), 0) AS cgst,
                    l.sgst_paise - coalesce(sum(c.sgst_paise), 0) AS sgst, l.igst_paise - coalesce(sum(c.igst_paise), 0) AS igst,
                    l.total_paise - coalesce(sum(c.total_paise), 0) AS total
               FROM fin_invoice_lines l
               LEFT JOIN (fin_credit_note_lines c JOIN fin_credit_notes n ON n.id = c.credit_note_id
                          AND n.status IN (" . ($issuedOnly ? "'issued'" : "'issued','pending_approval'") . ") AND n.id IS DISTINCT FROM ?)
                      ON c.invoice_line_id = l.id
              WHERE l.invoice_id = ? GROUP BY l.id ORDER BY l.line_no",
        );
        $stmt->execute([$excludeCreditNoteId, $invoiceId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = ['taxable' => (int) $row['taxable'], 'cgst' => (int) $row['cgst'], 'sgst' => (int) $row['sgst'],
                'igst' => (int) $row['igst'], 'total' => (int) $row['total']];
        }

        return $out;
    }

    // ------------------------------------------------------------------ Manage list (contract §1.2, cursor paging §0.3)

    /**
     * @return array{rows:list<array<string,mixed>>, next_cursor:?string, limit:int}
     */
    public function listForCompany(int $cmpId, ?string $fy, ?string $docType, int $limit, ?array $cursor): array
    {
        $params = [];
        $parts  = [];
        if ($docType === null || $docType === 'tax_invoice') {
            $parts[] = "SELECT id, 'tax_invoice' AS doc_type, document_id, doc_no, doc_date, total_paise, status FROM fin_invoices WHERE cmp_id = ?"
                . ($fy !== null ? ' AND fy = ?' : '');
            $params[] = $cmpId;
            if ($fy !== null) {
                $params[] = $fy;
            }
        }
        if ($docType === null || $docType === 'credit_note') {
            $parts[] = "SELECT id, 'credit_note' AS doc_type, document_id, doc_no, doc_date, total_paise, status FROM fin_credit_notes WHERE cmp_id = ? AND status = 'issued'"
                . ($fy !== null ? ' AND fy = ?' : '');
            $params[] = $cmpId;
            if ($fy !== null) {
                $params[] = $fy;
            }
        }
        $where = '';
        if ($cursor !== null) {
            $where    = 'WHERE (d.doc_date, d.id) < (?::date, ?)';
            $params[] = $cursor['d'];
            $params[] = $cursor['id'];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM (' . implode(' UNION ALL ', $parts) . ") d {$where} ORDER BY d.doc_date DESC, d.id DESC LIMIT " . ($limit + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $out = [];
        foreach ($rows as $row) {
            $doc   = $row['doc_type'] === 'tax_invoice' ? $this->invoice((int) $row['id'], $cmpId) : $this->creditNote((int) $row['id'], $cmpId, true);
            $out[] = [
                'document_id'  => $doc['document_id'],
                'doc_type'     => $doc['doc_type'],
                'doc_no'       => $doc['doc_no'],
                'doc_date'     => $doc['doc_date'],
                'fy'           => $doc['fy'],
                'total_paise'  => $doc['total_paise'],
                'status'       => $doc['status'],
                'summary'      => $doc['summary'],
                'period_start' => $doc['period_start'],
                'period_end'   => $doc['period_end'],
                'related'      => $doc['related'],
            ];
        }
        $last = end($rows);

        return [
            'rows'        => $out,
            'next_cursor' => $more && $last ? self::encodeCursor((string) $last['doc_date'], (int) $last['id']) : null,
            'limit'       => $limit,
        ];
    }

    public static function encodeCursor(string $date, int $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['d' => $date, 'id' => $id])), '+/', '-_'), '=');
    }

    /** @return array{d:string, id:int} */
    public static function decodeCursor(string $cursor): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !FiscalYear::isDate($data['d'] ?? null) || !is_int($data['id'] ?? null) || $data['id'] < 1) {
            throw ApiException::coded(400, 'bad_request', 'The cursor is not valid.', ['field' => 'cursor']);
        }

        return ['d' => $data['d'], 'id' => $data['id']];
    }

    /**
     * Strip what a customer must not see (staff fields, internal ids) from a document face.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public static function forManage(array $doc): array
    {
        unset($doc['id'], $doc['buyer_kind'], $doc['source'], $doc['issued_by'], $doc['credit_notes'], $doc['remaining_paise'], $doc['requested_by'], $doc['requested_at'],
            $doc['approved_by'], $doc['approved_at'], $doc['rejected_by'], $doc['reject_reason'], $doc['credit_deadline']);
        if (is_array($doc['original_invoice'] ?? null)) {
            unset($doc['original_invoice']['id']);
        }
        $doc['lines'] = array_map(static function (array $l): array {
            unset($l['id'], $l['invoice_line_id']);

            return $l;
        }, (array) $doc['lines']);

        return $doc;
    }

    // ------------------------------------------------------------------ staff register + CSV

    /**
     * @param array{fy?:?string, doc_type?:?string, status?:?string, cmp_id?:?int, buyer_kind?:?string, q?:?string} $f
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, per_page:int, totals:array<string,int>}
     */
    public function register(array $f, int $page = 1, int $perPage = 50): array
    {
        [$sql, $params] = $this->registerQuery($f);
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $count = $this->pdo->prepare("SELECT count(*) AS n, coalesce(sum(CASE WHEN doc_type = 'tax_invoice' THEN total_paise ELSE 0 END), 0) AS inv,
                                             coalesce(sum(CASE WHEN doc_type = 'credit_note' AND status = 'issued' THEN total_paise ELSE 0 END), 0) AS cn
                                        FROM ({$sql}) r");
        $count->execute($params);
        $agg  = $count->fetch();
        $stmt = $this->pdo->prepare("SELECT * FROM ({$sql}) r ORDER BY doc_date DESC NULLS FIRST, id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($params);
        $rows = array_map(static fn (array $r) => [
            'id'               => (int) $r['id'],
            'doc_type'         => (string) $r['doc_type'],
            'document_id'      => (string) $r['document_id'],
            'doc_no'           => $r['doc_no'],
            'doc_date'         => $r['doc_date'],
            'fy'               => $r['fy'],
            'cmp_id'           => $r['cmp_id'] === null ? null : (int) $r['cmp_id'],
            'buyer_kind'       => (string) ($r['buyer_kind'] ?? 'company'),
            'buyer_legal_name' => (string) $r['buyer_legal_name'],
            'buyer_gstin'      => $r['buyer_gstin'],
            'place_of_supply'  => (string) $r['place_of_supply'],
            'taxable_paise'    => (int) $r['taxable_paise'],
            'tax_paise'        => (int) $r['cgst_paise'] + (int) $r['sgst_paise'] + (int) $r['igst_paise'],
            'total_paise'      => (int) $r['total_paise'],
            'status'           => (string) $r['status'],
            'invoice_id'       => $r['invoice_id'] === null ? null : (int) $r['invoice_id'],
        ], $stmt->fetchAll());

        return ['rows' => $rows, 'total' => (int) $agg['n'], 'page' => $page, 'per_page' => $perPage,
            'totals' => ['invoiced_paise' => (int) $agg['inv'], 'credited_paise' => (int) $agg['cn']]];
    }

    /**
     * @param array{fy?:?string, doc_type?:?string, status?:?string, cmp_id?:?int, buyer_kind?:?string, q?:?string} $f
     * @return array{0:string, 1:list<mixed>}
     */
    private function registerQuery(array $f): array
    {
        $cols = 'id, document_id, doc_no, doc_date, fy, cmp_id, buyer_kind, buyer_legal_name, buyer_gstin, place_of_supply, supply_type, taxable_paise, cgst_paise, sgst_paise, igst_paise, total_paise, status';
        $params = [];
        $parts  = [];
        $docType = $f['doc_type'] ?? null;
        $status  = $f['status'] ?? null;
        $filters = function (string $alias) use ($f, &$params): string {
            $w = '';
            if (!empty($f['fy'])) {
                $w .= " AND {$alias}.fy = ?";
                $params[] = $f['fy'];
            }
            if (!empty($f['cmp_id'])) {
                $w .= " AND {$alias}.cmp_id = ?";
                $params[] = (int) $f['cmp_id'];
            }
            if (!empty($f['buyer_kind'])) {
                $w .= " AND {$alias}.buyer_kind = ?";
                $params[] = (string) $f['buyer_kind'];
            }
            if (!empty($f['q'])) {
                $w .= " AND ({$alias}.doc_no ILIKE ? OR {$alias}.buyer_legal_name ILIKE ? OR {$alias}.buyer_gstin ILIKE ?)";
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
                array_push($params, $like, $like, $like);
            }

            return $w;
        };
        if ($docType === null || $docType === 'tax_invoice') {
            $w = $filters('i');
            if ($status !== null && in_array($status, ['issued', 'partially_credited', 'credited'], true)) {
                $w .= ' AND i.status = ?';
                $params[] = $status;
            } elseif ($status !== null) {
                $w .= ' AND false';
            }
            $parts[] = "SELECT 'tax_invoice' AS doc_type, NULL::bigint AS invoice_id, {$cols} FROM fin_invoices i WHERE true{$w}";
        }
        if ($docType === null || $docType === 'credit_note') {
            $w = $filters('c');
            if ($status !== null && in_array($status, ['issued', 'pending_approval', 'rejected'], true)) {
                $w .= ' AND c.status = ?';
                $params[] = $status;
            } elseif ($status !== null) {
                $w .= ' AND false';
            } else {
                $w .= " AND c.status = 'issued'";
            }
            $parts[] = "SELECT 'credit_note' AS doc_type, c.invoice_id, {$cols} FROM fin_credit_notes c WHERE true{$w}";
        }

        return [implode(' UNION ALL ', $parts), $params];
    }

    /**
     * GSTR-1 friendly CSV of issued documents: one row per document per (SAC, rate), with the GSTR-1 table each row
     * belongs to — B2B (buyer has a GSTIN), B2CL (inter-state, unregistered, document value above ₹1,00,000), B2CS
     * (other unregistered), CDNR / CDNUR (credit notes to registered / B2CL buyers; a credit note to a B2CS buyer
     * is reported as B2CS and reduces that table). Values are positive rupees; the Document type says which are
     * credit notes. Confirm the mapping with your CA before filing.
     *
     * @param array{fy?:?string, doc_type?:?string, status?:?string, cmp_id?:?int, buyer_kind?:?string, q?:?string} $f
     */
    public function gstr1Csv(array $f): string
    {
        $f['status'] = in_array($f['status'] ?? null, ['issued', 'partially_credited', 'credited'], true) ? $f['status'] : null;
        [$sql, $params] = $this->registerQuery($f);
        $stmt = $this->pdo->prepare("SELECT * FROM ({$sql}) r ORDER BY doc_date, id");
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        $head = ['GSTR-1 section', 'Document type', 'Document number', 'Document date', 'Original invoice number', 'Original invoice date',
            'Company (cmp_id)', 'Buyer name', 'Buyer GSTIN', 'Place of supply', 'Supply type', 'Reverse charge', 'Document value',
            'SAC', 'Rate (%)', 'Taxable value', 'IGST', 'CGST', 'SGST', 'Cess'];
        $out = [BankFileFormatter::csv($head)];
        $invLines = $this->pdo->prepare('SELECT sac, gst_rate, sum(taxable_paise) t, sum(igst_paise) i, sum(cgst_paise) c, sum(sgst_paise) s
                                           FROM fin_invoice_lines WHERE invoice_id = ? GROUP BY sac, gst_rate ORDER BY sac, gst_rate');
        $cnLines  = $this->pdo->prepare('SELECT sac, gst_rate, sum(taxable_paise) t, sum(igst_paise) i, sum(cgst_paise) c, sum(sgst_paise) s
                                           FROM fin_credit_note_lines WHERE credit_note_id = ? GROUP BY sac, gst_rate ORDER BY sac, gst_rate');
        $orig     = $this->pdo->prepare('SELECT doc_no, doc_date, total_paise, supply_type FROM fin_invoices WHERE id = ?');
        foreach ($docs as $d) {
            $isCn = $d['doc_type'] === 'credit_note';
            $o    = null;
            if ($isCn) {
                $orig->execute([(int) $d['invoice_id']]);
                $o = $orig->fetch() ?: null;
            }
            $inter = $d['supply_type'] === 'inter_state';
            if ($d['buyer_gstin'] !== null) {
                $section = $isCn ? 'CDNR' : 'B2B';
            } elseif ($isCn) {
                $section = $o && $o['supply_type'] === 'inter_state' && (int) $o['total_paise'] > self::B2CL_THRESHOLD_PAISE ? 'CDNUR' : 'B2CS';
            } else {
                $section = $inter && (int) $d['total_paise'] > self::B2CL_THRESHOLD_PAISE ? 'B2CL' : 'B2CS';
            }
            $lines = $isCn ? $cnLines : $invLines;
            $lines->execute([(int) $d['id']]);
            foreach ($lines->fetchAll() as $g) {
                $out[] = BankFileFormatter::csv(array_map('strval', [
                    $section, $isCn ? 'Credit Note' : 'Invoice', (string) $d['doc_no'], DocumentHtml::dmy((string) $d['doc_date']),
                    $o ? (string) $o['doc_no'] : '', $o ? DocumentHtml::dmy((string) $o['doc_date']) : '',
                    (string) $d['cmp_id'], (string) $d['buyer_legal_name'], (string) ($d['buyer_gstin'] ?? ''), Gstin::stateLabel((string) $d['place_of_supply']),
                    $inter ? 'Inter-state' : 'Intra-state', 'N', Money::format((int) $d['total_paise']),
                    (string) $g['sac'], (string) $g['gst_rate'], Money::format((int) $g['t']), Money::format((int) $g['i']),
                    Money::format((int) $g['c']), Money::format((int) $g['s']), '0.00',
                ]));
            }
        }

        return implode("\r\n", $out) . "\r\n";
    }

    private static function qty(string $q): string
    {
        return str_contains($q, '.') ? rtrim(rtrim($q, '0'), '.') : $q;
    }
}
