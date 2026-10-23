<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Gstin;
use App\Support\Money;

/**
 * Validates and normalises what an invoice is issued for: the buyer snapshot and the lines (contract §1.1
 * fin_checkout_orders shape). Plan, licence and product types are opaque strings (item_code, item_kind,
 * description): Finance only needs SAC, rate, amounts and periods.
 *
 * Every problem is reported at once (422 validation_failed, errors keyed buyer.gstin, lines[0].sac, …).
 */
final class InvoiceInput
{
    public const MAX_LINES = 50;

    /**
     * $requireAddress false (an Aicountly Academy student below the Rule 46(e) value, see InvoiceService) makes the
     * billing address and PIN optional: an empty address comes back as '' and an empty PIN as ''.
     *
     * @param array<string,mixed> $buyer
     * @param array<string,string> $errors collected problems (by reference)
     * @return array{legal_name:string, gstin:?string, state_code:string, address:string, pincode:string, country:string, email:?string}
     */
    public static function buyer(array $buyer, array &$errors, string $prefix = 'buyer.', bool $requireAddress = true): array
    {
        $name = self::text($buyer['legal_name'] ?? null, 200);
        if ($name === '') {
            $errors[$prefix . 'legal_name'] = 'The buyer\'s legal name is required.';
        }
        $gstin = Gstin::normalise($buyer['gstin'] ?? null);
        if ($gstin !== '' && ($p = Gstin::problem($gstin)) !== null) {
            $errors[$prefix . 'gstin'] = $p;
        }
        $state = trim(is_scalar($buyer['state_code'] ?? null) ? (string) $buyer['state_code'] : '');
        if (preg_match('/^\d$/', $state)) {
            $state = '0' . $state;
        }
        if (!Gstin::isStateCode($state)) {
            $errors[$prefix . 'state_code'] = 'A GST state code (two digits, e.g. 27 for Maharashtra).';
        } elseif ($gstin !== '' && !isset($errors[$prefix . 'gstin']) && Gstin::stateOf($gstin) !== $state) {
            $errors[$prefix . 'state_code'] = 'Must be the GSTIN\'s state (' . Gstin::stateLabel(Gstin::stateOf($gstin)) . '): the place of supply follows the GSTIN.';
        }
        $address = self::text($buyer['address'] ?? null, 500, true);
        if ($address === '' && $requireAddress) {
            $errors[$prefix . 'address'] = 'The billing address is required.';
        }
        $pincode = trim(is_scalar($buyer['pincode'] ?? null) ? (string) $buyer['pincode'] : '');
        if (($pincode !== '' || $requireAddress) && !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            $errors[$prefix . 'pincode'] = 'A six-digit PIN code.';
        }
        $country = strtoupper(trim(is_string($buyer['country'] ?? null) ? $buyer['country'] : 'IN')) ?: 'IN';
        if ($country !== 'IN') {
            $errors[$prefix . 'country'] = 'Only buyers in India are invoiced for now (exports are not supported).';
        }
        $email = self::text($buyer['email'] ?? null, 254);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[$prefix . 'email'] = 'A valid email address.';
        }

        return [
            'legal_name' => $name,
            'gstin'      => $gstin === '' ? null : $gstin,
            'state_code' => $state,
            'address'    => $address,
            'pincode'    => $pincode,
            'country'    => $country,
            'email'      => $email === '' ? null : strtolower($email),
        ];
    }

    /**
     * @param mixed $lines
     * @param array<string,string> $errors
     * @return list<array{item_code:string, item_kind:string, description:string, sac:string, gst_rate:string, rate_bp:int,
     *                    price_basis:string, period_start:?string, period_end:?string, quantity:string, amount_paise:int,
     *                    list_amount_paise:?int, discount_paise:?int, discount_ref:?string}>
     */
    public static function lines(mixed $lines, array &$errors): array
    {
        if (!is_array($lines) || !array_is_list($lines) || $lines === []) {
            $errors['lines'] = 'At least one line is required.';

            return [];
        }
        if (count($lines) > self::MAX_LINES) {
            $errors['lines'] = 'At most ' . self::MAX_LINES . ' lines.';

            return [];
        }
        $out = [];
        foreach ($lines as $i => $line) {
            $k = "lines[{$i}].";
            if (!is_array($line)) {
                $errors["lines[{$i}]"] = 'Each line is an object.';
                continue;
            }
            $itemCode = self::text($line['item_code'] ?? null, 64);
            $itemKind = self::text($line['item_kind'] ?? null, 64);
            $desc     = self::text($line['description'] ?? null, 300);
            foreach (['item_code' => $itemCode, 'item_kind' => $itemKind, 'description' => $desc] as $f => $v) {
                if ($v === '') {
                    $errors[$k . $f] = 'Required.';
                }
            }
            $sac = trim(is_scalar($line['sac'] ?? null) ? (string) $line['sac'] : '');
            if (!preg_match('/^99[0-9]{4}$/', $sac)) {
                $errors[$k . 'sac'] = 'A six-digit SAC (services start with 99, e.g. 997331).';
            }
            $rateBp = Money::parseRate($line['gst_rate'] ?? null, 9999);
            if ($rateBp === null) {
                $errors[$k . 'gst_rate'] = 'The GST rate in percent (e.g. 18), at least 0 and below 100, at most two decimals.';
            }
            $basis = is_string($line['price_basis'] ?? null) ? strtolower(trim($line['price_basis'])) : '';
            if (!in_array($basis, [GstCalculator::INCLUSIVE, GstCalculator::EXCLUSIVE], true)) {
                $errors[$k . 'price_basis'] = 'inclusive (the amount includes GST) or exclusive (GST is added).';
            }
            $start = $line['period_start'] ?? null;
            $end   = $line['period_end'] ?? null;
            $start = $start === '' ? null : $start;
            $end   = $end === '' ? null : $end;
            if ($start !== null && !FiscalYear::isDate($start)) {
                $errors[$k . 'period_start'] = 'A date (YYYY-MM-DD).';
            }
            if ($end !== null && !FiscalYear::isDate($end)) {
                $errors[$k . 'period_end'] = 'A date (YYYY-MM-DD).';
            }
            if (is_string($start) && is_string($end) && FiscalYear::isDate($start) && FiscalYear::isDate($end) && $start > $end) {
                $errors[$k . 'period_end'] = 'The period ends before it starts.';
            }
            $qty = $line['quantity'] ?? 1;
            $qty = is_int($qty) ? (string) $qty : (is_string($qty) ? trim($qty) : '');
            if (!preg_match('/^(?=.*[1-9])\d{1,9}(\.\d{1,3})?$/', $qty)) {
                $errors[$k . 'quantity'] = 'A positive quantity (at most three decimals).';
            }
            [$list, $discount, $discountRef, $hasDiscount] = self::discount($line, $k, $errors);
            $amountGiven = (array_key_exists('amount_paise', $line) && $line['amount_paise'] !== null)
                || (array_key_exists('amount', $line) && $line['amount'] !== null && $line['amount'] !== '');
            if (array_key_exists('amount_paise', $line) && $line['amount_paise'] !== null) {
                $amount = is_int($line['amount_paise']) && $line['amount_paise'] > 0 && $line['amount_paise'] <= Money::MAX_PAISE ? $line['amount_paise'] : null;
            } else {
                $amount = Money::parse($line['amount'] ?? null);
                $amount = $amount !== null && $amount > 0 ? $amount : null;
            }
            if ($hasDiscount && $list !== null && $discount !== null) {
                // A discounted line: what is billed (and taxed) is list − discount.
                $net = $list - $discount;
                if (!$amountGiven) {
                    $amount = $net;
                } elseif ($amount !== null && $amount !== $net) {
                    $errors[$k . 'amount'] = 'Must equal the list price less the discount (' . Money::format($net) . '), or be left out.';
                }
            } elseif ($amount === null && !$hasDiscount) {
                $errors[$k . 'amount'] = 'The line amount in rupees (e.g. "11800.00"), more than zero.';
            }
            $out[] = [
                'item_code'    => $itemCode,
                'item_kind'    => $itemKind,
                'description'  => $desc,
                'sac'          => $sac,
                'gst_rate'     => $rateBp === null ? '' : Money::formatRate($rateBp),
                'rate_bp'      => $rateBp ?? 0,
                'price_basis'  => $basis,
                'period_start' => is_string($start) ? $start : null,
                'period_end'   => is_string($end) ? $end : null,
                'quantity'     => $qty,
                'amount_paise' => $amount ?? 0,
                // Discounted line (docs/INVOICING.md "Discounted lines"): null on an ordinary line.
                'list_amount_paise' => $hasDiscount ? $list : null,
                'discount_paise'    => $hasDiscount ? $discount : null,
                'discount_ref'      => $hasDiscount ? $discountRef : null,
            ];
        }

        return $out;
    }

    /**
     * Compute every line and the totals for a seller state and place of supply.
     *
     * @param list<array<string,mixed>> $lines normalised by lines()
     * @return array{lines:list<array<string,mixed>>, totals:array{taxable:int, cgst:int, sgst:int, igst:int, total:int}}
     */
    public static function compute(array $lines, string $sellerState, string $placeOfSupply): array
    {
        $intra = GstCalculator::supplyType($sellerState, $placeOfSupply) === 'intra_state';
        $out   = [];
        foreach ($lines as $line) {
            $out[] = $line + GstCalculator::line((int) $line['amount_paise'], (int) $line['rate_bp'], (string) $line['price_basis'], $intra);
        }

        return ['lines' => $out, 'totals' => GstCalculator::totals($out)];
    }

    /**
     * Optional discount of one line (contract §4.2). Given as list_amount_paise / discount_paise (integers) or
     * list_amount / discount (rupees), plus discount_ref (the coupon code or the Engage quote_ref). All absent (or
     * null) = an ordinary line. Rules: list > 0; 0 ≤ discount and, when positive, discount ≤ list − ₹1; a positive
     * discount needs its ref (≤ 64 characters: letters, digits, / _ . : -).
     *
     * @param array<string,mixed> $line
     * @param array<string,string> $errors
     * @return array{0:?int, 1:?int, 2:?string, 3:bool} [list, discount, ref, hasDiscount]
     */
    private static function discount(array $line, string $k, array &$errors): array
    {
        $present = static fn (string $f): bool => array_key_exists($f, $line) && $line[$f] !== null && $line[$f] !== '';
        if (!$present('list_amount_paise') && !$present('list_amount') && !$present('discount_paise') && !$present('discount') && !$present('discount_ref')) {
            return [null, null, null, false];
        }
        $money = static function (string $intField, string $rupeeField) use ($line, $present): array {
            if ($present($intField)) {
                $v = $line[$intField];

                return [is_int($v) && $v >= 0 && $v <= Money::MAX_PAISE ? $v : null, true];
            }
            if ($present($rupeeField)) {
                $v = Money::parse($line[$rupeeField]);

                return [$v !== null && $v >= 0 ? $v : null, true];
            }

            return [null, false];
        };
        [$list, $listGiven] = $money('list_amount_paise', 'list_amount');
        [$discount, $discGiven] = $money('discount_paise', 'discount');
        if (!$listGiven || $list === null || $list <= 0) {
            $errors[$k . 'list_amount'] = 'The list price before the discount, in rupees (more than zero), on the line\'s price basis.';
            $list = null;
        }
        if (!$discGiven || $discount === null) {
            $errors[$k . 'discount'] = 'The discount in rupees (0 or more).';
            $discount = null;
        } elseif ($list !== null && $discount > 0 && $discount > $list - 100) {
            $errors[$k . 'discount'] = 'At most ' . Money::format(max(0, $list - 100)) . ': the amount billed may not fall below ₹1 (a free plan is not a discount).';
        }
        $ref = is_scalar($line['discount_ref'] ?? null) ? trim((string) $line['discount_ref']) : '';
        if ($ref !== '' && !preg_match('#^[A-Za-z0-9/_.:\-]{1,64}$#', $ref)) {
            $errors[$k . 'discount_ref'] = 'The coupon code or quote reference: up to 64 letters, digits or / _ . : -';
        } elseif ($ref === '' && $discount !== null && $discount > 0) {
            $errors[$k . 'discount_ref'] = 'Name the discount (coupon code or quote reference) — it is printed on the invoice.';
        }

        return [$list, $discount, $ref === '' ? null : $ref, true];
    }

    /** @param array<string,string> $errors */
    public static function throwIf(array $errors, string $message = 'Some fields are invalid.'): void
    {
        if ($errors !== []) {
            throw ApiException::coded(422, 'validation_failed', $message, $errors);
        }
    }

    public static function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = $multiline
            ? trim((string) preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $value)))
            : trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_substr($value, 0, $max);
    }
}
