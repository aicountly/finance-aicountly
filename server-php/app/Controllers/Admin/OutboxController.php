<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AuditLog;
use App\Services\Payouts\OutboxService;

/** Engage status callbacks: GET v1/outbox (view) · POST v1/outbox/{id}/requeue (settings) for a dead event. */
class OutboxController extends BaseController
{
    public function index(array $params, array $body): never
    {
        json_success((new OutboxService(Database::pdo()))->overview());
    }

    public function requeue(array $params, array $body): never
    {
        $pdo = Database::pdo();
        $id  = $this->idParam();
        if (!(new OutboxService($pdo))->requeue($id)) {
            json_error('Only a dead event can be re-queued.', [], 409);
        }
        (new AuditLog($pdo))->record($this->actor(), 'outbox.requeued', 'outbox', $id, ['status' => 'dead'], ['status' => 'pending']);
        json_success(['id' => $id, 'status' => 'pending'], 'Re-queued; the next delivery run will retry it.');
    }
}
