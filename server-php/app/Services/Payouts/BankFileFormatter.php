<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Support\Money;

/**
 * Bank bulk-upload files (pure). A person downloads the file and uploads it to
 * the bank's corporate portal; Finance never moves money itself.
 *
 * generic_neft_csv — Finance's own documented layout, header row, one line per payee:
 *   Payment Mode, Beneficiary Name, Beneficiary Account Number, IFSC, Amount, Debit Account Number,
 *   Value Date (DD/MM/YYYY), Narration, Customer Reference (= Engage item_ref, used to match the
 *   bank's response file back to the item)
 *
 * icici_hdfc_style_csv — the column layout typical of ICICI CIB / HDFC ENet bulk vendor-payment
 *   templates: PYMT_PROD_TYPE_CODE (PAB_VENDOR), PYMT_MODE, DEBIT_ACC_NO, BNF_NAME, BENE_ACC_NO,
 *   BENE_IFSC, AMOUNT, DEBIT_NARR, CREDIT_NARR, MOBILE_NUM, EMAIL_ID, REMARK, PYMT_DATE (DD-MM-YYYY),
 *   REF_NO. CONFIRM THE FORMAT WITH YOUR BANK before the first upload — banks publish their own
 *   templates per corporate account and they change column order, date format and codes.
 *
 * Every text cell is stripped to characters banks accept, and a leading = + - @ is neutralised so a
 * spreadsheet never evaluates a payee name as a formula.
 */
final class BankFileFormatter
{
    public const FORMATS = [
        'generic_neft_csv' => [
            'label'          => 'Generic NEFT/IMPS CSV',
            'confirm_format' => false,
        ],
        'icici_hdfc_style_csv' => [
            'label'          => 'ICICI/HDFC-style bulk CSV — confirm format with your bank',
            'confirm_format' => true,
        ],
    ];

    /**
     * @param list<array{item_ref:string, holder:string, account:string, ifsc:string, net:int}> $rows
     * @param array{format:string, payment_mode:string, debit_account:string, value_date:string,
     *              narration_prefix:string, batch_ref:string} $ctx
     * @return array{filename:string, content:string, sha256:string, item_count:int, total:int}
     */
    public static function build(array $rows, array $ctx): array
    {
        if (!isset(self::FORMATS[$ctx['format']])) {
            throw new \InvalidArgumentException('Unknown bank file format: ' . $ctx['format']);
        }
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d', $ctx['value_date']) ?: new \DateTimeImmutable('today');
        $lines = [];
        $total = 0;

        if ($ctx['format'] === 'generic_neft_csv') {
            $lines[] = self::csv(['Payment Mode', 'Beneficiary Name', 'Beneficiary Account Number', 'IFSC', 'Amount',
                'Debit Account Number', 'Value Date', 'Narration', 'Customer Reference']);
            foreach ($rows as $r) {
                $total  += $r['net'];
                $lines[] = self::csv([
                    $ctx['payment_mode'],
                    self::text($r['holder'], 50),
                    $r['account'],
                    $r['ifsc'],
                    Money::format($r['net']),
                    $ctx['debit_account'],
                    $date->format('d/m/Y'),
                    self::text(trim($ctx['narration_prefix'] . ' ' . $r['item_ref']), 30),
                    self::text($r['item_ref'], 40),
                ]);
            }
        } else {
            $lines[] = self::csv(['PYMT_PROD_TYPE_CODE', 'PYMT_MODE', 'DEBIT_ACC_NO', 'BNF_NAME', 'BENE_ACC_NO', 'BENE_IFSC',
                'AMOUNT', 'DEBIT_NARR', 'CREDIT_NARR', 'MOBILE_NUM', 'EMAIL_ID', 'REMARK', 'PYMT_DATE', 'REF_NO']);
            foreach ($rows as $r) {
                $total  += $r['net'];
                $lines[] = self::csv([
                    'PAB_VENDOR',
                    $ctx['payment_mode'],
                    $ctx['debit_account'],
                    self::text($r['holder'], 50),
                    $r['account'],
                    $r['ifsc'],
                    Money::format($r['net']),
                    self::text(trim($ctx['narration_prefix'] . ' ' . $ctx['batch_ref']), 30),
                    self::text(trim($ctx['narration_prefix'] . ' ' . $r['item_ref']), 30),
                    '',
                    '',
                    '',
                    $date->format('d-m-Y'),
                    self::text($r['item_ref'], 40),
                ]);
            }
        }

        $content = implode("\r\n", $lines) . "\r\n";
        $safeRef = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ctx['batch_ref']) ?? 'batch';

        return [
            'filename'   => sprintf('payout-%s-%s-%s.csv', $safeRef, $ctx['format'], $date->format('Ymd')),
            'content'    => $content,
            'sha256'     => hash('sha256', $content),
            'item_count' => count($rows),
            'total'      => $total,
        ];
    }

    /** Bank-safe text: letters, digits, space and . , & - / ( ); trimmed to $max. */
    public static function text(string $value, int $max): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 .,&\-\/()]+/', ' ', $value) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return substr($clean, 0, $max);
    }

    /** @param list<string> $cells */
    public static function csv(array $cells): string
    {
        return implode(',', array_map(static function (string $c): string {
            if ($c !== '' && in_array($c[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $c = "'" . $c;
            }
            if (strpbrk($c, ",\"\r\n") !== false) {
                $c = '"' . str_replace('"', '""', $c) . '"';
            }

            return $c;
        }, $cells));
    }
}
