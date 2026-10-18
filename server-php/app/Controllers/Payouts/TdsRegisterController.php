<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\AuditLog;
use App\Services\DataVault;
use App\Services\Payouts\PayoutQueries;
use App\Support\FiscalYear;

/**
 * TDS register, section 194H, per quarter (Form 26Q deductee data).
 *   GET v1/tds-register?fy=2026-27&quarter=Q3          view gate, PAN masked
 *   GET v1/tds-register/export?fy=2026-27&quarter=Q3   edit gate, CSV with full PAN (audited)
 */
class TdsRegisterController extends BaseController
{
    public function register(array $params, array $body): never
    {
        json_success((new PayoutQueries(Database::pdo()))->tdsRegister($this->fy(), $this->quarter()));
    }

    public function export(array $params, array $body): never
    {
        $pdo      = Database::pdo();
        $fy       = $this->fy();
        $quarter  = $this->quarter();
        $register = (new PayoutQueries($pdo))->tdsRegister($fy, $quarter, DataVault::fromEnv());
        (new AuditLog($pdo))->record($this->actor(), 'tds_register.exported', 'tds_register', "{$fy}-{$quarter}", null, [
            'fy' => $fy, 'quarter' => $quarter, 'rows' => $register['totals']['rows'], 'tds_amount' => $register['totals']['tds_amount'],
        ]);
        $this->sendCsv("tds-194H-{$fy}-{$quarter}.csv", PayoutQueries::tdsCsv($register));
    }

    private function fy(): string
    {
        return $this->query('fy') ?: FiscalYear::current();
    }

    private function quarter(): string
    {
        return $this->query('quarter') ?: FiscalYear::quarterFor(FiscalYear::today());
    }
}
