<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Talks to console.aicountly.org on behalf of this portal (app_code=finance).
 * Mirrors console-react-app/portal-sso/ConsoleIdentityService.php.template.
 */
class ConsoleIdentityService
{
    private const COOKIE_NAME = 'aicountly_controller_token';

    public static function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    public function exchangeLaunchToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return $this->requestJson('/auth/sso/exchange', [
            'token'    => $token,
            'app_code' => $this->appCode(),
        ]);
    }

    public function introspectSession(string $consoleToken): ?array
    {
        $consoleToken = trim($consoleToken);
        if ($consoleToken === '') {
            return null;
        }

        return $this->requestJson(
            '/auth/introspect',
            ['app_code' => $this->appCode()],
            ['Authorization: Bearer ' . $consoleToken],
        );
    }

    private function appCode(): string
    {
        return strtolower(trim((string) ($_ENV['CONTROLLER_APP_CODE'] ?? 'finance'))) ?: 'finance';
    }

    private function resolveConsoleApiBase(): string
    {
        $value = trim((string) ($_ENV['CONSOLE_API_URL'] ?? ''));

        return $value !== '' ? rtrim($value, '/') : 'https://console.aicountly.org/api';
    }

    private function requestJson(string $path, array $payload = [], array $headers = [], string $method = 'POST'): ?array
    {
        $apiBase = $this->resolveConsoleApiBase();
        $method  = strtoupper($method);

        $ch = curl_init($apiBase . $path);
        if ($ch === false) {
            return null;
        }

        $options = [
            CURLOPT_HTTPHEADER     => array_merge(
                ['Content-Type: application/json', 'Accept: application/json'],
                $headers,
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            error_log('Console identity request failed HTTP ' . $status . ' for ' . $path);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['success']) || !is_array($decoded['data'] ?? null)) {
            return null;
        }

        return $decoded['data'];
    }
}
