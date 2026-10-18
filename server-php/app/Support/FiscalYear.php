<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Indian financial year (1 April – 31 March) and TDS quarters, in IST.
 *   FY "2026-27" = 2026-04-01 … 2027-03-31
 *   Q1 Apr–Jun, Q2 Jul–Sep, Q3 Oct–Dec, Q4 Jan–Mar (Form 26Q quarters)
 */
final class FiscalYear
{
    public const TZ = 'Asia/Kolkata';

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(self::TZ);
    }

    public static function forDate(string|\DateTimeInterface $date): string
    {
        $d = $date instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($date)->setTimezone(self::tz())
            : new \DateTimeImmutable($date, self::tz());
        $year  = (int) $d->format('Y');
        $start = (int) $d->format('n') >= 4 ? $year : $year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }

    public static function current(): string
    {
        return self::forDate(new \DateTimeImmutable('now', self::tz()));
    }

    public static function isValid(string $fy): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $fy, $m)) {
            return false;
        }

        return ((int) $m[1] + 1) % 100 === (int) $m[2];
    }

    /** @return array{0:string,1:string} first and last day of the FY (Y-m-d) */
    public static function range(string $fy): array
    {
        $start = (int) substr($fy, 0, 4);

        return [sprintf('%d-04-01', $start), sprintf('%d-03-31', $start + 1)];
    }

    /** @return array{0:string,1:string} first and last day of quarter Q1..Q4 of the FY */
    public static function quarterRange(string $fy, string $quarter): array
    {
        $start = (int) substr($fy, 0, 4);

        return match (strtoupper($quarter)) {
            'Q1' => [sprintf('%d-04-01', $start), sprintf('%d-06-30', $start)],
            'Q2' => [sprintf('%d-07-01', $start), sprintf('%d-09-30', $start)],
            'Q3' => [sprintf('%d-10-01', $start), sprintf('%d-12-31', $start)],
            'Q4' => [sprintf('%d-01-01', $start + 1), sprintf('%d-03-31', $start + 1)],
            default => throw ApiException::validation('quarter must be Q1, Q2, Q3 or Q4.'),
        };
    }

    public static function quarterFor(string $date): string
    {
        $month = (int) (new \DateTimeImmutable($date, self::tz()))->format('n');

        return match (true) {
            $month >= 4 && $month <= 6  => 'Q1',
            $month >= 7 && $month <= 9  => 'Q2',
            $month >= 10                => 'Q3',
            default                     => 'Q4',
        };
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', self::tz()))->format('Y-m-d');
    }

    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::tz());

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
