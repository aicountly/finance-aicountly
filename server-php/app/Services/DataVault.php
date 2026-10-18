<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\ApiException;

/**
 * Encryption at rest for PAN, GSTIN (it embeds the PAN), bank account numbers
 * and generated bank files.
 *
 *   FINANCE_DATA_KEY   32 random bytes, as 64 hex characters or base64
 *                      (generate: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;')
 *
 * Two sub-keys are derived with sodium_crypto_kdf (context "finvault"):
 *   1 → XSalsa20-Poly1305 secretbox, stored as "v1:" . base64(nonce . ciphertext)
 *   2 → HMAC-SHA256 blind index, so rows can be grouped by PAN (TDS per PAN per FY)
 *       and bank changes detected without decrypting anything.
 *
 * Unset or malformed key → every call throws a 503: Finance refuses to store or
 * show sensitive payee data rather than keep it in clear. Losing the key loses
 * the PANs and account numbers — back it up with the database credentials.
 */
final class DataVault
{
    private const PREFIX = 'v1:';

    private string $encKey;
    private string $macKey;

    public function __construct(string $rawKey)
    {
        $this->encKey = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 1, 'finvault', $rawKey);
        $this->macKey = sodium_crypto_kdf_derive_from_key(32, 2, 'finvault', $rawKey);
    }

    public static function fromEnv(): self
    {
        $key = self::decodeKey(finance_env('FINANCE_DATA_KEY'));
        if ($key === null) {
            throw ApiException::notConfigured('FINANCE_DATA_KEY is not configured (32 random bytes as 64 hex characters or base64).');
        }

        return new self($key);
    }

    public static function isConfigured(): bool
    {
        return self::decodeKey(finance_env('FINANCE_DATA_KEY')) !== null;
    }

    public static function decodeKey(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9a-fA-F]{64}$/', $value)) {
            return (string) hex2bin($value);
        }
        $raw = base64_decode($value, true);

        return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_KDF_KEYBYTES) ? $raw : null;
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->encKey));
    }

    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Unrecognised ciphertext.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupt ciphertext.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->encKey,
        );
        if ($plain === false) {
            throw new \RuntimeException('Ciphertext does not decrypt with FINANCE_DATA_KEY.');
        }

        return $plain;
    }

    /** Keyed blind index of a normalised value (uppercase, no spaces). */
    public function blindIndex(string $value): string
    {
        return hash_hmac('sha256', strtoupper(preg_replace('/\s+/', '', $value) ?? ''), $this->macKey);
    }

    public static function last4(string $value): string
    {
        return substr($value, -4);
    }

    /** A GSTIN contains the PAN (characters 3–12): show only the state code and the last three. */
    public static function maskGstin(?string $gstin): ?string
    {
        return $gstin === null ? null : substr($gstin, 0, 2) . 'XXXXXXXXXX' . substr($gstin, -3);
    }

    /** What the API and UI show: "XXXXXX234F" / "XXXXXX6789". */
    public static function mask(string $last4): string
    {
        return 'XXXXXX' . $last4;
    }
}
