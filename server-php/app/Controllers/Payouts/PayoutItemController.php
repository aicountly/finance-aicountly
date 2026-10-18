<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\Payouts\PayoutService;

/** Item-level review (hold, adjust) and payment results (UTR, failure, reversal). */
class PayoutItemController extends BaseController
{
    public function hold(array $params, array $body): never
    {
        $this->done((new PayoutService(Database::pdo()))->hold($this->idParam(), $body['reason'] ?? null, $this->actor()), 'Item put on hold.');
    }

    public function unhold(array $params, array $body): never
    {
        $this->done((new PayoutService(Database::pdo()))->unhold($this->idParam(), $body['reason'] ?? null, $this->actor()), 'Hold released.');
    }

    public function adjust(array $params, array $body): never
    {
        $this->done((new PayoutService(Database::pdo()))->adjust($this->idParam(), $body['amount'] ?? null, $body['reason'] ?? null, $this->actor()), 'Adjustment added.');
    }

    public function pay(array $params, array $body): never
    {
        $this->done((new PayoutService(Database::pdo()))->recordPayment($this->idParam(), $body['utr'] ?? null, $body['paid_at'] ?? null, $this->actor()), 'Payment recorded.');
    }

    public function fail(array $params, array $body): never
    {
        $this->done((new PayoutService(Database::pdo()))->failItem($this->idParam(), $body['reason'] ?? null, $this->actor()), 'Marked failed.');
    }

    public function reverse(array $params, array $body): never
    {
        $this->done((new PayoutService(Database::pdo()))->reverseItem($this->idParam(), $body['reason'] ?? null, $this->actor()), 'Marked reversed.');
    }

    /** @param array<string,mixed> $item */
    private function done(array $item, string $message): never
    {
        json_success([
            'id'               => (int) $item['id'],
            'batch_id'         => (int) $item['batch_id'],
            'item_ref'         => $item['item_ref'],
            'status'           => $item['status'],
            'net_amount'       => $item['net_amount'],
            'adjustment_total' => $item['adjustment_total'],
            'utr'              => $item['utr'],
            'paid_at'          => $item['paid_at'],
            'hold_reason'      => $item['hold_reason'],
            'failure_reason'   => $item['failure_reason'],
        ], $message);
    }
}
