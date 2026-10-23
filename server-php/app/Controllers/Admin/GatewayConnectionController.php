<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AcademyFees\GatewayConnectionService;

/**
 * Settings → Payment gateways (finance_admin only, gate settings). Secrets are write-only: no answer carries them.
 *
 *   GET  v1/gateway-connections                list (key ids, "secret set (…last4)", webhook secret set?, last test)
 *   POST v1/gateway-connections                {provider, mode, label, key_id, key_secret, webhook_secret?}
 *   PUT  v1/gateway-connections/{id}           {label?, key_id?, key_secret?, webhook_secret?, clear_webhook_secret?} — blank secret = keep
 *   POST v1/gateway-connections/{id}/test      provider auth check (read-only call)
 *   POST v1/gateway-connections/{id}/enable | /disable | /default
 */
class GatewayConnectionController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $base = rtrim(finance_env('BASE_URL'), '/');
        json_success([
            'connections'  => $this->svc()->list(),
            'providers'    => GatewayConnectionService::PROVIDERS,
            'modes'        => GatewayConnectionService::MODES,
            'webhook_urls' => ['razorpay' => $base . '/v1/webhooks/razorpay', 'cashfree' => $base . '/v1/webhooks/cashfree'],
        ]);
    }

    public function create(array $params, array $body): never
    {
        json_success($this->svc()->create($body, $this->actor()), 'Connection saved (disabled until you enable it). Test it first.', 201);
    }

    public function update(array $params, array $body): never
    {
        json_success($this->svc()->update($this->idParam(), $body, $this->actor()), 'Connection saved.');
    }

    public function test(array $params, array $body): never
    {
        $res = $this->svc()->test($this->idParam(), $this->actor());
        json_success($res, $res['message']);
    }

    public function enable(array $params, array $body): never
    {
        json_success($this->svc()->setEnabled($this->idParam(), true, $this->actor()), 'Connection enabled.');
    }

    public function disable(array $params, array $body): never
    {
        json_success($this->svc()->setEnabled($this->idParam(), false, $this->actor()), 'Connection disabled: no new links are created on it.');
    }

    public function makeDefault(array $params, array $body): never
    {
        json_success($this->svc()->makeDefault($this->idParam(), $this->actor()), 'Default connection changed.');
    }

    private function svc(): GatewayConnectionService
    {
        return new GatewayConnectionService(Database::pdo());
    }
}
