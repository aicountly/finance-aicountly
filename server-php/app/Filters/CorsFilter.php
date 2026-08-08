<?php
declare(strict_types=1);

class CorsFilter
{
    /**
     * Set CORS headers and handle OPTIONS preflight.
     *
     * Reads the allowed origin(s) from CORS_ORIGINS (or CORS_ORIGIN) env var.
     */
    public static function handle(): void
    {
        $allowed = self::allowedOrigins();
        if ($allowed === []) {
            return;
        }

        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $reflect = in_array($requestOrigin, $allowed, true)
            ? $requestOrigin
            : $allowed[0];

        header("Access-Control-Allow-Origin: {$reflect}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /** @return array<int,string> */
    private static function allowedOrigins(): array
    {
        $raw = trim((string) ($_ENV['CORS_ORIGINS'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($_ENV['CORS_ORIGIN'] ?? ''));
        }

        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_unique(array_filter($parts, static fn (string $o): bool => $o !== '')));
    }
}
