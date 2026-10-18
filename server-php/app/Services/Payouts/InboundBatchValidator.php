<?php
declare(strict_types=1);

namespace App\Services\Payouts;

use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Money;

/**
 * Validates and normalises Engage's weekly payout batch (contract:
 * SELLER-CONTRACTS "Weekly payouts"):
 *
 *   {batch_ref, period_start, period_end, currency:"INR",
 *    items:[{item_ref, seller_code,
 *            payee:{legal_name, pan, gstin|null, entity_type, bank:{account_holder, account_number, ifsc}},
 *            gross_amount, entries:[entry ids]}]}
 *
 * Every problem is reported at once (errors keyed by JSON path), and nothing is
 * stored unless the whole batch is valid.
 */
final class InboundBatchValidator
{
    public const MAX_ITEMS     = 2000;
    public const ENTITY_TYPES  = ['individual', 'proprietorship', 'partnership', 'llp', 'company'];
    private const REF          = '/^[A-Za-z0-9][A-Za-z0-9._:\-\/]{0,79}$/';
    private const PAN          = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';
    private const GSTIN        = '/^[0-3][0-9][A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';
    private const IFSC         = '/^[A-Z]{4}0[A-Z0-9]{6}$/';
    private const ACCOUNT      = '/^[0-9A-Z]{6,20}$/';

    /**
     * @param array<string,mixed> $body
     * @return array{batch_ref:string, period_start:string, period_end:string, currency:string, items:list<array<string,mixed>>}
     */
    public static function validate(array $body): array
    {
        $errors = [];

        $batchRef = is_string($body['batch_ref'] ?? null) ? trim($body['batch_ref']) : '';
        if (!preg_match(self::REF, $batchRef)) {
            $errors['batch_ref'] = 'Required: 1–80 characters (letters, digits, . _ : - /).';
        }
        $start = $body['period_start'] ?? null;
        $end   = $body['period_end'] ?? null;
        if (!FiscalYear::isDate($start)) {
            $errors['period_start'] = 'Required: YYYY-MM-DD.';
        }
        if (!FiscalYear::isDate($end)) {
            $errors['period_end'] = 'Required: YYYY-MM-DD.';
        }
        if (FiscalYear::isDate($start) && FiscalYear::isDate($end) && $start > $end) {
            $errors['period_end'] = 'Must not be before period_start.';
        }
        if (($body['currency'] ?? null) !== 'INR') {
            $errors['currency'] = 'Must be "INR".';
        }

        $items = $body['items'] ?? null;
        $out   = [];
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            $errors['items'] = 'Required: a non-empty list.';
        } elseif (count($items) > self::MAX_ITEMS) {
            $errors['items'] = 'At most ' . self::MAX_ITEMS . ' items per batch.';
        } else {
            $seen = [];
            foreach ($items as $i => $item) {
                $p = "items[{$i}]";
                if (!is_array($item)) {
                    $errors[$p] = 'Must be an object.';
                    continue;
                }
                $norm = self::item($item, $p, $errors);
                if ($norm === null) {
                    continue;
                }
                if (isset($seen[$norm['item_ref']])) {
                    $errors["{$p}.item_ref"] = 'Duplicate item_ref in this batch.';
                    continue;
                }
                $seen[$norm['item_ref']] = true;
                $out[] = $norm;
            }
        }

        if ($errors !== []) {
            throw ApiException::validation('The payout batch is invalid; nothing was stored.', $errors);
        }

        return [
            'batch_ref'    => $batchRef,
            'period_start' => (string) $start,
            'period_end'   => (string) $end,
            'currency'     => 'INR',
            'items'        => $out,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,string> $errors
     * @return array<string,mixed>|null
     */
    private static function item(array $item, string $p, array &$errors): ?array
    {
        $before = count($errors);
        $str    = static fn (mixed $v): string => is_string($v) ? trim($v) : '';

        $itemRef    = $str($item['item_ref'] ?? null);
        $sellerCode = strtoupper($str($item['seller_code'] ?? null));
        if (!preg_match(self::REF, $itemRef)) {
            $errors["{$p}.item_ref"] = 'Required: 1–80 characters (letters, digits, . _ : - /).';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9_\-]{0,39}$/', $sellerCode)) {
            $errors["{$p}.seller_code"] = 'Required, e.g. SLR-000123.';
        }

        $gross = Money::parse($item['gross_amount'] ?? null);
        if ($gross === null || $gross <= 0) {
            $errors["{$p}.gross_amount"] = 'Required: a positive amount string such as "1234.50".';
        }

        $payee = is_array($item['payee'] ?? null) ? $item['payee'] : null;
        $bank  = is_array($payee['bank'] ?? null) ? $payee['bank'] : null;
        if ($payee === null) {
            $errors["{$p}.payee"] = 'Required.';
        }
        $legalName  = $str($payee['legal_name'] ?? null);
        $pan        = strtoupper($str($payee['pan'] ?? null));
        $gstinRaw   = $payee['gstin'] ?? null;
        $gstin      = $gstinRaw === null || $gstinRaw === '' ? null : strtoupper($str($gstinRaw));
        $entityType = strtolower($str($payee['entity_type'] ?? null));
        if ($payee !== null) {
            if ($legalName === '' || mb_strlen($legalName) > 200) {
                $errors["{$p}.payee.legal_name"] = 'Required (at most 200 characters).';
            }
            if (!preg_match(self::PAN, $pan)) {
                $errors["{$p}.payee.pan"] = 'Required: a valid PAN (AAAAA9999A). TDS cannot be computed without it.';
            }
            if ($gstin !== null && !preg_match(self::GSTIN, $gstin)) {
                $errors["{$p}.payee.gstin"] = 'Not a valid GSTIN (or send null).';
            } elseif ($gstin !== null && preg_match(self::PAN, $pan) && substr($gstin, 2, 10) !== $pan) {
                $errors["{$p}.payee.gstin"] = 'GSTIN does not belong to this PAN.';
            }
            if (!in_array($entityType, self::ENTITY_TYPES, true)) {
                $errors["{$p}.payee.entity_type"] = 'One of: ' . implode(', ', self::ENTITY_TYPES) . '.';
            }
            if ($bank === null) {
                $errors["{$p}.payee.bank"] = 'Required.';
            }
        }
        $holder  = $str($bank['account_holder'] ?? null);
        $account = strtoupper(preg_replace('/\s+/', '', $str($bank['account_number'] ?? null)) ?? '');
        $ifsc    = strtoupper($str($bank['ifsc'] ?? null));
        if ($bank !== null) {
            if ($holder === '' || mb_strlen($holder) > 200) {
                $errors["{$p}.payee.bank.account_holder"] = 'Required (at most 200 characters).';
            }
            if (!preg_match(self::ACCOUNT, $account)) {
                $errors["{$p}.payee.bank.account_number"] = 'Required: 6–20 letters or digits.';
            }
            if (!preg_match(self::IFSC, $ifsc)) {
                $errors["{$p}.payee.bank.ifsc"] = 'Required: a valid IFSC (e.g. HDFC0001234).';
            }
        }

        $entries = $item['entries'] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) {
            $errors["{$p}.entries"] = 'Must be a list of entry ids.';
            $entries = [];
        }
        foreach ($entries as $e) {
            if (!is_int($e) && !(is_string($e) && $e !== '' && strlen($e) <= 80)) {
                $errors["{$p}.entries"] = 'Entry ids must be integers or short strings.';
                break;
            }
        }

        if (count($errors) !== $before) {
            return null;
        }

        return [
            'item_ref'    => $itemRef,
            'seller_code' => $sellerCode,
            'gross'       => $gross,
            'entries'     => array_values($entries),
            'payee'       => [
                'legal_name'  => $legalName,
                'pan'         => $pan,
                'gstin'       => $gstin,
                'entity_type' => $entityType,
                'bank'        => ['account_holder' => $holder, 'account_number' => $account, 'ifsc' => $ifsc],
            ],
        ];
    }
}
