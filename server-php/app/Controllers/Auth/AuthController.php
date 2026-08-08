<?php
declare(strict_types=1);

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../../Helpers/JwtHelper.php';
require_once __DIR__ . '/../../Services/ConsoleIdentityService.php';

use App\Services\ConsoleIdentityService;

/**
 * Finance has no local user table or login form — Console is the only
 * identity provider (see console-react-app AUTH_INTEGRATION.md). This
 * controller only ever turns a Console-issued credential into a Finance
 * portal JWT; it never accepts a password.
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

        $jwt = $this->issueToken($data);

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
            json_success(['active' => false], 'No Console session found.');
        }

        $data = $this->console->introspectSession($consoleToken);
        if (!$this->isActiveSession($data)) {
            json_success(['active' => false], 'No active Console session.');
        }

        json_success([
            'active' => true,
            'token'  => $this->issueToken($data),
            'user'   => $this->publicUser($data),
        ]);
    }

    /**
     * GET /v1/auth/me — current Finance user, decoded from the portal JWT.
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

    /** @param array<string,mixed> $introspectData */
    private function issueToken(array $introspectData): string
    {
        $user = (array) ($introspectData['user'] ?? []);
        $authConfig = require __DIR__ . '/../../Config/Auth.php';
        $ttlHours = $authConfig['TOKEN_TTL_HOURS'];

        return jwt_encode([
            'sub'   => $user['id'] ?? null,
            'email' => $user['email'] ?? '',
            'name'  => $user['name'] ?? '',
            'role'  => $user['role'] ?? '',
            'gsa'   => (bool) ($introspectData['global_superadmin'] ?? false),
            'iat'   => time(),
            'exp'   => time() + ($ttlHours * 3600),
        ], $authConfig['JWT_SECRET']);
    }

    /** @param array<string,mixed> $introspectData */
    private function publicUser(array $introspectData): array
    {
        $user = (array) ($introspectData['user'] ?? []);

        return [
            'id'                => $user['id'] ?? null,
            'name'              => $user['name'] ?? '',
            'email'             => $user['email'] ?? '',
            'role'              => $user['role'] ?? '',
            'global_superadmin' => (bool) ($introspectData['global_superadmin'] ?? false),
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
