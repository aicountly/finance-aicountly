<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\Payouts\PayoutService;

/**
 * POST v1/inbound/engage/payout-batches  (X-Engage-Key, checked by ServiceKeyFilter)
 *
 *   201 {batch_id, status:"received"}                    new DRAFT batch, GST/TDS/net computed
 *   200 {batch_id, status:"received", duplicate:true}    same batch_ref + same content again
 *   409                                                   batch_ref known with different content
 *   422                                                   invalid payload (nothing stored)
 *   503                                                   database / FINANCE_DATA_KEY not configured
 */
class EngageInboundController extends BaseController
{
    public function payoutBatches(array $params, array $body): never
    {
        $result = (new PayoutService(Database::pdo()))->receiveBatch($body);
        $data   = ['batch_id' => $result['batch_id'], 'status' => $result['status']];
        if ($result['duplicate']) {
            json_success($data + ['duplicate' => true], 'Batch already received.', 200);
        }
        json_success($data, 'Batch received as a draft for Finance review.', 201);
    }
}
