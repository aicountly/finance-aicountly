<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/BaseController.php';

use App\Database\Database;
use App\Services\FinanceAccessPolicy;
use App\Services\Payouts\BankFileFormatter;
use App\Services\SettingsService;

/** GET v1/settings (view gate; debit account masked unless finance_admin) · PUT v1/settings (settings gate). */
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
        $admin = FinanceAccessPolicy::allows($this->roles(), 'settings');
        if (!$admin && $values['debit_account_number'] !== '') {
            $values['debit_account_number'] = 'XXXXXX' . substr($values['debit_account_number'], -4);
        }

        return [
            'values'   => $values,
            'can_edit' => $admin,
            'options'  => [
                'gst_mode'         => SettingsService::GST_MODES,
                'payment_mode'     => SettingsService::PAYMENT_MODES,
                'bank_file_format' => BankFileFormatter::FORMATS,
            ],
        ];
    }
}
