<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Tiny TTL cache for Console user-access answers, so a burst of API calls
 * costs one Console round trip per user per minute. Finance has no database:
 * APCu is used when it is enabled, otherwise one JSON file per key under
 * FINANCE_AUTHZ_CACHE_DIR (default: the system temp dir).
 */
final class AuthzCache
{
    public const DEFAULT_TTL = 60;

    public static function get(string $key): ?array
    {
        if (self::useApcu()) {
            $hit = false;
            $value = \apcu_fetch(self::apcuKey($key), $hit);

            return $hit && is_array($value) ? $value : null;
        }

        $file = self::file($key);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $entry = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($entry) || !isset($entry['expires'], $entry['value']) || (int) $entry['expires'] <= time()) {
            @unlink($file);

            return null;
        }

        return is_array($entry['value']) ? $entry['value'] : null;
    }

    /** @param array<string,mixed> $value */
    public static function set(string $key, array $value, int $ttl = self::DEFAULT_TTL): void
    {
        if (self::useApcu()) {
            \apcu_store(self::apcuKey($key), $value, $ttl);

            return;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log('[finance] authz cache directory is not writable: ' . $dir);

            return;
        }

        $file = self::file($key);
        $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['expires' => time() + $ttl, 'value' => $value], JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);

            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $file);
    }

    private static function useApcu(): bool
    {
        return function_exists('apcu_enabled') && \apcu_enabled();
    }

    private static function apcuKey(string $key): string
    {
        return 'finance_authz:' . hash('sha256', $key);
    }

    private static function dir(): string
    {
        $configured = trim((string) ($_ENV['FINANCE_AUTHZ_CACHE_DIR'] ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // Per-install subdirectory so two deployments on one host never share entries.
        return rtrim(sys_get_temp_dir(), '/') . '/finance-authz-' . substr(hash('sha256', __DIR__), 0, 12);
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.json';
    }
}
