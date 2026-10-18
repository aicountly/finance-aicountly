<?php
declare(strict_types=1);

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../../Helpers/JwtHelper.php';
require_once __DIR__ . '/../../Services/ConsoleIdentityService.php';
require_once __DIR__ . '/../../Services/FinanceAccessPolicy.php';

use App\Services\ConsoleIdentityService;
use App\Services\FinanceAccessPolicy;

/**
 * Finance has no local user table or login form — Console is the only
 * identity provider (see console-react-app AUTH_INTEGRATION.md). This
 * controller only ever turns a Console-issued credential into a Finance
 * portal JWT; it never accepts a password.
 *
 * Roles come from Console too (app_code `finance`: finance_admin,
 * payout_approver, accountant, finance_viewer). A Console user with no Finance role assigned is refused
 * with "Access not assigned…" — an active Console grant alone is not enough.
 */
class AuthController extends BaseController
{
    private ConsoleIdentityService $console;

    public function __construct()
    {
        parent::__construct();
        $this->console = new ConsoleIdentityService();
    }

    /**
     * GET /v1/auth/sso-callback?token={one_time_key}
     *
     * Console's App Launcher redirects the browser here after the user
     * clicks Finance. Exchanges the one-time key for the user's identity,
     * issues a local Finance JWT, and hands the browser back to the SPA.
     */
    public function ssoCallback(array $params, array $body): never
    {
        $token = trim((string) ($_GET['token'] ?? $_GET['sesskey'] ?? ''));
        if ($token === '') {
            $this->redirectToFrontend(['sso_error' => 'missing_token']);
        }

        $data = $this->console->exchangeLaunchToken($token);
        if (!$this->isActiveSession($data)) {
            $this->redirectToFrontend(['sso_error' => $this->classifyExchangeFailure()]);
        }

        $access = FinanceAccessPolicy::rolesFromIdentity($data);
        if (!$access['allowed']) {
            $this->redirectToFrontend(['sso_error' => 'role_not_assigned']);
        }

        $jwt = $this->issueToken($data, $access);

        $this->redirectToFrontend(['token' => $jwt]);
    }

    /**
     * POST /v1/auth/console-session
     *
     * Direct-visit flow: the browser sends the shared Console cookie
     * (`credentials: include`, Domain=.aicountly.org) and this exchanges it
     * for a Finance JWT without ever showing a local login form.
     */
    public function consoleSession(array $params, array $body): never
    {
        $cookieName = ConsoleIdentityService::cookieName();
        $consoleToken = isset($_COOKIE[$cookieName]) ? trim((string) $_COOKIE[$cookieName]) : '';

        if ($consoleToken === '') {
            json_success(
                ['active' => false, 'reason' => 'no_console_session'],
                'No Console session found.',
            );
        }

        $data = $this->console->introspectSession($consoleToken);
        if (!$this->isActiveSession($data)) {
            $reason = $this->classifyIntrospectFailure($data);

            json_success(
                ['active' => false, 'reason' => $reason],
                $reason === 'no_finance_access'
                    ? 'Signed in to Console, but this account has no Finance access.'
                    : 'No active Console session.',
            );
        }

        $access = FinanceAccessPolicy::rolesFromIdentity($data);
        if (!$access['allowed']) {
            json_success(
                ['active' => false, 'reason' => 'role_not_assigned'],
                FinanceAccessPolicy::MSG_NOT_ASSIGNED,
            );
        }

        json_success([
            'active' => true,
            'token'  => $this->issueToken($data, $access),
            'user'   => $this->publicUser($data, $access),
        ]);
    }

    /**
     * GET /v1/auth/me — current Finance user, decoded from the portal JWT.
     * `roles` are the Finance roles: re-validated against Console by
     * AuthFilter when CONSOLE_API_TOKEN is configured, else as signed in.
     */
    public function me(array $params, array $body): never
    {
        global $currentUser;
        $payload = $currentUser ?? $this->currentUser;

        if (!$payload) {
            json_unauthorized();
        }

        json_success([
            'id'                => $payload['sub'] ?? null,
            'name'              => $payload['name'] ?? '',
            'email'             => $payload['email'] ?? '',
            'role'              => $payload['role'] ?? '',
            'global_superadmin' => (bool) ($payload['gsa'] ?? false),
            'roles'             => array_values((array) ($payload['roles'] ?? [])),
            'app_superadmin'    => (bool) ($payload['app_superadmin'] ?? false),
            'gates'             => FinanceAccessPolicy::gatesFor((array) ($payload['roles'] ?? [])),
        ]);
    }

    /** @param array<string,mixed>|null $introspectData */
    private function isActiveSession(?array $introspectData): bool
    {
        if ($introspectData === null || empty($introspectData['user'])) {
            return false;
        }

        return !empty($introspectData['active']) || !empty($introspectData['global_superadmin']);
    }

    /**
     * Turns the last Console request's HTTP status into a specific
     * `sso_error` reason so the frontend (and whoever is debugging a failed
     * sign-in) can tell an expired token apart from a missing grant apart
     * from Console being unreachable, instead of one opaque "sso_failed".
     */
    private function classifyExchangeFailure(): string
    {
        $status = $this->console->lastErrorStatus();

        return match (true) {
            $status === null => 'sso_failed',
            $status === 0 => 'console_unreachable',
            $status === 401 => 'invalid_or_expired_token',
            $status === 403 => 'no_finance_access',
            $status >= 500 => 'console_unreachable',
            default => 'sso_failed',
        };
    }

    /**
     * Why the shared-cookie check did not produce a Finance session.
     *
     * The frontend acts on this: only `no_console_session` may bounce the
     * browser to the Console login form. Redirecting on `no_finance_access`
     * would loop forever, because Console sees an authenticated user and
     * sends them straight back here.
     *
     * @param array<string,mixed>|null $introspectData
     */
    private function classifyIntrospectFailure(?array $introspectData): string
    {
        // Console answered 200 with a user but active=false → the session is
        // real, the Finance grant is not.
        if ($introspectData !== null && !empty($introspectData['user'])) {
            return 'no_finance_access';
        }

        $status = $this->console->lastErrorStatus();

        return match (true) {
            $status === 0 => 'console_unreachable',
            $status !== null && $status >= 500 => 'console_unreachable',
            default => 'no_console_session',
        };
    }

    /**
     * `role` stays the Console role (unchanged claim); `roles` are the
     * Finance roles Console assigned in app `finance`.
     *
     * @param array<string,mixed> $introspectData
     * @param array{roles:list<string>, app_superadmin:bool} $access
     */
    private function issueToken(array $introspectData, array $access): string
    {
        $user = (array) ($introspectData['user'] ?? []);
        $authConfig = require __DIR__ . '/../../Config/Auth.php';
        $ttlHours = $authConfig['TOKEN_TTL_HOURS'];
        $consoleUserId = (int) ($introspectData['console_user_id'] ?? 0);

        return jwt_encode([
            'sub'             => $user['id'] ?? null,
            'email'           => $user['email'] ?? '',
            'name'            => $user['name'] ?? '',
            'role'            => $user['role'] ?? '',
            'gsa'             => (bool) ($introspectData['global_superadmin'] ?? false),
            'roles'           => $access['roles'],
            'app_superadmin'  => $access['app_superadmin'],
            'console_user_id' => $consoleUserId > 0 ? $consoleUserId : null,
            'authz_version'   => $introspectData['authz_version'] ?? null,
            'iat'             => time(),
            'exp'             => time() + ($ttlHours * 3600),
        ], $authConfig['JWT_SECRET']);
    }

    /**
     * @param array<string,mixed> $introspectData
     * @param array{roles:list<string>, app_superadmin:bool} $access
     */
    private function publicUser(array $introspectData, array $access): array
    {
        $user = (array) ($introspectData['user'] ?? []);

        return [
            'id'                => $user['id'] ?? null,
            'name'              => $user['name'] ?? '',
            'email'             => $user['email'] ?? '',
            'role'              => $user['role'] ?? '',
            'global_superadmin' => (bool) ($introspectData['global_superadmin'] ?? false),
            'roles'             => $access['roles'],
            'app_superadmin'    => $access['app_superadmin'],
            'gates'             => FinanceAccessPolicy::gatesFor($access['roles']),
        ];
    }

    /** @param array<string,string> $query */
    private function redirectToFrontend(array $query): never
    {
        $base = rtrim((string) ($_ENV['FRONTEND_URL'] ?? 'https://finance.aicountly.org'), '/');
        header('Location: ' . $base . '/?' . http_build_query($query), true, 302);
        exit;
    }
}
