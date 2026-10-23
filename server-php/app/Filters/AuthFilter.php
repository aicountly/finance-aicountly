<?php
declare(strict_types=1);

require_once __DIR__ . '/../Helpers/JwtHelper.php';
require_once __DIR__ . '/../Helpers/ResponseHelper.php';
require_once __DIR__ . '/../Services/ConsoleIdentityService.php';
require_once __DIR__ . '/../Services/FinanceAccessPolicy.php';
require_once __DIR__ . '/../Services/AuthzCache.php';

use App\Services\AuthzCache;
use App\Services\ConsoleIdentityService;
use App\Services\FinanceAccessPolicy;

/**
 * Finance holds no local user table, so authentication starts as a stateless
 * signature + expiry check of the portal JWT issued at SSO time.
 *
 * Roles are then re-validated against Console on every authenticated request
 * (there is no refresh endpoint to hang it on): when CONSOLE_API_URL and
 * CONSOLE_API_TOKEN are set, the user's current Finance roles are pulled from
 * Console's user-access endpoint, cached for 60 s (APCu or a temp file).
 *
 *   revoked in Console        → 403 "Access not assigned…"
 *   roles changed in Console  → the request runs with the new roles
 *   Console unreachable       → token roles kept only if it was issued < 12 h ago, else 503
 *   not configured            → token roles kept, warning logged (production must configure)
 *
 * Tokens issued before Finance carried roles (no `roles` claim) are refused,
 * so the SPA silently signs in again through Console.
 */
class AuthFilter
{
    public static function getAuthorizationHeader(): string
    {
        // Apache hides the header from PHP when it rewrites to the front
        // controller. .htaccess re-exposes it as an env var, and each rewrite
        // hop prefixes another REDIRECT_, so accept any depth of nesting.
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && preg_match('/^(REDIRECT_)*HTTP_AUTHORIZATION$/', $key) && !empty($value)) {
                return (string) $value;
            }
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

        // Every Finance JWT is issued with exp (AuthController::issueToken); one without it would never expire.
        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int) $payload['exp'] < time()) {
            json_unauthorized('Token has expired.');
        }

        if (empty($payload['sub']) || empty($payload['email'])) {
            json_unauthorized('Invalid token payload.');
        }

        if (!isset($payload['roles']) || !is_array($payload['roles'])) {
            json_unauthorized('Sign in again to load your Finance role.');
        }

        $payload = self::revalidateRoles($payload, new ConsoleIdentityService());

        if ($payload['roles'] === []) {
            json_forbidden(FinanceAccessPolicy::MSG_NOT_ASSIGNED);
        }

        $currentUser = $payload;

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload decoded Finance JWT
     * @return array<string,mixed> payload with current `roles`
     */
    public static function revalidateRoles(array $payload, ConsoleIdentityService $console): array
    {
        if (!$console->isUserAccessConfigured()) {
            error_log('[finance] Roles not re-validated with Console: set CONSOLE_API_URL and CONSOLE_API_TOKEN (production must).');

            return $payload;
        }

        $consoleUserId = (int) ($payload['console_user_id'] ?? 0);
        $email         = strtolower(trim((string) ($payload['email'] ?? '')));
        $appCode       = strtolower(trim((string) ($_ENV['CONTROLLER_APP_CODE'] ?? 'finance'))) ?: 'finance';
        $cacheKey      = $appCode . '|' . ($consoleUserId > 0 ? 'id:' . $consoleUserId : 'email:' . $email);

        $verdict = AuthzCache::get($cacheKey);
        if ($verdict === null) {
            $lookup = $console->userAccess($consoleUserId > 0 ? $consoleUserId : null, $email);
            if ($lookup['status'] === 'ok') {
                $roles   = FinanceAccessPolicy::rolesFromUserAccess($lookup['data']);
                $verdict = [
                    'revoked'        => $roles === null,
                    'roles'          => $roles ?? [],
                    'app_superadmin' => !empty($lookup['data']['app_superadmin']),
                    'authz_version'  => $lookup['data']['authz_version'] ?? null,
                ];
                AuthzCache::set($cacheKey, $verdict);
            }
        }

        if ($verdict === null) {
            // Console could not be asked: trust the token's roles for a while.
            if (!FinanceAccessPolicy::withinOfflineGrace((int) ($payload['iat'] ?? 0), time())) {
                json_error('Could not confirm your Finance access with Console. Sign in again.', [], 503);
            }

            return $payload;
        }

        if (!empty($verdict['revoked'])) {
            json_forbidden(FinanceAccessPolicy::MSG_NOT_ASSIGNED);
        }

        $payload['roles']          = array_values((array) $verdict['roles']);
        $payload['app_superadmin'] = (bool) $verdict['app_superadmin'];
        $payload['authz_version']  = $verdict['authz_version'];

        return $payload;
    }
}
