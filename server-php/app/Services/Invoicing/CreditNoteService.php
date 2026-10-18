<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Services\AuditLog;
use App\Support\AmountInWords;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Money;
use App\Support\Ulid;

/**
 * Credit notes against an issued tax invoice (contract §1.5), with four-eyes: a maker requests one (full, or partial
 * amounts per line, GST-inclusive), a different person approves it — only then is it numbered from the FY's
 * credit_note series, rendered and stored — or rejects it. Corrections to an invoice are made this way only.
 *
 *  - The credited value per invoice line never exceeds what that line still has (issued and pending notes counted);
 *    a full credit copies the remaining taxable value and tax heads exactly, so a fully credited invoice nets to zero.
 *  - A partial amount is split like an inclusive price at the line's rate (tax = round(gross × R / (100 + R))), CGST/SGST
 *    or IGST as on the invoice, never exceeding any remaining head.
 *  - Deadline (s.34(2) CGST Act): 30 November after the end of the invoice's FY (or the annual return date if that is
 *    earlier — not tracked here).
 *  - Refund-driven credit notes (phase B, from fin_refunds) will use the same issue path.
 */
final class CreditNoteService
{
    private AuditLog $audit;
    private DocumentQueries $queries;

    /** @param (\Closure():string)|null $today */
    public function __construct(private \PDO $pdo, private ?\Closure $today = null, private ?InvoiceService $invoices = null)
    {
        $this->audit    = new AuditLog($pdo);
        $this->queries  = new DocumentQueries($pdo);
        $this->invoices ??= new InvoiceService($pdo, $today);
    }

    private function today(): string
    {
        return $this->today !== null ? ($this->today)() : FiscalYear::today();
    }

    /** Last day a credit note may be issued for an invoice of FY $fy ("2026-27" → "2027-11-30"). */
    public static function deadline(string $fy): string
    {
        return sprintf('%d-11-30', (int) substr($fy, 0, 4) + 1);
    }

    /**
     * @param array<string,mixed> $input kind full|partial, reason, lines [{invoice_line_id, amount}] for partial
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed> the pending credit note
     */
    public function request(int $invoiceId, array $input, array $actor): array
    {
        $kind   = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        $reason = InvoiceInput::text($input['reason'] ?? null, 500);
        $errors = [];
        if (!in_array($kind, ['full', 'partial'], true)) {
            $errors['kind'] = 'full or partial.';
        }
        if ($reason === '') {
            $errors['reason'] = 'Say why the invoice is credited (e.g. refund, price correction).';
        }
        InvoiceInput::throwIf($errors, 'The credit note request is invalid.');

        return $this->tx(function () use ($invoiceId, $input, $kind, $reason, $actor): array {
            $inv = $this->lockInvoice($invoiceId);
            $this->assertWithinDeadline((string) $inv['fy']);
            $remaining = $this->queries->remainingByLine($invoiceId);
            $lines     = $this->creditLines($invoiceId, $kind, $input['lines'] ?? null, $remaining, (string) $inv['supply_type']);
            $totals    = GstCalculator::totals($lines);

            $stmt = $this->pdo->prepare(
                'INSERT INTO fin_credit_notes (document_id, invoice_id, cmp_id, kind, reason, buyer_legal_name, buyer_gstin, buyer_state_code, buyer_address,
                     buyer_pincode, buyer_country, buyer_email, place_of_supply, supply_type, taxable_paise, cgst_paise, sgst_paise, igst_paise, total_paise,
                     amount_in_words, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            );
            $stmt->execute(['fin_cn_' . Ulid::generate(), $invoiceId, (int) $inv['cmp_id'], $kind, $reason, $inv['buyer_legal_name'], $inv['buyer_gstin'],
                $inv['buyer_state_code'], $inv['buyer_address'], $inv['buyer_pincode'], $inv['buyer_country'], $inv['buyer_email'], $inv['place_of_supply'],
                $inv['supply_type'], $totals['taxable'], $totals['cgst'], $totals['sgst'], $totals['igst'], $totals['total'], AmountInWords::inr($totals['total']),
                $actor['actor']]);
            $id  = (int) $stmt->fetchColumn();
            $ins = $this->pdo->prepare(
                'INSERT INTO fin_credit_note_lines (credit_note_id, line_no, invoice_line_id, description, sac, gst_rate, taxable_paise, cgst_paise, sgst_paise,
                     igst_paise, total_paise) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            foreach ($lines as $i => $l) {
                $ins->execute([$id, $i + 1, $l['invoice_line_id'], $l['description'], $l['sac'], $l['gst_rate'], $l['taxable'], $l['cgst'], $l['sgst'], $l['igst'], $l['total']]);
            }
            $this->audit->record($actor, 'credit_note.requested', 'credit_note', $id, null, [
                'invoice_id' => $invoiceId, 'invoice_doc_no' => $inv['doc_no'], 'kind' => $kind, 'reason' => $reason, 'total_paise' => $totals['total'],
            ]);

            return $this->queries->creditNote($id);
        });
    }

    /**
     * Four-eyes approval: number, seller snapshot and stored rendering are assigned now.
     *
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function approve(int $creditNoteId, array $actor): array
    {
        $doc = $this->tx(function () use ($creditNoteId, $actor): array {
            $cn = $this->lockPending($creditNoteId);
            if (strtolower(trim((string) $cn['requested_by'])) === strtolower(trim($actor['actor']))) {
                throw ApiException::coded(403, 'four_eyes', 'Four-eyes rule: you requested this credit note, so someone else must approve it.');
            }
            $inv = $this->lockInvoice((int) $cn['invoice_id']);
            $this->assertWithinDeadline((string) $inv['fy']);

            // Re-check against what is already credited by issued notes (pending ones may lose the race).
            $remaining = $this->queries->remainingByLine((int) $cn['invoice_id'], $creditNoteId, true);
            $lines = $this->pdo->prepare('SELECT * FROM fin_credit_note_lines WHERE credit_note_id = ?');
            $lines->execute([$creditNoteId]);
            foreach ($lines->fetchAll() as $l) {
                $r = $remaining[(int) $l['invoice_line_id']] ?? null;
                foreach (['taxable', 'cgst', 'sgst', 'igst', 'total'] as $h) {
                    if ($r === null || (int) $l[$h . '_paise'] > $r[$h]) {
                        throw ApiException::coded(409, 'over_credit', 'Another credit note has been issued against this invoice since: this one would credit more than is left. Reject it and request a new one.');
                    }
                }
            }

            $seller = $this->invoices->sellerRow();
            if ($seller['gstin'] !== $inv['seller_gstin']) {
                throw ApiException::coded(422, 'seller_changed', 'The seller GSTIN changed since the invoice was issued: a credit note must be issued under the invoice\'s GSTIN (' . $inv['seller_gstin'] . ').');
            }
            $today = $this->today();
            $num   = (new DocumentNumbering($this->pdo))->next(FiscalYear::forDate($today), 'credit_note');
            $this->pdo->prepare(
                "UPDATE fin_credit_notes SET status = 'issued', series_id = ?, fy = ?, seq_no = ?, doc_no = ?, doc_date = ?, seller_profile_id = ?, seller = ?::jsonb,
                        seller_gstin = ?, approved_by = ?, approved_at = now() WHERE id = ?",
            )->execute([$num['series_id'], $num['fy'], $num['seq_no'], $num['doc_no'], $today, (int) $seller['id'],
                json_encode(InvoiceService::sellerSnapshot($seller), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $seller['gstin'], $actor['actor'], $creditNoteId]);
            $credited = (int) $inv['credited_paise'] + (int) $cn['total_paise'];
            $this->pdo->prepare(
                "UPDATE fin_invoices SET credited_paise = ?, status = CASE WHEN ? = total_paise THEN 'credited' ELSE 'partially_credited' END WHERE id = ?",
            )->execute([$credited, $credited, (int) $inv['id']]);

            $doc = $this->queries->creditNote($creditNoteId);
            $this->invoices->storeHtml('credit_note_id', $creditNoteId, DocumentHtml::render($doc));
            $this->audit->record($actor, 'credit_note.issued', 'credit_note', $creditNoteId, ['status' => 'pending_approval'], [
                'document_id' => $doc['document_id'], 'doc_no' => $doc['doc_no'], 'doc_date' => $doc['doc_date'], 'cmp_id' => $doc['cmp_id'],
                'invoice_id' => (int) $inv['id'], 'invoice_doc_no' => $inv['doc_no'], 'total_paise' => $doc['total_paise'],
                'requested_by' => $cn['requested_by'],
            ]);

            return $doc;
        });
        $this->invoices->storePdf('credit_note', $creditNoteId);

        return $this->queries->creditNote($creditNoteId);
    }

    /**
     * @param array{actor:string, name?:?string, ip?:?string} $actor
     * @return array<string,mixed>
     */
    public function reject(int $creditNoteId, mixed $reason, array $actor): array
    {
        $reason = InvoiceInput::text($reason, 500);
        if ($reason === '') {
            throw ApiException::coded(422, 'validation_failed', 'A reason is required.', ['reason' => 'Required.']);
        }

        return $this->tx(function () use ($creditNoteId, $reason, $actor): array {
            $this->lockPending($creditNoteId);
            $this->pdo->prepare("UPDATE fin_credit_notes SET status = 'rejected', rejected_by = ?, rejected_at = now(), reject_reason = ? WHERE id = ?")
                ->execute([$actor['actor'], $reason, $creditNoteId]);
            $this->audit->record($actor, 'credit_note.rejected', 'credit_note', $creditNoteId, ['status' => 'pending_approval'], ['status' => 'rejected', 'reason' => $reason]);

            return $this->queries->creditNote($creditNoteId);
        });
    }

    /**
     * @param array<int, array{taxable:int, cgst:int, sgst:int, igst:int, total:int}> $remaining
     * @return list<array<string,mixed>>
     */
    private function creditLines(int $invoiceId, string $kind, mixed $input, array $remaining, string $supplyType): array
    {
        $stmt = $this->pdo->prepare('SELECT id, line_no, description, sac, gst_rate FROM fin_invoice_lines WHERE invoice_id = ? ORDER BY line_no');
        $stmt->execute([$invoiceId]);
        $invLines = [];
        foreach ($stmt->fetchAll() as $l) {
            $invLines[(int) $l['id']] = $l;
        }

        $wanted = [];
        if ($kind === 'full') {
            foreach ($remaining as $lineId => $r) {
                if ($r['total'] > 0) {
                    $wanted[$lineId] = $r['total'];
                }
            }
            if ($wanted === []) {
                throw ApiException::coded(409, 'nothing_to_credit', 'This invoice is already fully credited (or credit notes for the rest are awaiting approval).');
            }
        } else {
            if (!is_array($input) || !array_is_list($input) || $input === []) {
                throw ApiException::coded(422, 'validation_failed', 'Give the amount to credit per line.', ['lines' => 'At least one line with an amount.']);
            }
            $errors = [];
            foreach ($input as $i => $row) {
                $lineId = is_array($row) ? ($row['invoice_line_id'] ?? null) : null;
                $lineId = is_int($lineId) ? $lineId : (is_string($lineId) && ctype_digit($lineId) ? (int) $lineId : 0);
                $amount = is_array($row) ? Money::parse($row['amount'] ?? null) : null;
                if (!isset($invLines[$lineId])) {
                    $errors["lines[{$i}].invoice_line_id"] = 'Not a line of this invoice.';
                    continue;
                }
                if ($amount === null || $amount <= 0) {
                    $errors["lines[{$i}].amount"] = 'An amount in rupees including GST, more than zero.';
                    continue;
                }
                if (isset($wanted[$lineId])) {
                    $errors["lines[{$i}].invoice_line_id"] = 'Each line once.';
                    continue;
                }
                $left = $remaining[$lineId]['total'] ?? 0;
                if ($amount > $left) {
                    $errors["lines[{$i}].amount"] = 'At most ' . Money::format($left) . ' is left to credit on this line.';
                    continue;
                }
                $wanted[$lineId] = $amount;
            }
            InvoiceInput::throwIf($errors, 'The credit amounts are invalid.');
        }

        $out = [];
        foreach ($wanted as $lineId => $gross) {
            $l = $invLines[$lineId];
            $r = $remaining[$lineId];
            if ($gross === $r['total']) {
                $parts = ['taxable' => $r['taxable'], 'cgst' => $r['cgst'], 'sgst' => $r['sgst'], 'igst' => $r['igst'], 'total' => $r['total']];
            } else {
                $parts = self::splitGross($gross, Money::parseRate((string) $l['gst_rate'], 9999) ?? 0, $supplyType === 'intra_state', $r);
            }
            $out[] = $parts + ['invoice_line_id' => $lineId, 'description' => $l['description'], 'sac' => $l['sac'], 'gst_rate' => $l['gst_rate']];
        }

        return $out;
    }

    /**
     * A GST-inclusive credit amount split at the line's rate, kept within every remaining head.
     *
     * @param array{taxable:int, cgst:int, sgst:int, igst:int, total:int} $remaining
     * @return array{taxable:int, cgst:int, sgst:int, igst:int, total:int}
     */
    public static function splitGross(int $gross, int $rateBp, bool $intra, array $remaining): array
    {
        $calc = GstCalculator::line($gross, $rateBp, GstCalculator::INCLUSIVE, $intra);
        $tax  = min($calc['tax'], $remaining['cgst'] + $remaining['sgst'] + $remaining['igst']);
        if ($gross - $tax > $remaining['taxable']) {
            $tax = $gross - $remaining['taxable'];
        }
        [$cgst, $sgst, $igst] = GstCalculator::split($tax, $intra);
        if ($intra) {
            if ($cgst > $remaining['cgst']) {
                $cgst = $remaining['cgst'];
                $sgst = $tax - $cgst;
            } elseif ($sgst > $remaining['sgst']) {
                $sgst = $remaining['sgst'];
                $cgst = $tax - $sgst;
            }
        }

        return ['taxable' => $gross - $tax, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'total' => $gross];
    }

    private function assertWithinDeadline(string $fy): void
    {
        $deadline = self::deadline($fy);
        if ($this->today() > $deadline) {
            throw ApiException::coded(422, 'credit_note_deadline_passed',
                "A credit note for an FY {$fy} invoice had to be issued by " . DocumentHtml::dmy($deadline) . ' (s.34 CGST Act).');
        }
    }

    /** @return array<string,mixed> */
    private function lockInvoice(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fin_invoices WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Invoice not found.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function lockPending(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fin_credit_notes WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Credit note not found.');
        }
        if ($row['status'] !== 'pending_approval') {
            throw ApiException::coded(409, 'not_pending', 'This credit note is already ' . str_replace('_', ' ', (string) $row['status']) . '.');
        }

        return $row;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $out = $fn();
            $this->pdo->commit();

            return $out;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
