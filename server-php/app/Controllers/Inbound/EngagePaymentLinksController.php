<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';
require_once dirname(__DIR__, 2) . '/Helpers/S2sResponse.php';

use App\Database\Database;
use App\Services\AcademyFees\GatewayConnectionService;
use App\Services\AcademyFees\PaymentLinkService;
use App\Support\ApiException;

/**
 * Engage → Finance: Aicountly Academy fee payment links (docs/ACADEMY_PAYMENTS.md § "Engage → Finance"), AIC-S2S v1
 * signed (S2sFilter; client engage, key scope issue; 30 requests / minute per key by default).
 *
 *   POST v1/inbound/engage/payment-links                 Idempotency-Key header required
 *        201 new link created at the provider · 200 the same request again (duplicate: true)
 *        409 idempotency_conflict / open_link_exists / already_paid · 422 validation_failed / fee_breakdown_mismatch /
 *        seller_not_configured · 502 provider_error (send the same request again) · 503 gateway_not_configured
 *   GET  v1/inbound/engage/payment-links/{link_id}       the link, its payments and their invoices
 *   POST v1/inbound/engage/payment-links/{link_id}/cancel   {reason?}
 *
 * Only links Engage created are visible to Engage (staff-raised links answer 404 like unknown ids).
 */
class EngagePaymentLinksController extends BaseController
{
    public function create(array $params, array $body): never
    {
        $this->guard(function () use ($body): never {
            $raw = file_get_contents('php://input');
            if (!is_array(json_decode($raw === false ? '' : $raw, true))) {
                throw ApiException::coded(400, 'bad_request', 'Send a JSON object.');
            }
            $key = isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) && is_string($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? trim($_SERVER['HTTP_IDEMPOTENCY_KEY']) : '';
            $svc = $this->service();
            $res = $svc->createFromEngage($body, $key, $this->caller());
            $view = $svc->engageView($res['link']);
            s2s_success($res['created'] ? $view : $view + ['duplicate' => true], null, $res['created'] ? 201 : 200);
        });
    }

    public function show(array $params, array $body): never
    {
        $this->guard(function () use ($params): never {
            $svc = $this->service();
            s2s_success($svc->engageView($this->own($svc, (string) ($params['link_id'] ?? ''))));
        });
    }

    public function cancel(array $params, array $body): never
    {
        $this->guard(function () use ($params, $body): never {
            $svc  = $this->service();
            $link = $this->own($svc, (string) ($params['link_id'] ?? ''));
            if ($link['status'] === 'cancelled') {
                s2s_success($svc->engageView($link) + ['duplicate' => true]);
            }
            $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;
            s2s_success($svc->engageView($svc->cancel((int) $link['id'], $reason, $this->caller())));
        });
    }

    private function own(PaymentLinkService $svc, string $linkId): array
    {
        $link = $svc->byLinkId($linkId);
        if ($link === null || $link['source_app'] !== 'engage') {
            throw ApiException::coded(404, 'not_found', 'No such payment link.');
        }

        return $link;
    }

    private function service(): PaymentLinkService
    {
        $pdo = Database::pdo();

        return new PaymentLinkService($pdo, new GatewayConnectionService($pdo));
    }

    /** @return array{actor:string, name:?string, ip:?string} */
    private function caller(): array
    {
        $c = S2sFilter::$caller ?? [];

        return ['actor' => 'service:' . ($c['client'] ?? 'engage'), 'name' => $c['actor'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null];
    }

    /** Every failure leaves in the AIC-S2S envelope. */
    private function guard(callable $fn): never
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $code = $e->errorCode() ?? match ($e->status()) {
                400 => 'bad_request', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'validation_failed', 503 => 'database_unavailable', default => 'error',
            };
            $details   = $e->errors();
            $retryable = (bool) ($details['retryable'] ?? in_array($e->status(), [429, 502, 503], true));
            unset($details['retryable']);
            s2s_error($e->status(), $code, $e->status() === 503 && $e->errorCode() === null ? 'Finance is temporarily unavailable.' : $e->getMessage(), $details, $retryable);
        } catch (\Throwable $e) {
            error_log('[finance] Engage payment-link request failed: ' . $e->getMessage());
            s2s_error(500, 'internal_error', 'Finance could not answer this request.', [], true);
        }
        exit;
    }
}
