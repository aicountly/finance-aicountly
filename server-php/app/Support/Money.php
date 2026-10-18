<?php
declare(strict_types=1);

namespace App\Support;

/**
 * INR money as integer paise — never floats. The wire format (contract) is a
 * NUMERIC(14,2) string such as "1234.50"; PostgreSQL returns NUMERIC columns
 * as the same kind of string, so values round-trip without loss.
 *
 * Rates are "basis points of a percent": 2.00 % → 200, 18.00 % → 1800.
 */
final class Money
{
    /** Upper bound for any single amount (₹10,00,00,00,000.00) — keeps every product inside PHP_INT_MAX. */
    public const MAX_PAISE = 1_000_000_000_000;

    /**
     * "1234.5" | "1234.50" | 1234 | "-12.30" → paise. Null when the value is
     * not a plain decimal with at most two fraction digits.
     */
    public static function parse(mixed $value, bool $allowNegative = false): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $pattern = $allowNegative ? '/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/' : '/^()(\d{1,13})(?:\.(\d{1,2}))?$/';
        if (!preg_match($pattern, $value, $m)) {
            return null;
        }
        $paise = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        if ($paise > self::MAX_PAISE) {
            return null;
        }

        return $m[1] === '-' ? -$paise : $paise;
    }

    public static function format(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $abs  = abs($paise);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A percent string ("2", "2.00", "18.5") → basis points of a percent, or null. */
    public static function parseRate(mixed $value, int $maxBp = 10000): ?int
    {
        $bp = self::parse(is_numeric($value) ? (string) $value : $value);
        if ($bp === null || $bp > $maxBp) {
            return null;
        }

        return $bp;
    }

    public static function formatRate(int $bp): string
    {
        return self::format($bp);
    }

    /** round_half_up(paise × bp / 10000) for non-negative inputs. */
    public static function percentOf(int $paise, int $bp): int
    {
        if ($paise <= 0 || $bp <= 0) {
            return 0;
        }

        return intdiv($paise * $bp + 5000, 10000);
    }

    /** The taxable value inside a GST-inclusive amount: round_half_up(gross × 10000 / (10000 + bp)). */
    public static function exclusiveOf(int $grossPaise, int $bp): int
    {
        if ($grossPaise <= 0) {
            return 0;
        }
        $den = 10000 + $bp;

        return intdiv($grossPaise * 10000 * 2 + $den, $den * 2);
    }
}
