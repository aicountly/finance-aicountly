<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AcademyFees\EngagePaymentEventOutbox;
use App\Services\AcademyFees\GatewayConnectionService;
use App\Services\AcademyFees\PaymentLinkInput;
use App\Services\AcademyFees\PaymentLinkService;
use App\Services\AcademyFees\WebhookService;
use App\Services\AcademyFees\GatewayPaymentService;
use App\Support\ApiException;

/**
 * Staff: Aicountly Academy fee payment links (docs/ACADEMY_PAYMENTS.md § "Staff screens").
 *
 *   GET  v1/academy/payment-links?status=&stream=&q=&page=   view      list (awaiting approval first)
 *   GET  v1/academy/payment-links/{id}                        view      detail: payments, invoices, webhook log, Engage events
 *   POST v1/academy/payment-links/preview                     edit      GST split and amount for the form (nothing stored)
 *   POST v1/academy/payment-links                             edit      raise a link (maker) → awaiting approval
 *   POST v1/academy/payment-links/{id}/approve                approve   four-eyes: not the maker → created at the provider
 *   POST v1/academy/payment-links/{id}/reject                 approve   {reason}
 *   POST v1/academy/payment-links/{id}/cancel                 approve   {reason?} — at the provider too
 *   POST v1/academy/payment-links/{id}/retry                  approve   retry a failed provider call
 *   POST v1/academy/payment-links/{id}/refresh                edit      re-read the link and its payments from the provider
 */
class PaymentLinkController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $status = $this->query('status') ?: null;
        $stream = $this->query('stream') ?: null;
        if ($stream !== null && !isset(PaymentLinkInput::STREAMS[$stream])) {
            throw ApiException::validation('stream must be edge or aspire.', ['stream' => 'Invalid.']);
        }
        $q = $this->query('q') ?: null;
        json_success($this->svc()->list(['status' => $status, 'stream' => $stream, 'q' => $q === null ? null : mb_substr($q, 0, 60)], (int) ($this->query('page') ?: 1)));
    }

    public function show(array $params, array $body): never
    {
        $pdo  = Database::pdo();
        $svc  = $this->svc();
        $row  = $svc->row($this->idParam());
        $conn = new GatewayConnectionService($pdo);
        $out  = $svc->staffView($row, $this->roles(), $this->actor()['actor']);
        $out['webhooks']      = (new WebhookService($pdo, $conn, new GatewayPaymentService($pdo, $conn)))->log(['link_id' => (int) $row['id']], 1, 50)['rows'];
        $out['engage_events'] = (new EngagePaymentEventOutbox($pdo))->overview(50, (int) $row['id'])['recent'];
        json_success($out);
    }

    public function preview(array $params, array $body): never
    {
        json_success($this->svc()->preview($body));
    }

    public function create(array $params, array $body): never
    {
        $svc = $this->svc();
        $row = $svc->createByStaff($body, $this->actor());
        json_success($svc->staffView($row, $this->roles(), $this->actor()['actor']), 'Payment link raised: someone else must approve it before it is created at the provider.', 201);
    }

    public function approve(array $params, array $body): never
    {
        $svc = $this->svc();
        $row = $svc->approve($this->idParam(), $this->actor());
        json_success($svc->staffView($row, $this->roles(), $this->actor()['actor']), 'Approved: the payment link is live. Share the URL with the student.');
    }

    public function reject(array $params, array $body): never
    {
        $svc = $this->svc();
        $row = $svc->reject($this->idParam(), is_string($body['reason'] ?? null) ? $body['reason'] : '', $this->actor());
        json_success($svc->staffView($row, $this->roles(), $this->actor()['actor']), 'Payment link rejected.');
    }

    public function cancel(array $params, array $body): never
    {
        $svc = $this->svc();
        $row = $svc->cancel($this->idParam(), is_string($body['reason'] ?? null) ? $body['reason'] : null, $this->actor());
        json_success($svc->staffView($row, $this->roles(), $this->actor()['actor']), 'Payment link cancelled.');
    }

    public function retry(array $params, array $body): never
    {
        $svc = $this->svc();
        $row = $svc->retry($this->idParam(), $this->actor());
        json_success($svc->staffView($row, $this->roles(), $this->actor()['actor']), 'The payment link is live.');
    }

    public function refresh(array $params, array $body): never
    {
        $svc = $this->svc();
        $row = $svc->refresh($this->idParam(), $this->actor());
        json_success($svc->staffView($row, $this->roles(), $this->actor()['actor']), 'Re-read from the provider.');
    }

    private function svc(): PaymentLinkService
    {
        $pdo = Database::pdo();

        return new PaymentLinkService($pdo, new GatewayConnectionService($pdo));
    }
}
