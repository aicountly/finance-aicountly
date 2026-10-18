<?php
declare(strict_types=1);

require_once __DIR__ . '/../Helpers/S2sResponse.php';

use App\Database\Database;
use App\Security\AicS2s;

/**
 * Inbound AIC-S2S v1 authentication (commerce contract §0.1, App\Security\AicS2s) for the server-to-server routes
 * Manage (and later Engage) call. These routes never accept a Finance staff JWT or a browser session, and get no
 * CORS headers: they are not reachable from a browser.
 *
 * Route config (Config/Routes.php): 's2s' => ['scope' => 'read'|'issue', 'clients' => ['manage'], 'cost' => 1]
 *
 *   503 inbound_not_configured   no ≥ 32-byte FINANCE_INBOUND_<CLIENT>_SECRET_<KEYID> for any allowed caller
 *   400 environment_required     X-AIC-Environment missing
 *   401 signature_missing / unknown_key / timestamp_stale / signature_invalid / nonce_replayed
 *   403 environment_mismatch / route_not_allowed_for_key
 *   429 rate_limited (+ Retry-After)   per key id: FINANCE_S2S_READ_PER_MINUTE (120) / FINANCE_S2S_ISSUE_PER_MINUTE (30)
 *   503 database_unavailable     nonces and rate windows live in PostgreSQL (fin_s2s_nonces, fin_s2s_rate)
 *
 * The receiver's environment is FINANCE_S2S_ENVIRONMENT (production | sandbox | local), else "production" when
 * APP_ENV=production and "local" otherwise.
 */
class S2sFilter
{
    /** @var array{client:string, key_id:string, scope:string, actor:string, request_id:string}|null */
    public static ?array $caller = null;

    public static string $requestId = '';

    /** @param array{scope?:string, clients?:list<string>, cost?:int} $route */
    public static function handle(array $route): void
    {
        self::$requestId = s2s_request_id();
        $route += ['scope' => 'read', 'clients' => ['manage'], 'cost' => 1];

        if (!self::isConfigured($route['clients'])) {
            s2s_error(503, 'inbound_not_configured', 'Finance is not configured to accept this caller yet.', [], true);
        }

        $raw  = file_get_contents('php://input');
        $body = $raw === false ? '' : $raw;
        $v = AicS2s::verify(
            self::headers(),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::path(),
            (string) ($_SERVER['QUERY_STRING'] ?? ''),
            $body,
            ['scope' => $route['scope'], 'clients' => $route['clients']],
            [self::class, 'secretFor'],
            [self::class, 'scopeFor'],
            self::environment(),
            time(),
        );
        if (!$v['ok']) {
            s2s_error($v['status'], $v['code'], $v['message'], [], false);
        }

        try {
            $pdo = Database::pdo();
            $pdo->exec("DELETE FROM fin_s2s_nonces WHERE seen_at < now() - interval '" . AicS2s::NONCE_TTL . " seconds'");
            $ins = $pdo->prepare('INSERT INTO fin_s2s_nonces (key_id, nonce) VALUES (?, ?) ON CONFLICT DO NOTHING');
            $ins->execute([$v['key_id'], $v['nonce']]);
            $fresh = $ins->rowCount() === 1;
        } catch (\Throwable $e) {
            error_log('[finance] S2S nonce store unavailable: ' . $e->getMessage());
            s2s_error(503, 'database_unavailable', 'Finance is temporarily unavailable.', [], true);
        }
        if (!$fresh) {
            s2s_error(401, 'nonce_replayed', 'This X-AIC-Nonce was already used.', [], false);
        }

        $limit = self::limit($route['scope']);
        $rate  = $pdo->prepare(
            "INSERT INTO fin_s2s_rate (key_id, scope, window_start, used) VALUES (?, ?, date_trunc('minute', now()), ?)
             ON CONFLICT (key_id, scope, window_start) DO UPDATE SET used = fin_s2s_rate.used + EXCLUDED.used
             RETURNING used, ceil(extract(epoch FROM (window_start + interval '1 minute' - now())))::int AS reset",
        );
        $rate->execute([$v['key_id'], $route['scope'], max(1, (int) $route['cost'])]);
        $r = $rate->fetch();
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM fin_s2s_rate WHERE window_start < now() - interval '1 hour'");
        }
        if ((int) $r['used'] > $limit) {
            $retry = max(1, (int) $r['reset']);
            s2s_error(429, 'rate_limited', 'Too many requests for this key; slow down.', ['limit_per_minute' => $limit], true, ['Retry-After' => (string) $retry]);
        }

        self::$caller = [
            'client'     => $v['client'],
            'key_id'     => $v['key_id'],
            'scope'      => $v['scope'],
            'actor'      => $v['actor'],
            'request_id' => self::$requestId,
        ];
    }

    /** @param list<string> $clients */
    public static function isConfigured(array $clients = ['manage']): bool
    {
        foreach (self::environmentVariables() as $name => $value) {
            foreach ($clients as $client) {
                if (str_starts_with($name, 'FINANCE_INBOUND_' . strtoupper($client) . '_SECRET_') && strlen(trim($value)) >= AicS2s::MIN_SECRET) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function secretFor(string $client, string $keyId): ?string
    {
        $name   = AicS2s::secretEnvName($client, $keyId);
        $secret = finance_env($name);
        if ($secret === '') {
            return null;
        }
        if (strlen($secret) < AicS2s::MIN_SECRET) {
            error_log("[finance] {$name} is shorter than " . AicS2s::MIN_SECRET . ' bytes and is ignored.');

            return null;
        }

        return $secret;
    }

    public static function scopeFor(string $client, string $keyId): string
    {
        $scope = strtolower(finance_env(AicS2s::scopeEnvName($client, $keyId), 'read'));

        return in_array($scope, AicS2s::SCOPES, true) ? $scope : 'read';
    }

    public static function environment(): string
    {
        $env = strtolower(finance_env('FINANCE_S2S_ENVIRONMENT'));
        if (in_array($env, AicS2s::ENVIRONMENTS, true)) {
            return $env;
        }

        return finance_env('APP_ENV') === 'production' ? 'production' : 'local';
    }

    private static function limit(string $scope): int
    {
        $raw = finance_env($scope === 'issue' ? 'FINANCE_S2S_ISSUE_PER_MINUTE' : 'FINANCE_S2S_READ_PER_MINUTE');

        return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : ($scope === 'issue' ? 30 : 120);
    }

    /** True for paths served only to signed S2S callers (no CORS, no staff session). */
    public static function isS2sPath(string $requestUri): bool
    {
        $path = (string) parse_url($requestUri, PHP_URL_PATH);

        return (bool) preg_match('#^(/api)?/v1/inbound/manage(/|$)#', $path);
    }

    /** The signed path: the request path after /api, e.g. /v1/inbound/manage/documents. */
    public static function path(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return str_starts_with($path, '/api/') ? substr($path, 4) : $path;
    }

    /** @return array<string,string> */
    private static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_X_AIC_')) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function environmentVariables(): array
    {
        $vars = [];
        foreach ((array) getenv() as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $vars[$k] = $v;
            }
        }
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $vars[$k] = (string) $v;
            }
        }

        return $vars;
    }
}
