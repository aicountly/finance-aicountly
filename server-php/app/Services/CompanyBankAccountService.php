<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Payouts\BankFileFormatter;
use App\Support\ApiException;

/**
 * The company's own bank accounts that seller payouts are debited from (table company_bank_accounts,
 * migration 002). Replaces the single setting `debit_account_number`.
 *
 *  - Account numbers are encrypted with DataVault (FINANCE_DATA_KEY); only the last four digits are
 *    stored in clear. No API answer and no audit row ever carries the full number — not even for a
 *    finance_admin. The only place it appears is inside a generated bank file.
 *  - Exactly one active account is the default whenever any account is active (partial unique index
 *    plus a deferred constraint trigger in the database; the rules below keep it so).
 *  - Writes (create, edit, set default, deactivate, activate) are finance_admin only (route gate
 *    `settings`); every Finance role can list them, masked.
 *  - Every change is in audit_log (entity_type company_bank_account), masked.
 *
 * $actor = ['actor' => email, 'name' => display name, 'ip' => client ip].
 */
final class CompanyBankAccountService
{
    public const ACCOUNT_TYPES = [
        'current' => 'Current account',
        'savings' => 'Savings account',
        'od'      => 'Overdraft (OD)',
        'cc'      => 'Cash credit (CC)',
    ];

    /** Settings key the account used to live in (kept readable, masked, for older clients). */
    public const LEGACY_SETTING = 'debit_account_number';
    public const LEGACY_LABEL   = 'Primary account';

    public const IFSC_PATTERN   = '/^[A-Z]{4}0[A-Z0-9]{6}$/';
    public const NUMBER_PATTERN = '/^[0-9]{6,18}$/';

    private const LOCK_KEY        = 7_302_119_003;
    private const MIGRATION_ACTOR = ['actor' => 'system:migration', 'name' => 'Migration 002 (debit account moved from Settings)', 'ip' => null];

    private const COLUMNS = 'id, label, bank_name, account_holder, account_number_last4, account_number_hash, ifsc, branch, account_type,
                             bank_file_format, is_default, active, source, created_by, created_at, updated_by, updated_at';

    private AuditLog $audit;

    public function __construct(private \PDO $pdo, private ?DataVault $vault = null)
    {
        $this->audit = new AuditLog($pdo);
    }

    private function vault(): DataVault
    {
        return $this->vault ??= DataVault::fromEnv();
    }

    // ------------------------------------------------------------------ reads (every Finance role)

    /** @return list<array<string,mixed>> every account, default first, then active, then by label */
    public function list(bool $activeOnly = false): array
    {
        $this->adoptLegacyIfPending();
        $sql = 'SELECT ' . self::COLUMNS . ' FROM company_bank_accounts'
             . ($activeOnly ? ' WHERE active' : '')
             . ' ORDER BY is_default DESC, active DESC, lower(label), id';

        return array_map([self::class, 'publicRow'], $this->pdo->query($sql)->fetchAll());
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return self::publicRow($this->row($id, false));
    }

    /**
     * What GET v1/settings still answers as `debit_account_number`: the default account, masked
     * ("XXXXXX9012"), or '' when there is none.
     */
    public function defaultMasked(): string
    {
        $this->adoptLegacyIfPending();
        $row = $this->pdo->query('SELECT account_number_last4 FROM company_bank_accounts WHERE active AND is_default LIMIT 1')->fetch();
        if ($row) {
            return DataVault::mask((string) $row['account_number_last4']);
        }
        $legacy = $this->legacyValue();

        return $legacy === '' ? '' : DataVault::mask(DataVault::last4($legacy));
    }

    // ------------------------------------------------------------------ writes (finance_admin)

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function create(array $input, array $actor): array
    {
        $v     = self::validate($input, null);
        $vault = $this->vault();

        return $this->tx(function () use ($v, $vault, $actor): array {
            $hash = $vault->blindIndex((string) $v['account_number']);
            $this->refuseDuplicate($hash, null);
            $hasActive = (bool) $this->pdo->query('SELECT EXISTS (SELECT 1 FROM company_bank_accounts WHERE active) AS x')->fetch()['x'];
            $default   = !$hasActive || $v['is_default'];
            if ($default) {
                $this->pdo->exec('UPDATE company_bank_accounts SET is_default = false WHERE is_default');
            }
            $ins = $this->pdo->prepare(
                'INSERT INTO company_bank_accounts (label, bank_name, account_holder, account_number_enc, account_number_last4, account_number_hash,
                     ifsc, branch, account_type, bank_file_format, is_default, active, source, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true, \'manual\', ?, ?) RETURNING id',
            );
            $ins->execute([
                $v['label'], $v['bank_name'], $v['account_holder'], $vault->encrypt((string) $v['account_number']),
                DataVault::last4((string) $v['account_number']), $hash, $v['ifsc'], $v['branch'], $v['account_type'],
                $v['bank_file_format'], $default ? 'true' : 'false', $actor['actor'], $actor['actor'],
            ]);
            $id  = (int) $ins->fetch()['id'];
            $row = $this->row($id, false);
            $this->audit->record($actor, 'bank_account.created', 'company_bank_account', $id, null, self::snapshot($row));

            return self::publicRow($row);
        });
    }

    /**
     * Edit details. `account_number` (with `account_number_confirm`) is optional: leave it out to keep the
     * current number. Default and active state have their own routes and are not changed here.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function update(int $id, array $input, array $actor): array
    {
        return $this->tx(function () use ($id, $input, $actor): array {
            $before = $this->row($id, true);
            $v      = self::validate($input, $before);
            $enc    = null;
            $last4  = (string) $before['account_number_last4'];
            $hash   = null;
            if ($v['account_number'] !== null) {
                $vault = $this->vault();
                $hash  = $vault->blindIndex($v['account_number']);
                if (hash_equals((string) $before['account_number_hash'], $hash)) {
                    $hash = null;   // same number typed again: nothing to change
                } else {
                    $this->refuseDuplicate($hash, $id);
                    $enc   = $vault->encrypt($v['account_number']);
                    $last4 = DataVault::last4($v['account_number']);
                }
            }
            $this->pdo->prepare(
                'UPDATE company_bank_accounts SET label = ?, bank_name = ?, account_holder = ?, ifsc = ?, branch = ?, account_type = ?,
                        bank_file_format = ?, account_number_enc = COALESCE(?, account_number_enc), account_number_last4 = ?,
                        account_number_hash = COALESCE(?, account_number_hash), updated_by = ?, updated_at = now()
                  WHERE id = ?',
            )->execute([$v['label'], $v['bank_name'], $v['account_holder'], $v['ifsc'], $v['branch'], $v['account_type'],
                $v['bank_file_format'], $enc, $last4, $hash, $actor['actor'], $id]);
            $after = $this->row($id, false);
            [$b, $a] = self::diff(self::snapshot($before), self::snapshot($after));
            if ($hash !== null) {
                // Recorded even when the new number ends in the same four digits.
                $b['account_number_masked'] = DataVault::mask((string) $before['account_number_last4']);
                $a['account_number_masked'] = DataVault::mask($last4);
                $a['account_number_changed'] = true;
            }
            if ($a !== []) {
                $this->audit->record($actor, 'bank_account.updated', 'company_bank_account', $id, $b, $a);
            }

            return self::publicRow($after);
        });
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function setDefault(int $id, array $actor): array
    {
        return $this->tx(function () use ($id, $actor): array {
            $row = $this->row($id, true);
            if (!$row['active']) {
                throw ApiException::conflict('An inactive account cannot be the default. Activate it first.');
            }
            if ($row['is_default']) {
                return self::publicRow($row);
            }
            $old = $this->pdo->query('SELECT id, label, account_number_last4 FROM company_bank_accounts WHERE is_default AND active FOR UPDATE')->fetch() ?: null;
            $this->pdo->exec('UPDATE company_bank_accounts SET is_default = false WHERE is_default');
            $this->pdo->prepare('UPDATE company_bank_accounts SET is_default = true, updated_by = ?, updated_at = now() WHERE id = ?')->execute([$actor['actor'], $id]);
            $this->audit->record($actor, 'bank_account.default_changed', 'company_bank_account', $id,
                $old === null ? ['default' => null] : ['default' => self::ref($old)],
                ['default' => self::ref($row)]);

            return self::publicRow($this->row($id, false));
        });
    }

    /**
     * Deactivate. The default account can only be deactivated together with a new default
     * (`new_default_id`) while another account is active; the only active account may be deactivated
     * (bank files are then refused until one is active again).
     *
     * @param array<string,mixed> $input {new_default_id?}
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function deactivate(int $id, array $input, array $actor): array
    {
        return $this->tx(function () use ($id, $input, $actor): array {
            $row = $this->row($id, true);
            if (!$row['active']) {
                throw ApiException::conflict('This account is already inactive.');
            }
            $others = $this->pdo->prepare('SELECT id, label, account_number_last4 FROM company_bank_accounts WHERE active AND id <> ? ORDER BY id FOR UPDATE');
            $others->execute([$id]);
            $others = $others->fetchAll();
            $newDefault = null;
            if ($row['is_default'] && $others !== []) {
                $want = $input['new_default_id'] ?? null;
                $want = is_int($want) || (is_string($want) && ctype_digit($want)) ? (int) $want : null;
                foreach ($others as $o) {
                    if ((int) $o['id'] === $want) {
                        $newDefault = $o;
                    }
                }
                if ($newDefault === null) {
                    throw ApiException::validation(
                        'This is the default account. Choose which active account becomes the default before deactivating it.',
                        ['new_default_id' => 'Pick one of the other active accounts.'],
                    );
                }
            }
            $this->pdo->prepare('UPDATE company_bank_accounts SET active = false, is_default = false, updated_by = ?, updated_at = now() WHERE id = ?')
                ->execute([$actor['actor'], $id]);
            if ($newDefault !== null) {
                $this->pdo->prepare('UPDATE company_bank_accounts SET is_default = true, updated_by = ?, updated_at = now() WHERE id = ?')
                    ->execute([$actor['actor'], (int) $newDefault['id']]);
            }
            $this->audit->record($actor, 'bank_account.deactivated', 'company_bank_account', $id,
                ['active' => true, 'is_default' => (bool) $row['is_default']],
                ['active' => false, 'is_default' => false] + ($newDefault !== null ? ['new_default' => self::ref($newDefault)] : [])
                    + ($others === [] ? ['note' => 'No active company bank account is left: bank files are refused until one is active.'] : []));

            return self::publicRow($this->row($id, false));
        });
    }

    /**
     * Re-activate. It becomes the default only when no other active account is.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function activate(int $id, array $actor): array
    {
        return $this->tx(function () use ($id, $actor): array {
            $row = $this->row($id, true);
            if ($row['active']) {
                throw ApiException::conflict('This account is already active.');
            }
            $hasDefault = (bool) $this->pdo->query('SELECT EXISTS (SELECT 1 FROM company_bank_accounts WHERE active AND is_default) AS x')->fetch()['x'];
            $this->pdo->prepare('UPDATE company_bank_accounts SET active = true, is_default = ?, updated_by = ?, updated_at = now() WHERE id = ?')
                ->execute([$hasDefault ? 'false' : 'true', $actor['actor'], $id]);
            $this->audit->record($actor, 'bank_account.activated', 'company_bank_account', $id,
                ['active' => false], ['active' => true, 'is_default' => !$hasDefault]);

            return self::publicRow($this->row($id, false));
        });
    }

    // ------------------------------------------------------------------ bank file (called inside PayoutService's transaction)

    /**
     * The account a bank file debits: $requested (an active account's id) or else the default. Refuses
     * when no account is active. The row is locked (FOR SHARE) until the caller's transaction ends.
     *
     * @return array{id:int, label:string, last4:string, number:string, bank_file_format:?string}
     */
    public function forBankFile(mixed $requested): array
    {
        $this->adoptLegacyIfPending();
        if ($requested !== null && $requested !== '') {
            $id = is_int($requested) || (is_string($requested) && ctype_digit($requested)) ? (int) $requested : 0;
            $stmt = $this->pdo->prepare('SELECT * FROM company_bank_accounts WHERE id = ? AND active FOR SHARE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw ApiException::validation('That is not an active company bank account.', ['bank_account_id' => 'Pick an active account from Settings → Bank accounts.']);
            }
        } else {
            $row = $this->pdo->query('SELECT * FROM company_bank_accounts WHERE active AND is_default FOR SHARE')->fetch();
            if (!$row) {
                throw ApiException::validation(
                    'No active company bank account. Add the company bank account in Settings → Bank accounts before generating a bank file.',
                    ['bank_account_id' => 'No active company bank account.'],
                );
            }
        }

        return [
            'id'               => (int) $row['id'],
            'label'            => (string) $row['label'],
            'last4'            => (string) $row['account_number_last4'],
            'number'           => $this->vault()->decrypt((string) $row['account_number_enc']),
            'bank_file_format' => $row['bank_file_format'] === null ? null : (string) $row['bank_file_format'],
        ];
    }

    // ------------------------------------------------------------------ the old setting

    /** Raw stored value of settings.debit_account_number ('' once adopted). */
    private function legacyValue(): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([self::LEGACY_SETTING]);
        $row = $stmt->fetch();

        return $row ? preg_replace('/\s+/', '', (string) $row['value']) ?? '' : '';
    }

    /** Adopt the old setting when there is one and the data key is available (else leave it for later). */
    public function adoptLegacyIfPending(): ?array
    {
        if ($this->legacyValue() === '' || ($this->vault === null && !DataVault::isConfigured())) {
            return null;
        }

        return $this->adoptLegacySetting();
    }

    /**
     * Move a non-empty settings.debit_account_number into company_bank_accounts as "Primary account"
     * (the default unless another active account already is), audit it, and blank the setting so the
     * number is no longer stored in clear. Idempotent; run by bin/migrate.php after migration 002 and on
     * first use. Fields the setting never had (bank name, IFSC, holder) are left empty for an admin to fill.
     *
     * @return array<string,mixed>|null the account the setting now lives in, or null when there was nothing to move
     */
    public function adoptLegacySetting(): ?array
    {
        $vault = $this->vault();

        return $this->tx(function () use ($vault): ?array {
            $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ? FOR UPDATE');
            $stmt->execute([self::LEGACY_SETTING]);
            $raw    = $stmt->fetch();
            $number = $raw ? (preg_replace('/\s+/', '', (string) $raw['value']) ?? '') : '';
            if ($number === '') {
                return null;
            }
            if (!preg_match('/^[0-9]{4,34}$/', $number)) {
                error_log('[finance] settings.debit_account_number is not a plain account number; not moved to company_bank_accounts.');

                return null;
            }
            $hash = $vault->blindIndex($number);
            $find = $this->pdo->prepare('SELECT * FROM company_bank_accounts WHERE account_number_hash = ?');
            $find->execute([$hash]);
            $row = $find->fetch() ?: null;
            if ($row === null) {
                $hasDefault = (bool) $this->pdo->query('SELECT EXISTS (SELECT 1 FROM company_bank_accounts WHERE active AND is_default) AS x')->fetch()['x'];
                $ins = $this->pdo->prepare(
                    "INSERT INTO company_bank_accounts (label, bank_name, account_holder, account_number_enc, account_number_last4, account_number_hash,
                         ifsc, branch, account_type, bank_file_format, is_default, active, source, created_by)
                     VALUES (?, NULL, '', ?, ?, ?, NULL, NULL, 'current', NULL, ?, true, 'legacy_setting', ?) RETURNING id",
                );
                $ins->execute([self::LEGACY_LABEL, $vault->encrypt($number), DataVault::last4($number), $hash, $hasDefault ? 'false' : 'true', self::MIGRATION_ACTOR['actor']]);
                $row = $this->row((int) $ins->fetch()['id'], false);
                $this->audit->record(self::MIGRATION_ACTOR, 'bank_account.migrated_from_setting', 'company_bank_account', (int) $row['id'],
                    ['setting' => self::LEGACY_SETTING, 'value' => DataVault::mask(DataVault::last4($number))], self::snapshot($row));
            }
            $this->pdo->prepare("UPDATE settings SET value = '', updated_at = now(), updated_by = ? WHERE key = ?")
                ->execute([self::MIGRATION_ACTOR['actor'], self::LEGACY_SETTING]);

            return self::publicRow($row);
        });
    }

    // ------------------------------------------------------------------ validation (pure)

    /**
     * Validate a create ($existing null) or an edit (merged over $existing). Bank name, IFSC and account
     * holder are required for every account written through the API — only the row adopted from the old
     * setting may lack them, until an admin edits it.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing database row
     * @return array{label:string, bank_name:string, account_holder:string, ifsc:string, branch:?string, account_type:string,
     *               bank_file_format:?string, account_number:?string, is_default:bool}
     */
    public static function validate(array $input, ?array $existing): array
    {
        $errors = [];
        $has    = static fn (string $k): bool => array_key_exists($k, $input);
        $str    = static function (mixed $v): ?string {
            if ($v === null) {
                return '';
            }

            return is_scalar($v) && !is_bool($v) ? trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '') : null;
        };
        $pick = static function (string $k) use ($input, $existing, $has, $str): ?string {
            if ($has($k)) {
                return $str($input[$k]);
            }

            return $existing === null ? '' : (string) ($existing[$k] ?? '');
        };

        $text = static function (string $k, string $label, int $max, bool $required) use ($pick, &$errors): ?string {
            $v = $pick($k);
            if ($v === null) {
                $errors[$k] = 'Must be text.';

                return null;
            }
            if ($v === '') {
                if ($required) {
                    $errors[$k] = "{$label} is required.";
                }

                return null;
            }
            if (mb_strlen($v) > $max) {
                $errors[$k] = "At most {$max} characters.";
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $v)) {
                $errors[$k] = 'Contains control characters.';
            }

            return $v;
        };

        $label  = $text('label', 'A label', 60, true);
        $bank   = $text('bank_name', 'The bank name', 80, true);
        $holder = $text('account_holder', 'The account holder name', 100, true);
        $branch = $text('branch', 'Branch', 80, false);

        $ifsc = $pick('ifsc');
        $ifsc = $ifsc === null ? null : strtoupper(str_replace(' ', '', $ifsc));
        if ($ifsc === null || $ifsc === '') {
            $errors['ifsc'] = 'The IFSC is required.';
        } elseif (!preg_match(self::IFSC_PATTERN, $ifsc)) {
            $errors['ifsc'] = 'An IFSC is 11 characters: 4 letters, 0, then 6 letters or digits (e.g. HDFC0001234).';
        }

        $type = $pick('account_type');
        if ($type === '' && $existing === null) {
            $type = 'current';
        }
        if (!array_key_exists((string) $type, self::ACCOUNT_TYPES)) {
            $errors['account_type'] = 'One of: ' . implode(', ', array_keys(self::ACCOUNT_TYPES)) . '.';
        }

        $format = $has('bank_file_format') ? $str($input['bank_file_format']) : ($existing === null ? '' : (string) ($existing['bank_file_format'] ?? ''));
        if ($format === null || ($format !== '' && !array_key_exists($format, BankFileFormatter::FORMATS))) {
            $errors['bank_file_format'] = 'Empty (use the Settings default) or one of: ' . implode(', ', array_keys(BankFileFormatter::FORMATS)) . '.';
        }

        $number  = null;
        $rawNum  = $has('account_number') ? $input['account_number'] : null;
        $rawConf = $has('account_number_confirm') ? $input['account_number_confirm'] : null;
        $digits  = static fn (mixed $v): ?string => is_string($v) || is_int($v) ? preg_replace('/[\s-]+/', '', (string) $v) : null;
        $n       = $digits($rawNum);
        if ($existing === null || ($n !== null && $n !== '')) {
            if ($n === null || $n === '') {
                $errors['account_number'] = 'The account number is required.';
            } elseif (!preg_match(self::NUMBER_PATTERN, $n)) {
                $errors['account_number'] = 'An account number is 6 to 18 digits.';
            } elseif ($digits($rawConf) !== $n) {
                $errors['account_number_confirm'] = 'The two account numbers do not match.';
            } else {
                $number = $n;
            }
        }

        $isDefault = $existing === null && $has('is_default') && filter_var($input['is_default'], FILTER_VALIDATE_BOOLEAN);

        if ($errors !== []) {
            throw ApiException::validation('Some bank account details are invalid.', $errors);
        }

        return [
            'label'            => (string) $label,
            'bank_name'        => (string) $bank,
            'account_holder'   => (string) $holder,
            'ifsc'             => (string) $ifsc,
            'branch'           => $branch,
            'account_type'     => (string) $type,
            'bank_file_format' => $format === '' ? null : $format,
            'account_number'   => $number,
            'is_default'       => $isDefault,
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * What the API shows: never the number, only "XXXXXX" + last four.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function publicRow(array $r): array
    {
        $format = $r['bank_file_format'] ?? null;

        return [
            'id'                     => (int) $r['id'],
            'label'                  => (string) $r['label'],
            'bank_name'              => $r['bank_name'],
            'account_holder'         => (string) $r['account_holder'],
            'account_number_masked'  => DataVault::mask((string) $r['account_number_last4']),
            'account_number_last4'   => (string) $r['account_number_last4'],
            'ifsc'                   => $r['ifsc'],
            'branch'                 => $r['branch'],
            'account_type'           => (string) $r['account_type'],
            'account_type_label'     => self::ACCOUNT_TYPES[(string) $r['account_type']] ?? (string) $r['account_type'],
            'bank_file_format'       => $format,
            'bank_file_format_label' => $format === null ? null : (BankFileFormatter::FORMATS[$format]['label'] ?? $format),
            'is_default'             => (bool) $r['is_default'],
            'active'                 => (bool) $r['active'],
            'incomplete'             => ($r['bank_name'] ?? null) === null || ($r['ifsc'] ?? null) === null || trim((string) $r['account_holder']) === '',
            'source'                 => (string) $r['source'],
            'created_by'             => (string) $r['created_by'],
            'created_at'             => $r['created_at'],
            'updated_by'             => $r['updated_by'],
            'updated_at'             => $r['updated_at'],
        ];
    }

    /**
     * Audit snapshot (masked).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function snapshot(array $r): array
    {
        return [
            'label'                 => $r['label'],
            'bank_name'             => $r['bank_name'],
            'account_holder'        => $r['account_holder'],
            'account_number_masked' => DataVault::mask((string) $r['account_number_last4']),
            'ifsc'                  => $r['ifsc'],
            'branch'                => $r['branch'],
            'account_type'          => $r['account_type'],
            'bank_file_format'      => $r['bank_file_format'],
            'is_default'            => (bool) $r['is_default'],
            'active'                => (bool) $r['active'],
        ];
    }

    /** @param array<string,mixed> $r @return array{id:int, label:string, account_number_masked:string} */
    private static function ref(array $r): array
    {
        return ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'account_number_masked' => DataVault::mask((string) $r['account_number_last4'])];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{0:array<string,mixed>, 1:array<string,mixed>} only the keys that changed
     */
    private static function diff(array $before, array $after): array
    {
        $b = [];
        $a = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $b[$k] = $before[$k] ?? null;
                $a[$k] = $v;
            }
        }

        return [$b, $a];
    }

    /** @return array<string,mixed> */
    private function row(int $id, bool $lock): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM company_bank_accounts WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Bank account not found.');
        }
        $row['is_default'] = (bool) $row['is_default'];
        $row['active']     = (bool) $row['active'];

        return $row;
    }

    private function refuseDuplicate(string $hash, ?int $exceptId): void
    {
        $stmt = $this->pdo->prepare('SELECT id, label, active FROM company_bank_accounts WHERE account_number_hash = ? AND id IS DISTINCT FROM ?');
        $stmt->execute([$hash, $exceptId]);
        $dup = $stmt->fetch();
        if ($dup) {
            throw ApiException::conflict(sprintf(
                'This account number is already registered as "%s"%s.',
                $dup['label'],
                $dup['active'] ? '' : ' (inactive — activate it instead)',
            ));
        }
    }

    /**
     * One transaction (or the caller's, when already inside one), serialised with an advisory lock so two
     * admins cannot interleave default changes.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->query('SELECT pg_advisory_xact_lock(' . self::LOCK_KEY . ')');
            $out = $fn();
            if ($own) {
                $this->pdo->commit();
            }

            return $out;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
