<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\CompanyBankAccountService;
use App\Services\FinanceAccessPolicy;
use App\Services\Payouts\BankFileFormatter;
use App\Services\SettingsService;

/**
 * GET v1/settings (view gate) · PUT v1/settings (settings gate).
 *
 * `values.debit_account_number` is kept for older clients: read-only, always masked ("XXXXXX9012"),
 * taken from the default company bank account (v1/bank-accounts). Writing it is refused.
 */
class SettingsController extends BaseController
{
    public function show(array $params, array $body): never
    {
        json_success($this->payload((new SettingsService(Database::pdo()))->all()));
    }

    public function update(array $params, array $body): never
    {
        $values = (new SettingsService(Database::pdo()))->update($body, $this->actor());
        json_success($this->payload($values), 'Settings saved.');
    }

    /** @param array<string,string> $values */
    private function payload(array $values): array
    {
        $values[CompanyBankAccountService::LEGACY_SETTING] = (new CompanyBankAccountService(Database::pdo()))->defaultMasked();

        return [
            'values'   => $values,
            'can_edit' => FinanceAccessPolicy::allows($this->roles(), 'settings'),
            'options'  => [
                'gst_mode'         => SettingsService::GST_MODES,
                'payment_mode'     => SettingsService::PAYMENT_MODES,
                'bank_file_format' => BankFileFormatter::FORMATS,
            ],
        ];
    }
}
