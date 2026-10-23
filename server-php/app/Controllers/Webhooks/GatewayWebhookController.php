<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AcademyFees\GatewayConnectionService;
use App\Services\AcademyFees\GatewayPaymentService;
use App\Services\AcademyFees\WebhookService;

/**
 * Payment-gateway webhooks (no user session, no CORS; authenticated by the provider's signature over the raw body):
 *
 *   POST v1/webhooks/razorpay   X-Razorpay-Signature, X-Razorpay-Event-Id
 *   POST v1/webhooks/cashfree   x-webhook-signature, x-webhook-timestamp
 *
 * See App\Services\AcademyFees\WebhookService. Answers {ok, outcome|duplicate} (200), 401 bad signature, 503 not
 * configured, 500 Finance failed (the provider retries).
 */
class GatewayWebhookController extends BaseController
{
    public function razorpay(array $params, array $body): never
    {
        $this->handle('razorpay');
    }

    public function cashfree(array $params, array $body): never
    {
        $this->handle('cashfree');
    }

    private function handle(string $provider): never
    {
        $raw = file_get_contents('php://input');
        try {
            $pdo  = Database::pdo();
            $conn = new GatewayConnectionService($pdo);
            $res  = (new WebhookService($pdo, $conn, new GatewayPaymentService($pdo, $conn)))->handle($provider, $raw === false ? '' : $raw, self::headers());
        } catch (\App\Support\ApiException $e) {
            // Database or FINANCE_DATA_KEY not configured: 503, the provider retries later.
            error_log("[finance] {$provider} webhook not processed: " . $e->getMessage());
            $res = ['status' => $e->status() >= 500 ? $e->status() : 503, 'body' => ['ok' => false, 'error' => 'Finance is not ready to take webhooks.']];
        } catch (\Throwable $e) {
            error_log("[finance] {$provider} webhook failed: " . $e->getMessage());
            $res = ['status' => 500, 'body' => ['ok' => false, 'error' => 'Finance could not process the event; retry later.']];
        }
        http_response_code($res['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($res['body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @return array<string,string> lower-case header name => value */
    private static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }

        return $out;
    }
}
