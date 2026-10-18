<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

/**
 * The printable tax invoice / credit note (A4 HTML, self-contained: no external fonts, images or scripts, so the
 * PDF service needs no network and the stored HTML renders the same way years later).
 *
 * Input is the document as DocumentQueries::detail() returns it, so the PDF shows exactly what the API answers:
 * seller block, buyer block, place of supply, SAC and the tax split per line, totals, amount in words, the Paid
 * block (invoices), the original invoice (credit notes), the signatory line and "This is a computer-generated …".
 * Every value is HTML-escaped.
 */
final class DocumentHtml
{
    /** @param array<string,mixed> $doc */
    public static function render(array $doc): string
    {
        $isCn   = ($doc['doc_type'] ?? '') === 'credit_note';
        $title  = $isCn ? 'Credit Note' : 'Tax Invoice';
        $seller = (array) $doc['seller'];
        $buyer  = (array) $doc['buyer'];
        $intra  = ($doc['supply_type'] ?? '') === 'intra_state';
        $e      = static fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $sellerAddress = array_filter([
            $seller['address_line1'] ?? null,
            $seller['address_line2'] ?? null,
            trim(($seller['city'] ?? '') . ' ' . ($seller['pincode'] ?? '')),
        ], static fn ($l) => is_string($l) && trim($l) !== '');
        $sellerContact = array_filter([
            isset($seller['email']) && $seller['email'] !== null ? 'Email: ' . $seller['email'] : null,
            isset($seller['phone']) && $seller['phone'] !== null ? 'Phone: ' . $seller['phone'] : null,
        ]);

        $meta = [
            [$isCn ? 'Credit note no.' : 'Invoice no.', $doc['doc_no']],
            [$isCn ? 'Credit note date' : 'Invoice date', self::dmy($doc['doc_date'] ?? null)],
            ['Place of supply', $doc['place_of_supply']['code'] . ' - ' . $doc['place_of_supply']['name']],
            ['Reverse charge', !empty($doc['reverse_charge']) ? 'Yes' : 'No'],
        ];
        if ($isCn && is_array($doc['original_invoice'] ?? null)) {
            $meta[] = ['Against invoice', $doc['original_invoice']['doc_no'] . ' dated ' . self::dmy($doc['original_invoice']['doc_date'])];
        }

        $head = '<tr><th class="c">#</th><th>Description</th><th class="c">SAC</th><th class="r">Qty</th><th class="r">Taxable value</th>'
            . ($intra ? '<th class="r">CGST</th><th class="r">SGST</th>' : '<th class="r">IGST</th>')
            . '<th class="r">Total</th></tr>';
        $rows = '';
        foreach ((array) $doc['lines'] as $l) {
            $period = '';
            if (!empty($l['period_start']) || !empty($l['period_end'])) {
                $period = '<div class="sub">Period: ' . $e(self::dmy($l['period_start'] ?? null)) . ' to ' . $e(self::dmy($l['period_end'] ?? null)) . '</div>';
            }
            $code = !empty($l['item_code']) ? '<div class="sub">' . $e($l['item_code']) . '</div>' : '';
            $tax  = $intra
                ? '<td class="r">' . $e(self::inr((int) $l['cgst_paise'])) . '<div class="sub">@ ' . $e(self::rate($l['cgst_rate'])) . '</div></td>'
                  . '<td class="r">' . $e(self::inr((int) $l['sgst_paise'])) . '<div class="sub">@ ' . $e(self::rate($l['sgst_rate'])) . '</div></td>'
                : '<td class="r">' . $e(self::inr((int) $l['igst_paise'])) . '<div class="sub">@ ' . $e(self::rate($l['igst_rate'])) . '</div></td>';
            $rows .= '<tr><td class="c">' . (int) $l['line_no'] . '</td><td>' . $e($l['description']) . $code . $period . '</td>'
                . '<td class="c">' . $e($l['sac']) . '</td><td class="r">' . $e($l['quantity'] ?? '') . '</td>'
                . '<td class="r">' . $e(self::inr((int) $l['taxable_paise'])) . '</td>' . $tax
                . '<td class="r">' . $e(self::inr((int) $l['total_paise'])) . '</td></tr>';
        }

        $t = (array) $doc['totals'];
        $totals = '<tr><td>Taxable value</td><td class="r">' . $e(self::inr((int) $t['taxable_paise'])) . '</td></tr>';
        if ($intra) {
            $totals .= '<tr><td>CGST</td><td class="r">' . $e(self::inr((int) $t['cgst_paise'])) . '</td></tr>'
                . '<tr><td>SGST</td><td class="r">' . $e(self::inr((int) $t['sgst_paise'])) . '</td></tr>';
        } else {
            $totals .= '<tr><td>IGST</td><td class="r">' . $e(self::inr((int) $t['igst_paise'])) . '</td></tr>';
        }
        $totals .= '<tr class="grand"><td>' . ($isCn ? 'Total credit' : 'Invoice total') . '</td><td class="r">' . $e(self::inr((int) $t['total_paise'])) . '</td></tr>';

        $paid = '';
        if (!$isCn && is_array($doc['payment'] ?? null)) {
            $p = $doc['payment'];
            $via = $p['gateway'] ? $p['gateway'] . ' (' . $p['method_label'] . ')' : $p['method_label'];
            $paid = '<div class="paid"><span class="stamp">PAID</span> ' . $e(self::inr((int) $t['total_paise'])) . ' received on '
                . $e(self::dmy($p['paid_on'])) . ' by ' . $e($via) . ', reference ' . $e($p['reference']) . '.</div>';
        }
        $reason = $isCn && !empty($doc['reason']) ? '<p class="reason"><strong>Reason:</strong> ' . $e($doc['reason']) . '</p>' : '';

        $buyerGstin = $buyer['gstin'] ? 'GSTIN: ' . $buyer['gstin'] : 'Unregistered (no GSTIN)';
        $signatory  = trim(($seller['signatory_name'] ?? '') . (!empty($seller['signatory_designation']) ? ', ' . $seller['signatory_designation'] : ''));
        $legal      = $seller['legal_name'] . (!empty($seller['trade_name']) ? ' (' . $seller['trade_name'] . ')' : '');

        $metaHtml = '';
        foreach ($meta as [$k, $v]) {
            $metaHtml .= '<tr><td>' . $e($k) . '</td><td><strong>' . $e($v) . '</strong></td></tr>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $e($title . ' ' . $doc['doc_no']) . '</title><style>'
            . '@page{size:A4;margin:12mm}*{box-sizing:border-box}body{margin:0;font-family:"DejaVu Sans","Noto Sans",Arial,sans-serif;font-size:10.5px;color:#111}'
            . 'h1{font-size:18px;margin:0 0 2px;letter-spacing:.04em;text-transform:uppercase}.copy{font-size:9px;color:#555}'
            . '.top{display:flex;justify-content:space-between;gap:16px;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:10px}'
            . '.seller strong{font-size:13px}.meta td{padding:1px 6px 1px 0;vertical-align:top}.boxes{display:flex;gap:12px;margin-bottom:10px}'
            . '.box{flex:1;border:1px solid #bbb;padding:6px 8px}.box h2{font-size:9px;text-transform:uppercase;color:#555;margin:0 0 3px;letter-spacing:.06em}'
            . 'table.lines{width:100%;border-collapse:collapse;margin-bottom:8px}table.lines th,table.lines td{border:1px solid #bbb;padding:4px 5px;vertical-align:top}'
            . 'table.lines th{background:#f1f1f1;font-size:9.5px}.r{text-align:right;white-space:nowrap}.c{text-align:center}.sub{font-size:8.5px;color:#555}'
            . '.bottom{display:flex;justify-content:space-between;gap:16px}.words{flex:1}.totals{width:42%;border-collapse:collapse}'
            . '.totals td{padding:3px 5px;border-bottom:1px solid #ddd}.totals .grand td{font-weight:bold;font-size:12px;border-top:2px solid #111;border-bottom:none}'
            . '.paid{margin-top:10px;padding:6px 8px;border:1px solid #2e7d32;color:#1b5e20}.stamp{font-weight:bold;letter-spacing:.1em}'
            . '.reason{margin:6px 0}.sign{margin-top:28px;text-align:right}.sign .line{margin-top:30px}.foot{margin-top:18px;font-size:8.5px;color:#555;text-align:center}'
            . '</style></head><body>'
            . '<div class="top"><div class="seller"><strong>' . $e($legal) . '</strong><br>' . implode('<br>', array_map($e, $sellerAddress))
            . '<br>State: ' . $e($seller['state_name'] ?? '') . ' (' . $e($seller['state_code'] ?? '') . ')'
            . '<br>GSTIN: <strong>' . $e($seller['gstin']) . '</strong> &middot; PAN: ' . $e($seller['pan'])
            . ($sellerContact !== [] ? '<br>' . implode(' &middot; ', array_map($e, $sellerContact)) : '') . '</div>'
            . '<div><h1>' . $e($title) . '</h1><div class="copy">Original for recipient</div><table class="meta">' . $metaHtml . '</table></div></div>'
            . '<div class="boxes"><div class="box"><h2>' . ($isCn ? 'Issued to' : 'Billed to') . '</h2><strong>' . $e($buyer['legal_name']) . '</strong><br>'
            . nl2br($e($buyer['address'])) . ($buyer['pincode'] ? ' &ndash; ' . $e($buyer['pincode']) : '')
            . '<br>State: ' . $e($buyer['state_name']) . ' (' . $e($buyer['state_code']) . ')<br>' . $e($buyerGstin)
            . ($buyer['email'] ? '<br>' . $e($buyer['email']) : '') . '</div></div>'
            . $reason
            . '<table class="lines"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="bottom"><div class="words"><strong>Amount in words:</strong><br>' . $e($doc['amount_in_words']) . '</div>'
            . '<table class="totals">' . $totals . '</table></div>'
            . $paid
            . '<div class="sign">For <strong>' . $e($seller['legal_name']) . '</strong><div class="line">Authorised signatory' . ($signatory !== '' ? ': ' . $e($signatory) : '') . '</div></div>'
            . '<div class="foot">This is a computer-generated ' . ($isCn ? 'credit note' : 'invoice') . '.</div>'
            . '</body></html>';
    }

    /** ₹ with Indian digit grouping: 123456789 paise → "₹12,34,567.89". */
    public static function inr(int $paise): string
    {
        $neg   = $paise < 0;
        $paise = abs($paise);
        $whole = (string) intdiv($paise, 100);
        $last3 = substr($whole, -3);
        $rest  = substr($whole, 0, -3);
        $grp   = $rest === '' ? $last3 : (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3;

        return ($neg ? '-' : '') . '₹' . $grp . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /** YYYY-MM-DD → dd/mm/yyyy. */
    public static function dmy(?string $date): string
    {
        if ($date === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return '';
        }

        return "{$m[3]}/{$m[2]}/{$m[1]}";
    }

    /** "9.00" → "9%", "0.125" → "0.125%". */
    public static function rate(mixed $rate): string
    {
        $s = (string) $rate;
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return ($s === '' ? '0' : $s) . '%';
    }
}
