<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\Payouts\PayoutQueries;

/** Payees as synced from Engage batches. PAN and bank account are masked. */
class PayeeController extends BaseController
{
    public function index(array $params, array $body): never
    {
        json_success((new PayoutQueries(Database::pdo()))->payees($this->query('q')));
    }

    public function show(array $params, array $body): never
    {
        json_success((new PayoutQueries(Database::pdo()))->payee($this->idParam()));
    }
}
