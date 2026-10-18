<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\Payouts\PayoutQueries;

/** GET v1/dashboard — real payout metrics for the current FY. */
class DashboardController extends BaseController
{
    public function index(array $params, array $body): never
    {
        json_success((new PayoutQueries(Database::pdo()))->dashboard());
    }
}
