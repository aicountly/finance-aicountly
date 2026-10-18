<?php
declare(strict_types=1);

namespace App\Support;

/**
 * INR amount in words, Indian numbering (thousand / lakh / crore): 1180000 paise → "Rupees Eleven Thousand Eight
 * Hundred Only"; 11264020 → "Rupees One Lakh Twelve Thousand Six Hundred Forty and Twenty Paise Only".
 *
 * Ported from Smart Books (books-react-app server-php/app/Services/Printing/AmountInWordsService.php, the INR branch
 * of words() with indianWords()/hundreds()), so a Finance invoice reads exactly like a Books one. Changed for Finance:
 * the input is integer paise (no float rounding), and only INR is supported.
 */
final class AmountInWords
{
    public static function inr(int $paise): string
    {
        $negative = $paise < 0;
        $paise    = abs($paise);
        $rupees   = intdiv($paise, 100);
        $minor    = $paise % 100;

        $parts = ['Rupees', self::indianWords($rupees)];
        if ($minor > 0) {
            $parts[] = 'and';
            $parts[] = self::indianWords($minor);
            $parts[] = 'Paise';
        }
        $parts[] = 'Only';

        $text = trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));

        return $negative ? 'Minus ' . $text : $text;
    }

    private static function ones(int $n): string
    {
        $w = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
              'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
              'Seventeen', 'Eighteen', 'Nineteen'];

        return $w[$n] ?? '';
    }

    private static function tens(int $n): string
    {
        $w = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        return $w[$n] ?? '';
    }

    private static function hundreds(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        if ($n < 20) {
            return self::ones($n);
        }
        if ($n < 100) {
            $o = $n % 10;

            return trim(self::tens(intdiv($n, 10)) . ($o ? ' ' . self::ones($o) : ''));
        }
        $rest = $n % 100;

        return trim(self::ones(intdiv($n, 100)) . ' Hundred' . ($rest ? ' ' . self::hundreds($rest) : ''));
    }

    private static function indianWords(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $crore = intdiv($n, 10_000_000);
        $n    %= 10_000_000;
        $lakh  = intdiv($n, 100_000);
        $n    %= 100_000;
        $thou  = intdiv($n, 1000);
        $hund  = $n % 1000;

        $parts = [];
        if ($crore) {
            $parts[] = self::indianWords($crore) . ' Crore';
        }
        if ($lakh) {
            $parts[] = self::hundreds($lakh) . ' Lakh';
        }
        if ($thou) {
            $parts[] = self::hundreds($thou) . ' Thousand';
        }
        if ($hund) {
            $parts[] = self::hundreds($hund);
        }

        return implode(' ', $parts);
    }
}
