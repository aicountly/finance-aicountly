<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Support\Gstin;

/**
 * GST on one invoice line, in integer paise (contract §1.1, the same rule as Smart Books and Billing).
 *
 *   rate R            from the line's own data (gst_rate), never a constant; held as basis points of a percent
 *                     (18 % → 1800) like App\Support\Money
 *   price_basis       inclusive: the amount includes GST → tax = round(amount × R / (100 + R)), taxable = amount − tax
 *                     exclusive: the amount is the taxable value → tax = round(amount × R / 100), total = amount + tax
 *                     (round = half up, to the paisa)
 *   intra-state       seller state = place of supply → CGST + SGST: SGST = floor(tax / 2), CGST = tax − SGST
 *                     (CGST carries the odd paisa)
 *   inter-state       otherwise → IGST = tax
 *   place of supply   the buyer's GSTIN state code when it has a GSTIN, else its billing state (s.12(2) IGST Act for
 *                     services to a registered / unregistered recipient with a known address)
 *
 * Each line is rounded on its own; document totals are the sums of the lines.
 */
final class GstCalculator
{
    public const INCLUSIVE = 'inclusive';
    public const EXCLUSIVE = 'exclusive';

    /**
     * @return array{taxable:int, tax:int, cgst:int, sgst:int, igst:int, total:int}
     */
    public static function line(int $amountPaise, int $rateBp, string $priceBasis, bool $intraState): array
    {
        if ($amountPaise <= 0) {
            throw new \InvalidArgumentException('A line amount must be positive.');
        }
        if ($rateBp < 0 || $rateBp >= 10000) {
            throw new \InvalidArgumentException('A GST rate must be at least 0 and below 100 %.');
        }
        if ($priceBasis === self::INCLUSIVE) {
            $tax     = self::roundDiv($amountPaise * $rateBp, 10000 + $rateBp);
            $taxable = $amountPaise - $tax;
        } elseif ($priceBasis === self::EXCLUSIVE) {
            $taxable = $amountPaise;
            $tax     = self::roundDiv($amountPaise * $rateBp, 10000);
        } else {
            throw new \InvalidArgumentException('price_basis must be inclusive or exclusive.');
        }
        [$cgst, $sgst, $igst] = self::split($tax, $intraState);

        return ['taxable' => $taxable, 'tax' => $tax, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'total' => $taxable + $tax];
    }

    /** @return array{0:int, 1:int, 2:int} [cgst, sgst, igst] */
    public static function split(int $tax, bool $intraState): array
    {
        if (!$intraState) {
            return [0, 0, $tax];
        }
        $sgst = intdiv($tax, 2);

        return [$tax - $sgst, $sgst, 0];
    }

    /** Place of supply: the buyer GSTIN's state code, else the billing state code. */
    public static function placeOfSupply(?string $buyerGstin, string $billingStateCode): string
    {
        return $buyerGstin !== null && $buyerGstin !== '' ? Gstin::stateOf($buyerGstin) : $billingStateCode;
    }

    public static function supplyType(string $sellerStateCode, string $placeOfSupply): string
    {
        return $sellerStateCode === $placeOfSupply ? 'intra_state' : 'inter_state';
    }

    /**
     * Sum per-line figures into document totals.
     *
     * @param list<array{taxable:int, cgst:int, sgst:int, igst:int, total:int}> $lines
     * @return array{taxable:int, cgst:int, sgst:int, igst:int, total:int}
     */
    public static function totals(array $lines): array
    {
        $t = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total' => 0];
        foreach ($lines as $l) {
            foreach ($t as $k => $_) {
                $t[$k] += (int) $l[$k];
            }
        }

        return $t;
    }

    /** round_half_up(num / den) for num ≥ 0, den > 0. */
    private static function roundDiv(int $num, int $den): int
    {
        return intdiv($num * 2 + $den, $den * 2);
    }
}
