<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AcademyFees\EngagePaymentEventOutbox;
use App\Services\AcademyFees\GatewayConnectionService;
use App\Services\AcademyFees\GatewayPaymentService;
use App\Services\AcademyFees\PaymentLinkInput;
use App\Services\AcademyFees\PaymentLinkService;
use App\Services\AcademyFees\WebhookService;
use App\Services\AuditLog;
use App\Services\FinanceAccessPolicy;
use App\Services\Invoicing\SellerProfileService;
use App\Support\ApiException;
use App\Support\Gstin;

/**
 * Staff: the rest of "Academy fees".
 *
 *   GET  v1/academy/overview                      view       readiness, connected providers (no keys), states, streams, counts, can
 *   GET  v1/academy/payments?verification=&invoice=&page=   view   gateway payments
 *   POST v1/academy/payments/{id}/retry-invoice   approve    issue the invoice of a verified payment again (after fixing setup)
 *   GET  v1/academy/webhook-events?provider=&outcome=&page=  view   webhook log (payloads scrubbed)
 *   GET  v1/academy/engage-events                 view       Finance → Engage payment events (outbox)
 *   POST v1/academy/engage-events/{id}/requeue    settings   retry a dead event (finance_admin)
 */
class AcademyFeesController extends BaseController
{
    public function overview(array $params, array $body): never
    {
        $pdo   = Database::pdo();
        $conn  = new GatewayConnectionService($pdo);
        $ready = (new SellerProfileService($pdo))->readiness();
        $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        json_success([
            'providers'  => $conn->enabledOptions(),
            'readiness'  => [
                'gateway'          => $conn->enabledOptions() !== [],
                'seller_profile'   => $ready['seller_profile'],
                'invoice_series'   => $ready['series']['tax_invoice'],
                'engage_inbound'   => S2sFilter::isConfigured(['engage']),
                'engage_events'    => EngagePaymentEventOutbox::isConfigured(),
                'fy'               => $ready['fy'],
            ],
            'counts'     => [
                'awaiting_approval'    => $count("SELECT count(*) FROM fin_payment_links WHERE status = 'pending_approval'"),
                'open'                 => $count("SELECT count(*) FROM fin_payment_links WHERE status IN ('created','partially_paid')"),
                'failed'               => $count("SELECT count(*) FROM fin_payment_links WHERE status = 'failed'"),
                'paid'                 => $count("SELECT count(*) FROM fin_payment_links WHERE status = 'paid'"),
                'verification_pending' => $count("SELECT count(*) FROM fin_gateway_payments WHERE verification_status = 'pending'"),
                'verification_mismatch'=> $count("SELECT count(*) FROM fin_gateway_payments WHERE verification_status = 'mismatch'"),
                'invoice_blocked'      => $count("SELECT count(*) FROM fin_gateway_payments WHERE invoice_state IN ('blocked_config','blocked_data')"),
                'engage_dead'          => $count("SELECT count(*) FROM fin_engage_payment_events WHERE status = 'dead'"),
            ],
            'streams'    => PaymentLinkInput::STREAMS,
            'states'     => Gstin::STATES,
            'can'        => [
                'raise'   => FinanceAccessPolicy::allows($this->roles(), 'edit'),
                'approve' => FinanceAccessPolicy::allows($this->roles(), 'approve'),
                'admin'   => FinanceAccessPolicy::allows($this->roles(), 'settings'),
            ],
        ]);
    }

    public function payments(array $params, array $body): never
    {
        $pdo    = Database::pdo();
        $where  = [];
        $args   = [];
        $verif  = $this->query('verification') ?: null;
        $inv    = $this->query('invoice') ?: null;
        if ($verif !== null) {
            if (!in_array($verif, ['pending', 'verified', 'mismatch'], true)) {
                throw ApiException::validation('verification must be pending, verified or mismatch.');
            }
            $where[] = 'p.verification_status = ?';
            $args[]  = $verif;
        }
        if ($inv !== null) {
            if (!in_array($inv, ['not_issued', 'issued', 'blocked_config', 'blocked_data'], true)) {
                throw ApiException::validation('Unknown invoice state.');
            }
            $where[] = 'p.invoice_state = ?';
            $args[]  = $inv;
        }
        $sql  = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $page = max(1, (int) ($this->query('page') ?: 1));
        $stmt = $pdo->prepare("SELECT p.*, l.link_id AS link_ref, l.academy_ref, l.source_ref, l.payer_name, l.stream,
                                      i.id AS invoice_id, i.document_id AS invoice_document_id, i.doc_no AS invoice_doc_no
                                 FROM fin_gateway_payments p JOIN fin_payment_links l ON l.id = p.link_id
                                 LEFT JOIN fin_invoices i ON i.source_ref = 'payment:' || p.id
                                 {$sql} ORDER BY p.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50));
        $stmt->execute($args);
        $count = $pdo->prepare("SELECT count(*) FROM fin_gateway_payments p {$sql}");
        $count->execute($args);
        $canRetry = FinanceAccessPolicy::allows($this->roles(), 'approve');
        $rows = array_map(static fn (array $r) => PaymentLinkService::paymentView($r) + [
            'link_id' => (int) $r['link_id'], 'link_ref' => (string) $r['link_ref'], 'academy_ref' => (string) $r['academy_ref'], 'source_ref' => $r['source_ref'],
            'payer_name' => (string) $r['payer_name'], 'stream' => (string) $r['stream'],
            'can' => ['retry_invoice' => $canRetry && $r['verification_status'] === 'verified' && in_array($r['invoice_state'], ['blocked_config', 'blocked_data', 'not_issued'], true)
                && !str_starts_with((string) $r['invoice_error'], 'Duplicate payment')],
        ], $stmt->fetchAll());
        json_success(['rows' => $rows, 'total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => 50]);
    }

    public function retryInvoice(array $params, array $body): never
    {
        $pdo  = Database::pdo();
        $id   = $this->idParam();
        $svc  = new GatewayPaymentService($pdo, new GatewayConnectionService($pdo));
        $pay  = $svc->row($id);
        if ($pay['verification_status'] !== 'verified' || $pay['invoice_state'] === 'issued') {
            throw ApiException::conflict('Only a verified payment without an invoice can be retried.');
        }
        $res = $svc->issueInvoice($id);
        (new AuditLog($pdo))->record($this->actor(), 'gateway_payment.invoice_retried', 'gateway_payment', $id, ['invoice_state' => $pay['invoice_state']],
            ['invoice_state' => $res['state'], 'error' => $res['error']]);
        if ($res['state'] !== 'issued') {
            throw ApiException::coded(422, $res['state'], 'Still not invoiced: ' . $res['error']);
        }
        json_success(['invoice' => $res['invoice'], 'state' => 'issued'], 'Invoice ' . $res['invoice']['doc_no'] . ' issued.');
    }

    public function webhookEvents(array $params, array $body): never
    {
        $pdo      = Database::pdo();
        $conn     = new GatewayConnectionService($pdo);
        $provider = $this->query('provider') ?: null;
        $outcome  = $this->query('outcome') ?: null;
        if ($provider !== null && !isset(GatewayConnectionService::PROVIDERS[$provider])) {
            throw ApiException::validation('Unknown provider.');
        }
        if ($outcome !== null && !in_array($outcome, ['received', 'processed', 'ignored', 'rejected', 'error'], true)) {
            throw ApiException::validation('Unknown outcome.');
        }
        json_success((new WebhookService($pdo, $conn, new GatewayPaymentService($pdo, $conn)))
            ->log(['provider' => $provider, 'outcome' => $outcome], max(1, (int) ($this->query('page') ?: 1))));
    }

    public function engageEvents(array $params, array $body): never
    {
        json_success((new EngagePaymentEventOutbox(Database::pdo()))->overview(100) + ['can_requeue' => FinanceAccessPolicy::allows($this->roles(), 'settings')]);
    }

    public function requeueEngageEvent(array $params, array $body): never
    {
        $pdo = Database::pdo();
        $id  = $this->idParam();
        if (!(new EngagePaymentEventOutbox($pdo))->requeue($id)) {
            json_error('Only a dead event can be re-queued.', [], 409);
        }
        (new AuditLog($pdo))->record($this->actor(), 'engage_event.requeued', 'engage_payment_event', $id, ['status' => 'dead'], ['status' => 'pending']);
        json_success(['id' => $id, 'status' => 'pending'], 'Re-queued; the next cron run sends it again.');
    }
}
