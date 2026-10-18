<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AuditLog;

/** GET v1/audit-log?entity_type=&entity_id=&actor=&action=&page= */
class AuditLogController extends BaseController
{
    public function index(array $params, array $body): never
    {
        json_success((new AuditLog(Database::pdo()))->list([
            'entity_type' => (string) $this->query('entity_type'),
            'entity_id'   => (string) $this->query('entity_id'),
            'actor'       => strtolower((string) $this->query('actor')),
            'action'      => (string) $this->query('action'),
        ], (int) ($this->query('page') ?: 1), (int) ($this->query('per_page') ?: 50)));
    }
}
