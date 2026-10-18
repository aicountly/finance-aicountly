<?php
declare(strict_types=1);

namespace App\Support;

/**
 * GSTIN / PAN / GST state codes (no I/O).
 *
 * GSTIN (15 characters): 2-digit state code · 10-character PAN · entity number (1-9, A-Z) · 'Z' · check character.
 * The check character is the standard GSTN mod-36 checksum over the first 14 characters: each character's value
 * (0-9 → 0-9, A-Z → 10-35) is multiplied by 1 or 2 alternately, the quotient and remainder of each product by 36 are
 * added up, and the check value is (36 − sum mod 36) mod 36. Special registrations (UN bodies, non-resident OIDAR,
 * TDS/TCS deductors) use other formats and are not accepted here.
 */
final class Gstin
{
    public const PATTERN     = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';
    public const PAN_PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    private const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * GST state / UT codes in use for place of supply (CBIC list). 25 (Daman & Diu) and 28 (undivided Andhra Pradesh)
     * were merged into 26 and 37 and are not offered for new documents.
     */
    public const STATES = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    /** Upper-cased, spaces removed; '' for null. */
    public static function normalise(mixed $value): string
    {
        return is_string($value) ? strtoupper((string) preg_replace('/\s+/', '', $value)) : '';
    }

    public static function isValid(string $gstin): bool
    {
        if (!preg_match(self::PATTERN, $gstin) || !self::isStateCode(substr($gstin, 0, 2))) {
            return false;
        }

        return self::checkCharacter(substr($gstin, 0, 14)) === $gstin[14];
    }

    /** The mod-36 check character for the first 14 characters of a GSTIN. */
    public static function checkCharacter(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $value   = strpos(self::CHARS, $first14[$i]);
            $product = ((int) $value) * ($i % 2 === 0 ? 1 : 2);
            $sum    += intdiv($product, 36) + $product % 36;
        }

        return self::CHARS[(36 - $sum % 36) % 36];
    }

    /** Why a GSTIN is refused, or null when it is valid. */
    public static function problem(string $gstin): ?string
    {
        if (!preg_match(self::PATTERN, $gstin)) {
            return 'A GSTIN is 15 characters: state code, PAN, entity number, Z, check character (e.g. 27AAPFU0939F1ZV).';
        }
        if (!self::isStateCode(substr($gstin, 0, 2))) {
            return 'The GSTIN starts with an unknown state code.';
        }
        if (!self::isValid($gstin)) {
            return 'The GSTIN check character does not match (typo?).';
        }

        return null;
    }

    public static function stateOf(string $gstin): string
    {
        return substr($gstin, 0, 2);
    }

    public static function panOf(string $gstin): string
    {
        return substr($gstin, 2, 10);
    }

    public static function isStateCode(string $code): bool
    {
        return isset(self::STATES[$code]);
    }

    public static function stateName(string $code): string
    {
        return self::STATES[$code] ?? $code;
    }

    /** "27-Maharashtra" — the place-of-supply form GSTR-1 uses. */
    public static function stateLabel(string $code): string
    {
        return $code . '-' . self::stateName($code);
    }
}
