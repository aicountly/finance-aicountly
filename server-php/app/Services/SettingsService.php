<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Payouts\BankFileFormatter;
use App\Support\ApiException;
use App\Support\Money;

/**
 * Finance payout settings (table `settings`). Defaults are seeded by migration
 * 001; the operator must confirm the tax values with their CA.
 *
 * Changing a setting affects batches computed afterwards (new drafts, and drafts
 * someone recalculates). Approved batches keep the figures that were approved.
 *
 * The company's debit account is no longer a setting: it lives in company_bank_accounts
 * (CompanyBankAccountService, Settings → Bank accounts). The old key `debit_account_number`
 * cannot be written any more; GET v1/settings still answers it, masked, from the default account.
 */
final class SettingsService
{
    public const DEFAULTS = [
        'tds_rate_percent'     => '2.00',
        'tds_threshold_per_fy' => '20000.00',
        'gst_mode'             => 'exclusive',
        'gst_rate_percent'     => '18.00',
        'minimum_payout'       => '0.00',
        'bank_file_format'     => 'generic_neft_csv',
        'payment_mode'         => 'NEFT',
        'narration_prefix'     => 'Aicountly Commission',
        'four_eyes_enabled'    => 'true',
    ];

    public const GST_MODES     = ['none', 'exclusive', 'inclusive'];
    public const PAYMENT_MODES = ['NEFT', 'IMPS', 'RTGS'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $out = self::DEFAULTS;
        foreach ($this->pdo->query('SELECT key, value FROM settings')->fetchAll() as $row) {
            if (array_key_exists((string) $row['key'], $out)) {
                $out[(string) $row['key']] = (string) $row['value'];
            }
        }

        return $out;
    }

    /**
     * Typed view used by the tax calculator and bank files.
     *
     * @param array<string,string> $s
     * @return array{tds_rate_bp:int, tds_threshold:int, gst_mode:string, gst_rate_bp:int, minimum_payout:int,
     *               bank_file_format:string, payment_mode:string, narration_prefix:string, four_eyes_enabled:bool}
     */
    public static function typed(array $s): array
    {
        return [
            'tds_rate_bp'          => Money::parseRate($s['tds_rate_percent']) ?? 0,
            'tds_threshold'        => Money::parse($s['tds_threshold_per_fy']) ?? 0,
            'gst_mode'             => in_array($s['gst_mode'], self::GST_MODES, true) ? $s['gst_mode'] : 'none',
            'gst_rate_bp'          => Money::parseRate($s['gst_rate_percent']) ?? 0,
            'minimum_payout'       => Money::parse($s['minimum_payout']) ?? 0,
            'bank_file_format'     => $s['bank_file_format'],
            'payment_mode'         => $s['payment_mode'],
            'narration_prefix'     => $s['narration_prefix'],
            'four_eyes_enabled'    => $s['four_eyes_enabled'] === 'true',
        ];
    }

    /**
     * Validate a partial update.
     *
     * @param array<string,mixed> $input
     * @return array<string,string> normalised values to write
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $out    = [];
        foreach ($input as $key => $value) {
            if ($key === CompanyBankAccountService::LEGACY_SETTING) {
                $errors[$key] = 'The debit account is managed in Settings → Bank accounts now (v1/bank-accounts).';
                continue;
            }
            if (!array_key_exists((string) $key, self::DEFAULTS)) {
                $errors[$key] = 'Unknown setting.';
                continue;
            }
            $str = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? trim((string) $value) : null);
            if ($str === null) {
                $errors[$key] = 'Must be a string.';
                continue;
            }
            [$value, $error] = self::normalise((string) $key, $str);
            if ($error !== null) {
                $errors[$key] = $error;
            } else {
                $out[$key] = $value;
            }
        }
        if ($errors !== []) {
            throw ApiException::validation('Some settings are invalid.', $errors);
        }

        return $out;
    }

    /** @return array{0:?string, 1:?string} [normalised value, error] */
    private static function normalise(string $key, string $str): array
    {
        switch ($key) {
            case 'tds_rate_percent':
                $bp = Money::parseRate($str, 3000);

                return $bp === null ? [null, 'A percentage between 0 and 30 with at most two decimals.'] : [Money::formatRate($bp), null];
            case 'gst_rate_percent':
                $bp = Money::parseRate($str, 2800);

                return $bp === null ? [null, 'A percentage between 0 and 28 with at most two decimals.'] : [Money::formatRate($bp), null];
            case 'tds_threshold_per_fy':
            case 'minimum_payout':
                $p = Money::parse($str);

                return $p === null ? [null, 'An amount in rupees, e.g. "20000.00".'] : [Money::format($p), null];
            case 'gst_mode':
                return in_array($str, self::GST_MODES, true) ? [$str, null] : [null, 'One of: ' . implode(', ', self::GST_MODES) . '.'];
            case 'payment_mode':
                $u = strtoupper($str);

                return in_array($u, self::PAYMENT_MODES, true) ? [$u, null] : [null, 'One of: ' . implode(', ', self::PAYMENT_MODES) . '.'];
            case 'bank_file_format':
                return array_key_exists($str, BankFileFormatter::FORMATS)
                    ? [$str, null]
                    : [null, 'One of: ' . implode(', ', array_keys(BankFileFormatter::FORMATS)) . '.'];
            case 'narration_prefix':
                return preg_match('/^[A-Za-z0-9 .\-\/]{0,30}$/', $str) ? [$str, null] : [null, 'Up to 30 letters, digits, spaces or . - /'];
            case 'four_eyes_enabled':
                return in_array($str, ['true', 'false'], true) ? [$str, null] : [null, 'true or false.'];
        }

        return [null, 'Unknown setting.'];
    }

    /**
     * @param array<string,mixed> $input
     * @param array{actor:string, name:?string, ip:?string} $actor
     * @return array<string,string>
     */
    public function update(array $input, array $actor): array
    {
        $values = self::validate($input);
        if ($values === []) {
            throw ApiException::validation('Nothing to update.');
        }
        $this->pdo->beginTransaction();
        try {
            $before = $this->all();
            $stmt = $this->pdo->prepare(
                'INSERT INTO settings (key, value, updated_at, updated_by) VALUES (?, ?, now(), ?)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now(), updated_by = EXCLUDED.updated_by',
            );
            $changedBefore = [];
            $changedAfter  = [];
            foreach ($values as $key => $value) {
                if ($before[$key] === $value) {
                    continue;
                }
                $stmt->execute([$key, $value, $actor['actor']]);
                $changedBefore[$key] = $before[$key];
                $changedAfter[$key]  = $value;
            }
            if ($changedAfter !== []) {
                (new AuditLog($this->pdo))->record($actor, 'settings.updated', 'settings', null, $changedBefore, $changedAfter);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->all();
    }
}
