<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';
require_once dirname(__DIR__, 2) . '/Services/ConsoleIdentityService.php';
require_once dirname(__DIR__, 2) . '/Filters/ServiceKeyFilter.php';

use App\Database\Database;
use App\Services\ConsoleIdentityService;
use App\Services\DataVault;
use App\Services\Payouts\OutboxService;

/**
 * GET /api/health — public readiness for post-deploy and live verification
 * (scripts/ci/live-feature-checks.py). Booleans only: no URL, key, version or count.
 *
 *   200 {"success":true,"data":{"app":"Finance","status":"ok"|"misconfigured","checks":{
 *         "jwt_secret": bool,           JWT_SECRET is usable (every Finance session is signed with it)
 *         "console_role_sync": bool,    CONSOLE_API_URL + CONSOLE_API_TOKEN are set, so every request
 *                                       re-checks the user's Finance roles with Console (production must)
 *         "database": bool,             FINANCE_DB_* reach PostgreSQL and the payout schema is migrated
 *         "data_key": bool,             FINANCE_DATA_KEY is a usable 32-byte key (PAN / bank encryption)
 *         "engage_inbound": bool,       FINANCE_INBOUND_ENGAGE_KEY is set (≥ 32 chars): Engage can send batches
 *         "engage_callbacks": bool      ENGAGE_API_BASE_URL + ENGAGE_FINANCE_KEY are set: status callbacks go out
 *       },
 *       "payouts": {"money_movement": "manual_bank_upload"}}}
 *
 * Finance never moves money itself: payouts are released by a person uploading the
 * generated bank file; there is no payment-provider integration to report on.
 */
class HealthController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $secret = (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        $checks = [
            'jwt_secret'        => strlen(trim($secret)) >= 32,
            'console_role_sync' => (new ConsoleIdentityService())->isUserAccessConfigured(),
            'database'          => Database::isReady(),
            'data_key'          => DataVault::isConfigured(),
            'engage_inbound'    => ServiceKeyFilter::isConfigured('engage'),
            'engage_callbacks'  => OutboxService::isConfigured(),
        ];
        header('Cache-Control: no-store');
        json_success([
            'app'     => 'Finance',
            'status'  => in_array(false, $checks, true) ? 'misconfigured' : 'ok',
            'checks'  => $checks,
            'payouts' => ['money_movement' => 'manual_bank_upload'],
        ]);
    }
}
