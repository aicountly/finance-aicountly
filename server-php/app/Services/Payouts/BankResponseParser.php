<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Support\FiscalYear;

/**
 * Reads the bank's response / status file (CSV with a header row) into
 * per-line results (pure). Column names vary by bank, so headers are matched
 * loosely (case, spaces and punctuation ignored):
 *
 *   reference  Customer Reference | CUST_REF_NO | REF_NO | Reference | item_ref | Instruction Reference Number
 *   utr        UTR | UTR No | UTR Number | Bank Reference | RRN | Transaction Reference
 *   status     Status | Transaction Status | Payment Status        (optional)
 *   date       Paid At | Payment Date | Value Date | Transaction Date | PYMT_DATE   (optional)
 *   reason     Reason | Remarks | Failure Reason | Reject Reason | Error Description (optional)
 *
 * Outcome per line: paid (success + UTR), failed (failure status), or skip with a reason.
 * A success line without a UTR is skipped — nothing is ever marked paid without one.
 */
final class BankResponseParser
{
    private const COLUMNS = [
        'reference' => ['customerreference', 'custrefno', 'customerrefno', 'refno', 'reference', 'itemref',
            'customerreferencenumber', 'instructionreferencenumber', 'paymentreference'],
        'utr'       => ['utr', 'utrno', 'utrnumber', 'bankreference', 'bankreferenceno', 'bankreferencenumber',
            'rrn', 'transactionreference', 'transactionrefno'],
        'status'    => ['status', 'transactionstatus', 'paymentstatus', 'txnstatus'],
        'date'      => ['paidat', 'paymentdate', 'valuedate', 'transactiondate', 'pymtdate', 'date', 'creditdate'],
        'reason'    => ['reason', 'remarks', 'remark', 'failurereason', 'rejectreason', 'errordescription',
            'statusdescription'],
    ];

    private const SUCCESS = ['success', 'successful', 'paid', 'processed', 'completed', 'credited', 'executed', 's'];
    private const FAILURE = ['failed', 'failure', 'rejected', 'returned', 'error', 'f', 'r', 'cancelled'];

    public const UTR_PATTERN = '/^[A-Za-z0-9]{6,35}$/';

    /**
     * @return array{columns:array<string,?string>, rows:list<array{line:int, reference:string, outcome:string,
     *               utr:?string, paid_at:?string, reason:?string}>}
     */
    public static function parse(string $csv): array
    {
        $csv   = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l) => trim($l) !== ''));
        if ($lines === []) {
            throw new \InvalidArgumentException('The file is empty.');
        }

        $header = array_map(static fn ($h) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $h)) ?? '', str_getcsv($lines[0], ',', '"', ''));
        $index  = [];
        $names  = [];
        foreach (self::COLUMNS as $key => $aliases) {
            $index[$key] = null;
            $names[$key] = null;
            foreach ($aliases as $alias) {
                $pos = array_search($alias, $header, true);
                if ($pos !== false) {
                    $index[$key] = (int) $pos;
                    $names[$key] = $alias;
                    break;
                }
            }
        }
        if ($index['reference'] === null) {
            throw new \InvalidArgumentException('No reference column found (expected e.g. "Customer Reference" or "REF_NO" holding the item reference).');
        }
        if ($index['utr'] === null && $index['status'] === null) {
            throw new \InvalidArgumentException('No UTR or status column found.');
        }

        $rows = [];
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            $cells = str_getcsv($lines[$i], ',', '"', '');
            $get   = static fn (?int $pos): string => $pos === null ? '' : ltrim(trim((string) ($cells[$pos] ?? '')), "'");
            $ref    = $get($index['reference']);
            $utr    = $get($index['utr']);
            $status = strtolower($get($index['status']));
            $reason = $get($index['reason']);
            $date   = self::date($get($index['date']));
            $row    = ['line' => $i + 1, 'reference' => $ref, 'outcome' => 'skip', 'utr' => null, 'paid_at' => $date, 'reason' => null];

            if ($ref === '') {
                $row['reason'] = 'No reference on this line.';
            } elseif (in_array($status, self::FAILURE, true)) {
                $row['outcome'] = 'failed';
                $row['reason']  = $reason !== '' ? $reason : 'Bank reported the transfer as ' . $status . '.';
            } elseif ($index['status'] !== null && !in_array($status, self::SUCCESS, true)) {
                $row['reason'] = $status === '' ? 'No status on this line.' : "Status \"{$status}\" is neither success nor failure (pending?) — left as is.";
            } elseif ($utr === '') {
                $row['reason'] = 'Success without a UTR — not marked paid.';
            } elseif (!preg_match(self::UTR_PATTERN, $utr)) {
                $row['reason'] = 'UTR is not 6–35 letters/digits.';
            } else {
                $row['outcome'] = 'paid';
                $row['utr']     = strtoupper($utr);
            }
            $rows[] = $row;
        }

        return ['columns' => $names, 'rows' => $rows];
    }

    /** Y-m-d, d/m/Y, d-m-Y, d.m.Y (optionally followed by a time) → Y-m-d, or null. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            $iso = "{$m[1]}-{$m[2]}-{$m[3]}";
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $value, $m)) {
            $iso = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } else {
            return null;
        }

        return FiscalYear::isDate($iso) ? $iso : null;
    }
}
