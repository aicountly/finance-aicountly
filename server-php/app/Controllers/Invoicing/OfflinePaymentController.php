<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\FinanceAccessPolicy;
use App\Services\Invoicing\OfflinePaymentService;

/**
 * Staff: offline payments (bank transfer, UPI, cheque) → tax invoice, four-eyes.
 *
 *   GET  v1/offline-payments?status=&invoice_state=   view      list
 *   GET  v1/offline-payments/{id}                     view      detail
 *   POST v1/offline-payments/preview                  edit      GST computed for a draft (nothing stored)
 *   POST v1/offline-payments                          edit      record (maker)
 *   POST v1/offline-payments/{id}/verify              approve   verify against the bank statement (checker ≠ maker) → invoice issued
 *   POST v1/offline-payments/{id}/reject              approve   with a reason
 *   POST v1/offline-payments/{id}/retry-invoice       approve   retry an invoice blocked by missing configuration
 */
class OfflinePaymentController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $rows = (new OfflinePaymentService(Database::pdo()))->list(['status' => $this->query('status'), 'invoice_state' => $this->query('invoice_state')]);
        json_success([
            'payments' => array_map([$this, 'withCan'], $rows),
            'methods'  => OfflinePaymentService::METHODS,
            'can'      => ['record' => FinanceAccessPolicy::allows($this->roles(), 'edit'), 'verify' => FinanceAccessPolicy::allows($this->roles(), 'approve')],
        ]);
    }

    public function show(array $params, array $body): never
    {
        json_success($this->withCan((new OfflinePaymentService(Database::pdo()))->get($this->idParam())));
    }

    public function preview(array $params, array $body): never
    {
        json_success((new OfflinePaymentService(Database::pdo()))->preview($body));
    }

    public function create(array $params, array $body): never
    {
        $p = (new OfflinePaymentService(Database::pdo()))->record($body, $this->actor());
        json_success($this->withCan($p), 'Payment recorded. Someone else must verify it against the bank statement before the invoice is issued.', 201);
    }

    public function verify(array $params, array $body): never
    {
        $p = (new OfflinePaymentService(Database::pdo()))->verify($this->idParam(), $this->actor());
        json_success($this->withCan($p), $p['invoice'] !== null
            ? "Payment verified; tax invoice {$p['invoice']['doc_no']} issued."
            : 'Payment verified, but the invoice could not be issued yet: ' . $p['invoice_error']);
    }

    public function reject(array $params, array $body): never
    {
        $p = (new OfflinePaymentService(Database::pdo()))->reject($this->idParam(), $body['reason'] ?? null, $this->actor());
        json_success($this->withCan($p), 'Payment rejected.');
    }

    public function retry(array $params, array $body): never
    {
        $p = (new OfflinePaymentService(Database::pdo()))->retryInvoice($this->idParam(), $this->actor());
        json_success($this->withCan($p), $p['invoice'] !== null ? "Tax invoice {$p['invoice']['doc_no']} issued." : 'Still blocked: ' . $p['invoice_error']);
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function withCan(array $p): array
    {
        $approve = FinanceAccessPolicy::allows($this->roles(), 'approve');
        $mine    = strtolower($p['recorded_by']) === strtolower($this->actor()['actor']);
        $pending = $p['status'] === 'recorded';

        return $p + ['can' => [
            'verify'         => $pending && $approve && !$mine,
            'reject'         => $pending && $approve,
            'retry_invoice'  => $approve && $p['status'] === 'verified' && $p['invoice_state'] !== 'issued',
            'blocked_reason' => $pending && $approve && $mine ? 'Four-eyes rule: you recorded this payment, so someone else must verify it.' : null,
        ]];
    }
}
