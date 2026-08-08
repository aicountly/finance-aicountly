<?php
declare(strict_types=1);

require_once __DIR__ . '/../Helpers/JwtHelper.php';
require_once __DIR__ . '/../Helpers/ResponseHelper.php';

/**
 * Finance holds no local user table — the portal JWT issued at SSO time is
 * the full source of truth for the request, so authentication here is a
 * stateless signature + expiry check (unlike Console's AuthFilter, which
 * also verifies a `sessions` row).
 */
class AuthFilter
{
    public static function getAuthorizationHeader(): string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $key => $value) {
                if (strcasecmp((string) $key, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    public static function resolveToken(): string
    {
        $header = self::getAuthorizationHeader();
        if ($header !== '' && str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return '';
    }

    /**
     * Authenticate the current request via the Finance portal JWT.
     *
     * On success the decoded payload is stored in the global $currentUser
     * variable and returned. On failure the request is terminated with a
     * 401 JSON response.
     *
     * @return array<string,mixed>
     */
    public static function handle(): array
    {
        global $currentUser;

        $token = self::resolveToken();
        if ($token === '') {
            json_unauthorized('Missing or malformed Authorization header.');
        }

        $authConfig = require __DIR__ . '/../Config/Auth.php';
        $payload = jwt_decode($token, $authConfig['JWT_SECRET']);
        if ($payload === false) {
            json_unauthorized('Invalid token.');
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            json_unauthorized('Token has expired.');
        }

        if (empty($payload['sub']) || empty($payload['email'])) {
            json_unauthorized('Invalid token payload.');
        }

        $currentUser = $payload;

        return $payload;
    }
}
