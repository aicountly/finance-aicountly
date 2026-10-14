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

    /** HTTP status of the last failed requestJson() call, or null on success/no request yet. */
    private ?int $lastErrorStatus = null;

    /** Console's error message for the last failed requestJson() call. */
    private ?string $lastErrorMessage = null;

    public static function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    /**
     * Status of the most recent failed exchange/introspect call: the
     * upstream HTTP status Console returned (0 if Console was unreachable),
     * or null if the last call succeeded. Lets the caller distinguish an
     * expired token from "no Finance grant" from "Console is down" instead
     * of collapsing every failure into one generic error.
     */
    public function lastErrorStatus(): ?int
    {
        return $this->lastErrorStatus;
    }

    public function lastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
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

    /**
     * True when this API can ask Console for a user's current Finance roles
     * (both CONSOLE_API_URL and CONSOLE_API_TOKEN set). Production must set
     * them; without them roles are only checked at sign-in.
     */
    public function isUserAccessConfigured(): bool
    {
        return trim((string) ($_ENV['CONSOLE_API_URL'] ?? '')) !== ''
            && trim((string) ($_ENV['CONSOLE_API_TOKEN'] ?? '')) !== '';
    }

    /**
     * GET {CONSOLE_API_URL}/portal/controller-apps/user-access?app_code=finance&console_user_id=… (or &email=…)
     * with the service token (Console capability `staff.roles`).
     *
     * Any transport failure, non-2xx or malformed body is `unavailable` —
     * never a revocation — so a Console outage cannot lock everyone out.
     *
     * @return array{status:'ok'|'unavailable'|'not_configured', data:array<string,mixed>|null}
     */
    public function userAccess(?int $consoleUserId, string $email): array
    {
        if (!$this->isUserAccessConfigured()) {
            return ['status' => 'not_configured', 'data' => null];
        }

        $query = ['app_code' => $this->appCode()];
        if ($consoleUserId !== null && $consoleUserId > 0) {
            $query['console_user_id'] = (string) $consoleUserId;
        } else {
            $email = strtolower(trim($email));
            if ($email === '') {
                return ['status' => 'unavailable', 'data' => null];
            }
            $query['email'] = $email;
        }

        $url = $this->resolveConsoleApiBase() . '/portal/controller-apps/user-access?' . http_build_query($query);
        $ch  = curl_init($url);
        if ($ch === false) {
            return ['status' => 'unavailable', 'data' => null];
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . trim((string) $_ENV['CONSOLE_API_TOKEN']),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            error_log('Console user-access lookup failed HTTP ' . $status);

            return ['status' => 'unavailable', 'data' => null];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['success']) || !is_array($decoded['data'] ?? null)) {
            error_log('Console user-access lookup returned an unexpected body (HTTP ' . $status . ')');

            return ['status' => 'unavailable', 'data' => null];
        }

        return ['status' => 'ok', 'data' => $decoded['data']];
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
        $this->lastErrorStatus  = null;
        $this->lastErrorMessage = null;

        $apiBase = $this->resolveConsoleApiBase();
        $method  = strtoupper($method);

        $ch = curl_init($apiBase . $path);
        if ($ch === false) {
            $this->lastErrorStatus  = 0;
            $this->lastErrorMessage = 'Could not initialise request to Console.';

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

        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('Console identity request failed to connect for ' . $path . ': ' . $curlErr);
            $this->lastErrorStatus  = 0;
            $this->lastErrorMessage = $curlErr !== '' ? $curlErr : 'Could not reach Console.';

            return null;
        }

        $decoded = json_decode($raw, true);
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';

        if ($status < 200 || $status >= 300) {
            error_log('Console identity request failed HTTP ' . $status . ' for ' . $path . ($message !== '' ? ': ' . $message : ''));
            $this->lastErrorStatus  = $status;
            $this->lastErrorMessage = $message !== '' ? $message : 'Console request failed.';

            return null;
        }

        if (!is_array($decoded) || empty($decoded['success']) || !is_array($decoded['data'] ?? null)) {
            $this->lastErrorStatus  = $status;
            $this->lastErrorMessage = $message !== '' ? $message : 'Console returned an unexpected response.';

            return null;
        }

        return $decoded['data'];
    }
}
