<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\CompanyBankAccountService;
use App\Services\FinanceAccessPolicy;
use App\Services\Payouts\BankFileFormatter;
use App\Services\SettingsService;

/**
 * Company (debit) bank accounts for seller payout bank files.
 *
 *   GET  v1/bank-accounts                   view gate — every account, numbers masked (also for finance_admin)
 *   POST v1/bank-accounts                   settings gate — add
 *   PUT  v1/bank-accounts/{id}              settings gate — edit details (number optional, with confirmation)
 *   POST v1/bank-accounts/{id}/default      settings gate — make it the default
 *   POST v1/bank-accounts/{id}/deactivate   settings gate — {new_default_id} when it is the default and another is active
 *   POST v1/bank-accounts/{id}/activate     settings gate
 */
class BankAccountController extends BaseController
{
    public function index(array $params, array $body): never
    {
        $pdo = Database::pdo();
        json_success([
            'accounts' => (new CompanyBankAccountService($pdo))->list(),
            'can_edit' => FinanceAccessPolicy::allows($this->roles(), 'settings'),
            'options'  => [
                'account_type'     => CompanyBankAccountService::ACCOUNT_TYPES,
                'bank_file_format' => BankFileFormatter::FORMATS,
            ],
            'default_bank_file_format' => (new SettingsService($pdo))->all()['bank_file_format'],
        ]);
    }

    public function create(array $params, array $body): never
    {
        $row = (new CompanyBankAccountService(Database::pdo()))->create($body, $this->actor());
        json_success($row, $row['is_default'] ? 'Bank account added as the default.' : 'Bank account added.', 201);
    }

    public function update(array $params, array $body): never
    {
        json_success((new CompanyBankAccountService(Database::pdo()))->update($this->idParam(), $body, $this->actor()), 'Bank account saved.');
    }

    public function makeDefault(array $params, array $body): never
    {
        json_success((new CompanyBankAccountService(Database::pdo()))->setDefault($this->idParam(), $this->actor()), 'Default bank account changed.');
    }

    public function deactivate(array $params, array $body): never
    {
        json_success((new CompanyBankAccountService(Database::pdo()))->deactivate($this->idParam(), $body, $this->actor()), 'Bank account deactivated.');
    }

    public function activate(array $params, array $body): never
    {
        json_success((new CompanyBankAccountService(Database::pdo()))->activate($this->idParam(), $this->actor()), 'Bank account activated.');
    }
}
