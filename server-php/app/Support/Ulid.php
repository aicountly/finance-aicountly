<?php
declare(strict_types=1);

namespace App\Support;

/**
 * ULID (26 characters, Crockford base32: 48-bit millisecond time + 80 random bits). Used for opaque public document
 * ids such as fin_inv_01JAB…: they reveal nothing about volume and cannot be enumerated.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $milliseconds = null): string
    {
        $ms   = $milliseconds ?? (int) floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms   = intdiv($ms, 32);
        }
        $rand  = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $rand .= self::ALPHABET[ord($bytes[$i]) % 32];
        }

        return $time . $rand;
    }
}
