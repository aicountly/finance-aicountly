<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';
require_once dirname(__DIR__, 2) . '/Services/ConsoleIdentityService.php';

use App\Services\ConsoleIdentityService;

/**
 * GET /api/health — public readiness for post-deploy and live verification
 * (scripts/ci/live-feature-checks.py). Booleans only: no URL, key, version or count.
 *
 *   200 {"success":true,"data":{"app":"Finance","status":"ok"|"misconfigured","checks":{
 *         "jwt_secret": bool,           JWT_SECRET is usable (every Finance session is signed with it)
 *         "console_role_sync": bool     CONSOLE_API_URL + CONSOLE_API_TOKEN are set, so every request
 *                                       re-checks the user's Finance roles with Console (production must)
 *       }}}
 */
class HealthController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $secret = (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        $checks = [
            'jwt_secret'        => strlen(trim($secret)) >= 32,
            'console_role_sync' => (new ConsoleIdentityService())->isUserAccessConfigured(),
        ];
        header('Cache-Control: no-store');
        json_success([
            'app'    => 'Finance',
            'status' => in_array(false, $checks, true) ? 'misconfigured' : 'ok',
            'checks' => $checks,
        ]);
    }
}
