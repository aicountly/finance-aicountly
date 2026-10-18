<?php
declare(strict_types=1);

/**
 * Finance API tests — no Composer, no framework, just PHP 8.1+ with curl.
 *
 *   php server-php/tests/run.php
 *
 * 1. Unit: FinanceAccessPolicy, AuthzCache and the payout rules (payouts-unit.php:
 *    money, FY, TDS/GST, state machine, four-eyes, encryption, bank files, routes)
 *    and the invoicing rules (invoicing-unit.php).
 * 2. HTTP: the real front controller (public/index.php) under `php -S`,
 *    talking to a stub Console (tests/fake-console.php) also under `php -S`.
 *    One API instance has CONSOLE_API_TOKEN set (roles re-validated per
 *    request), one does not (warn + trust the token).
 * 3. Payouts against PostgreSQL (payouts-db.php): its own database `finance_test` (FINANCE_TEST_DB_NAME overrides)
 *    (dropped afterwards), the API under `php -S`, and a stub Engage
 *    (tests/fake-engage.php) for the status outbox. Connection: FINANCE_TEST_DB_HOST
 *    / _PORT / _USER / _PASSWORD (default 127.0.0.1:5432 postgres/postgres). If
 *    PostgreSQL is unreachable the section is skipped with a warning, unless
 *    FINANCE_TESTS_REQUIRE_DB=1 (then it fails).
 * 4. Invoicing (invoicing-unit.php without I/O; invoicing-db.php against the same database): GSTIN, tax engine,
 *    amount in words, AIC-S2S v1, numbering (two worker processes, tests/invoicing-worker.php), offline payments
 *    and credit notes with four-eyes, immutability triggers, the Manage read API and a stub Gotenberg
 *    (tests/fake-gotenberg.php).
 *
 * Exits non-zero on the first failing assertion.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Helpers/JwtHelper.php';
require_once __DIR__ . '/../app/Services/FinanceAccessPolicy.php';
require_once __DIR__ . '/../app/Services/AuthzCache.php';

use App\Services\AuthzCache;
use App\Services\FinanceAccessPolicy;

const JWT_SECRET    = 'finance-tests-only-secret-0123456789abcdef';
const SERVICE_TOKEN = 'svc-staff-roles-token';
const MSG           = 'Access not assigned. Ask a Console superadmin to assign you a role.';

$passed = 0;
$current = '';

function check(bool $ok, string $what): void
{
    global $passed, $current;
    if (!$ok) {
        fwrite(STDERR, "FAIL [{$current}] {$what}\n");
        exit(1);
    }
    $passed++;
}

function same(mixed $expected, mixed $actual, string $what): void
{
    check($expected === $actual, $what . ' — expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function test(string $name, callable $fn): void
{
    global $current;
    $current = $name;
    $fn();
    echo "  ok  {$name}\n";
}

// ---------------------------------------------------------------- unit

echo "Unit\n";

$identity = static fn (array $extra = []): array => $extra + [
    'active'            => true,
    'global_superadmin' => false,
    'user'              => ['id' => 77, 'name' => 'Meera', 'email' => 'meera@aicountly.com', 'role' => 'staff'],
];

test('a blank JWT_SECRET neither signs nor verifies tokens', function (): void {
    $header = base64url_encode('{"alg":"HS256","typ":"JWT"}');
    $body = base64url_encode(json_encode(['sub' => 1, 'email' => 'x@y.z', 'roles' => ['finance_admin'], 'exp' => time() + 60]));
    $forged = $header . '.' . $body . '.' . base64url_encode(hash_hmac('sha256', "{$header}.{$body}", '', true));
    same(false, jwt_decode($forged, ''), 'token forged with an empty key is refused');
    same(false, jwt_decode($forged, '   '), 'whitespace-only key counts as empty');
    $threw = false;
    try {
        jwt_encode(['sub' => 1], '');
    } catch (\RuntimeException) {
        $threw = true;
    }
    check($threw, 'jwt_encode refuses an empty key');
    check(is_array(jwt_decode(jwt_encode(['sub' => 1], JWT_SECRET), JWT_SECRET)), 'a real key still round-trips');
});

test('assigned roles are used, unknown slugs ignored', function () use ($identity): void {
    $d = FinanceAccessPolicy::rolesFromIdentity($identity(['app_roles' => ['finance_viewer', 'build_admin', 'FINANCE_VIEWER']]));
    same(true, $d['allowed'], 'allowed');
    same(['finance_viewer'], $d['roles'], 'roles');
});

test('empty app_roles is refused even with an active grant', function () use ($identity): void {
    same(false, FinanceAccessPolicy::rolesFromIdentity($identity(['app_roles' => []]))['allowed'], 'allowed');
    same(false, FinanceAccessPolicy::rolesFromIdentity($identity(['app_roles' => ['nope']]))['allowed'], 'unknown only');
});

test('app superadmin holds the whole catalog', function () use ($identity): void {
    $d = FinanceAccessPolicy::rolesFromIdentity($identity(['app_roles' => [], 'app_superadmin' => true]));
    same(['finance_admin', 'payout_approver', 'accountant', 'finance_viewer'], $d['roles'], 'roles');
    same(true, $d['app_superadmin'], 'app_superadmin');
});

test('payload without app_roles admits only a global superadmin', function () use ($identity): void {
    same(false, FinanceAccessPolicy::rolesFromIdentity($identity())['allowed'], 'plain active user');
    $gsa = FinanceAccessPolicy::rolesFromIdentity($identity(['active' => false, 'global_superadmin' => true]));
    same(true, $gsa['allowed'], 'gsa allowed');
    same(FinanceAccessPolicy::CATALOG, $gsa['roles'], 'gsa roles');
});

test('user-access revocations', function (): void {
    $ok = ['exists' => true, 'active' => true, 'app_access' => true, 'app_superadmin' => false, 'app_roles' => ['finance_admin']];
    same(['finance_admin'], FinanceAccessPolicy::rolesFromUserAccess($ok), 'ok');
    same(null, FinanceAccessPolicy::rolesFromUserAccess(['exists' => false] + $ok), 'missing user');
    same(null, FinanceAccessPolicy::rolesFromUserAccess(['active' => false] + $ok), 'inactive user');
    same(null, FinanceAccessPolicy::rolesFromUserAccess(['app_access' => false] + $ok), 'no app access');
    same(null, FinanceAccessPolicy::rolesFromUserAccess(['app_roles' => []] + $ok), 'no roles');
});

require __DIR__ . '/payouts-unit.php';
require __DIR__ . '/invoicing-unit.php';

$cacheDir = sys_get_temp_dir() . '/finance-tests-' . bin2hex(random_bytes(4));
$_ENV['FINANCE_AUTHZ_CACHE_DIR'] = $cacheDir . '/unit';

test('file cache round-trips and expires', function (): void {
    same(null, AuthzCache::get('k'), 'miss');
    AuthzCache::set('k', ['roles' => ['finance_viewer']], 60);
    same(['roles' => ['finance_viewer']], AuthzCache::get('k'), 'hit');
    AuthzCache::set('k', ['roles' => []], -1);
    same(null, AuthzCache::get('k'), 'expired');
});

// ---------------------------------------------------------------- HTTP

echo "HTTP (php -S)\n";

$serverRoot = dirname(__DIR__);
$stateFile  = $cacheDir . '/console-state.json';
$logFile    = $cacheDir . '/console-requests.log';
@mkdir($cacheDir, 0700, true);
file_put_contents($stateFile, '{}');
file_put_contents($logFile, '');

function freePort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    return (int) substr((string) strrchr((string) $name, ':'), 1);
}

/** @var list<resource> $procs */
$procs = [];

function serve(string $router, array $env): string
{
    global $procs;
    $port = freePort();
    $cmd  = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', "127.0.0.1:{$port}", $router];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname($router), $env + ['PATH' => (string) getenv('PATH')]);
    if (!is_resource($proc)) {
        fwrite(STDERR, "could not start php -S for {$router}\n");
        exit(1);
    }
    $procs[] = $proc;

    for ($i = 0; $i < 100; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($fp) {
            fclose($fp);

            return "http://127.0.0.1:{$port}";
        }
        usleep(50_000);
    }
    fwrite(STDERR, "php -S for {$router} did not start\n");
    exit(1);
}

register_shutdown_function(static function () use (&$procs, $cacheDir): void {
    foreach ($procs as $proc) {
        proc_terminate($proc);
    }
    exec('rm -rf ' . escapeshellarg($cacheDir));
});

$console = serve(__DIR__ . '/fake-console.php', [
    'FAKE_CONSOLE_STATE' => $stateFile,
    'FAKE_CONSOLE_LOG'   => $logFile,
]);

$apiEnv = [
    'APP_ENV'             => 'development',
    'CONSOLE_API_URL'     => $console . '/api',
    'CONTROLLER_APP_CODE' => 'finance',
    'JWT_SECRET'          => JWT_SECRET,
    'TOKEN_TTL_HOURS'     => '8',
    'FRONTEND_URL'        => 'https://finance.test',
    'CORS_ORIGIN'         => 'https://finance.test',
];
$api = serve($serverRoot . '/public/index.php', $apiEnv + [
    'CONSOLE_API_TOKEN'       => SERVICE_TOKEN,
    'FINANCE_AUTHZ_CACHE_DIR' => $cacheDir . '/api',
]);
$apiUnconfigured = serve($serverRoot . '/public/index.php', $apiEnv + [
    'FINANCE_AUTHZ_CACHE_DIR' => $cacheDir . '/api-unconfigured',
]);
$apiNoSecret = serve($serverRoot . '/public/index.php', ['JWT_SECRET' => ''] + $apiEnv + [
    'FINANCE_AUTHZ_CACHE_DIR' => $cacheDir . '/api-no-secret',
]);

function stub(array $answers): void
{
    global $stateFile;
    file_put_contents($stateFile, json_encode($answers));
}

function consoleRequests(?string $key = null): array
{
    global $logFile;
    $rows = array_values(array_filter(array_map(
        static fn (string $l) => json_decode($l, true),
        file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    )));

    return $key === null ? $rows : array_values(array_filter($rows, static fn (array $r) => $r['key'] === $key));
}

function resetConsoleLog(): void
{
    global $logFile;
    file_put_contents($logFile, '');
}

function clearCache(): void
{
    global $cacheDir;
    exec('rm -rf ' . escapeshellarg($cacheDir . '/api') . ' ' . escapeshellarg($cacheDir . '/api-unconfigured'));
}

/** @return array{status:int, headers:string, json:?array, body:string} */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $text = substr($raw, $hsize);

    return ['status' => $status, 'headers' => substr($raw, 0, $hsize), 'json' => json_decode($text, true), 'body' => $text];
}

function identityBody(array $extra = []): array
{
    return ['success' => true, 'data' => $extra + [
        'active'            => true,
        'global_superadmin' => false,
        'user'              => ['id' => 77, 'name' => 'Meera Iyer', 'email' => 'meera@aicountly.com', 'role' => 'staff'],
        'controller_grants' => [],
        'app_code'          => 'finance',
    ]];
}

function userAccessBody(array $extra = []): array
{
    return ['success' => true, 'data' => $extra + [
        'exists'          => true,
        'active'          => true,
        'app_access'      => true,
        'app_superadmin'  => false,
        'console_user_id' => 9077,
        'email'           => 'meera@aicountly.com',
        'name'            => 'Meera Iyer',
        'app_roles'       => ['finance_viewer'],
        'authz_version'   => 5,
    ]];
}

function ssoCallback(string $base): array
{
    $res = http('GET', $base . '/api/v1/auth/sso-callback?token=one-time');
    preg_match('/^Location:\s*(\S+)/mi', $res['headers'], $m);
    parse_str((string) parse_url($m[1] ?? '', PHP_URL_QUERY), $query);

    return ['status' => $res['status'], 'query' => $query];
}

function claims(string $jwt): array
{
    $payload = jwt_decode($jwt, JWT_SECRET);
    check(is_array($payload), 'token verifies with JWT_SECRET');

    return $payload;
}

function me(string $base, string $jwt): array
{
    return http('GET', $base . '/api/v1/auth/me', ['Authorization: Bearer ' . $jwt]);
}

function mintToken(array $claims): string
{
    return jwt_encode($claims + [
        'sub'   => 77,
        'email' => 'meera@aicountly.com',
        'name'  => 'Meera Iyer',
        'role'  => 'staff',
        'gsa'   => false,
        'iat'   => time(),
        'exp'   => time() + 3600,
    ], JWT_SECRET);
}

test('sso-callback puts the assigned roles in the Finance JWT (existing claims kept)', function () use ($api): void {
    stub(['exchange' => ['status' => 200, 'body' => identityBody([
        'console_user_id' => 9077, 'app_roles' => ['finance_viewer'], 'app_superadmin' => false, 'authz_version' => 4,
    ])]]);
    $res = ssoCallback($api);
    same(302, $res['status'], 'redirect');
    $c = claims((string) ($res['query']['token'] ?? ''));
    same(['finance_viewer'], $c['roles'], 'roles claim');
    same('staff', $c['role'], 'Console role claim kept');
    same(false, $c['gsa'], 'gsa claim kept');
    same(77, $c['sub'], 'sub kept');
    same(9077, $c['console_user_id'], 'console_user_id');
    check($c['exp'] > time(), 'exp set');
});

test('sso-callback without a Finance role redirects with role_not_assigned', function () use ($api): void {
    stub(['exchange' => ['status' => 200, 'body' => identityBody(['app_roles' => [], 'app_superadmin' => false])]]);
    $res = ssoCallback($api);
    same('role_not_assigned', $res['query']['sso_error'] ?? null, 'sso_error');
    same(null, $res['query']['token'] ?? null, 'no token');
});

test('old Console payload (no app_roles): only a global superadmin gets in', function () use ($api): void {
    stub(['exchange' => ['status' => 200, 'body' => identityBody()]]);
    same('role_not_assigned', ssoCallback($api)['query']['sso_error'] ?? null, 'plain user refused');

    stub(['exchange' => ['status' => 200, 'body' => identityBody(['global_superadmin' => true])]]);
    $c = claims((string) (ssoCallback($api)['query']['token'] ?? ''));
    same(FinanceAccessPolicy::CATALOG, $c['roles'], 'gsa roles');
    same(true, $c['gsa'], 'gsa');
});

test('console-session: refused without role, roles returned with one', function () use ($api): void {
    $cookie = ['Cookie: aicountly_controller_token=console-cookie'];

    stub(['introspect' => ['status' => 200, 'body' => identityBody(['app_roles' => []])]]);
    $res = http('POST', $api . '/api/v1/auth/console-session', $cookie);
    same(200, $res['status'], 'status');
    same(false, $res['json']['data']['active'], 'inactive');
    same('role_not_assigned', $res['json']['data']['reason'], 'reason');
    same(MSG, $res['json']['message'], 'message');

    stub(['introspect' => ['status' => 200, 'body' => identityBody(['app_roles' => ['finance_admin']])]]);
    $res = http('POST', $api . '/api/v1/auth/console-session', $cookie);
    same(true, $res['json']['data']['active'], 'active');
    same(['finance_admin'], $res['json']['data']['user']['roles'], 'user.roles');
    same(['finance_admin'], claims($res['json']['data']['token'])['roles'], 'token roles');
});

test('me re-validates with Console user-access (service token, 60 s cache)', function () use ($api): void {
    clearCache();
    resetConsoleLog();
    $jwt = mintToken(['roles' => ['finance_viewer'], 'console_user_id' => 9077]);

    stub(['user_access' => ['status' => 200, 'body' => userAccessBody(['app_roles' => ['finance_admin']])]]);
    $res = me($api, $jwt);
    same(200, $res['status'], 'status');
    same(['finance_admin'], $res['json']['data']['roles'], 'roles come from Console, not the token');
    same('staff', $res['json']['data']['role'], 'Console role');

    $calls = consoleRequests('user_access');
    same(1, count($calls), 'one lookup');
    same('Bearer ' . SERVICE_TOKEN, $calls[0]['authorization'], 'service token');
    same('/api/portal/controller-apps/user-access?app_code=finance&console_user_id=9077', $calls[0]['uri'], 'lookup url');

    // Revoked in Console: the cached verdict holds for up to 60 s…
    stub(['user_access' => ['status' => 200, 'body' => userAccessBody(['app_access' => false, 'app_roles' => []])]]);
    same(200, me($api, $jwt)['status'], 'cached');
    same(1, count(consoleRequests('user_access')), 'served from cache');

    // …then the next check refuses.
    clearCache();
    $res = me($api, $jwt);
    same(403, $res['status'], 'revoked');
    same(MSG, $res['json']['message'], 'message');
});

test('email lookup when the token has no console_user_id', function () use ($api): void {
    clearCache();
    resetConsoleLog();
    stub(['user_access' => ['status' => 200, 'body' => userAccessBody()]]);
    same(200, me($api, mintToken(['roles' => ['finance_viewer']]))['status'], 'status');
    same('/api/portal/controller-apps/user-access?app_code=finance&email=meera%40aicountly.com', consoleRequests('user_access')[0]['uri'], 'lookup url');
});

test('Console down: token roles kept for 12 h after sign-in, then 503', function () use ($api): void {
    clearCache();
    stub(['user_access' => ['status' => 502, 'body' => 'bad gateway']]);

    $res = me($api, mintToken(['roles' => ['finance_viewer']]));
    same(200, $res['status'], 'fresh token');
    same(['finance_viewer'], $res['json']['data']['roles'], 'token roles');

    $old = mintToken(['roles' => ['finance_viewer'], 'iat' => time() - 13 * 3600, 'exp' => time() + 3600]);
    same(503, me($api, $old)['status'], 'stale token');
});

test('not configured: token roles trusted; token without roles refused', function () use ($apiUnconfigured): void {
    resetConsoleLog();
    $res = me($apiUnconfigured, mintToken(['roles' => ['finance_viewer']]));
    same(200, $res['status'], 'status');
    same(['finance_viewer'], $res['json']['data']['roles'], 'roles');
    same([], consoleRequests('user_access'), 'no lookup');

    same(401, me($apiUnconfigured, mintToken([]))['status'], 'pre-roles token');
});

test('no JWT_SECRET: forged tokens refused and sign-in fails closed', function () use ($apiNoSecret): void {
    $header = base64url_encode('{"alg":"HS256","typ":"JWT"}');
    $body = base64url_encode(json_encode([
        'sub' => 77, 'email' => 'meera@aicountly.com', 'roles' => ['finance_admin'], 'exp' => time() + 3600,
    ]));
    $forged = $header . '.' . $body . '.' . base64url_encode(hash_hmac('sha256', "{$header}.{$body}", '', true));
    same(401, me($apiNoSecret, $forged)['status'], 'token signed with an empty key');

    stub(['introspect' => ['status' => 200, 'body' => identityBody(['app_roles' => ['finance_admin']])]]);
    $res = http('POST', $apiNoSecret . '/api/v1/auth/console-session', ['Cookie: aicountly_controller_token=console-cookie']);
    same(503, $res['status'], 'console-session status');
    check(!isset($res['json']['data']['token']), 'no token issued');
});

test('GET /api/health reports Console role sync (booleans only)', function () use ($api, $apiUnconfigured): void {
    $on = http('GET', $api . '/api/health');
    same(200, $on['status'], 'status');
    same(true, $on['json']['data']['checks']['console_role_sync'] ?? null, 'configured API: console_role_sync true');
    // This instance has no payout database / keys: honest "misconfigured" (payouts-db.php checks the configured one).
    same(false, $on['json']['data']['checks']['database'] ?? null, 'no FINANCE_DB_*: database false');
    same(false, $on['json']['data']['checks']['data_key'] ?? null, 'no FINANCE_DATA_KEY: data_key false');
    same(false, $on['json']['data']['checks']['engage_inbound'] ?? null, 'no inbound key: engage_inbound false');
    same('misconfigured', $on['json']['data']['status'] ?? null, 'payouts unconfigured → misconfigured');
    $off = http('GET', $apiUnconfigured . '/api/health');
    same(false, $off['json']['data']['checks']['console_role_sync'] ?? null, 'no CONSOLE_API_TOKEN: console_role_sync false');
    same('misconfigured', $off['json']['data']['status'] ?? null, 'status misconfigured');
    check(!str_contains(json_encode($on['json']), SERVICE_TOKEN), 'no token in the answer');
});

require __DIR__ . '/payouts-db.php';
require __DIR__ . '/invoicing-db.php';

echo "\n{$passed} assertions passed.\n";
