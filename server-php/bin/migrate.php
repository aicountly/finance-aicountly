<?php
declare(strict_types=1);

/**
 * Apply pending database migrations (database/migrations/NNN_*.sql).
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list applied / pending, change nothing
 *   php bin/migrate.php --require-db   exit 1 (instead of 0) when FINANCE_DB_* is not configured
 *
 * After the SQL migrations it moves the old setting `debit_account_number` (if
 * set) into company_bank_accounts as "Primary account" (migration 002) — that
 * needs FINANCE_DATA_KEY to encrypt the number; without it the move waits and
 * happens on first use once the key is set.
 *
 * Reads server-php/.env (FINANCE_DB_HOST / PORT / NAME / USER / PASSWORD, FINANCE_DATA_KEY).
 * The deploy workflow runs it on the server after every API upload
 * (`php api/bin/migrate.php`). CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
finance_load_env(dirname(__DIR__) . '/.env');

use App\Database\Database;
use App\Database\Migrator;
use App\Services\CompanyBankAccountService;
use App\Services\DataVault;

$args = array_slice($argv, 1);

if (!Database::isConfigured()) {
    fwrite(STDERR, "Finance database is not configured (set FINANCE_DB_HOST, FINANCE_DB_NAME, FINANCE_DB_USER, FINANCE_DB_PASSWORD in api/.env). No migration applied; payouts stay unavailable (503) until it is.\n");
    exit(in_array('--require-db', $args, true) ? 1 : 0);
}

try {
    $pdo = Database::pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cannot connect to the Finance database: ' . $e->getMessage() . "\n");
    exit(1);
}

$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');

if (in_array('--status', $args, true)) {
    $applied = $migrator->applied();
    foreach ($migrator->available() as $m) {
        $state = isset($applied[$m['version']])
            ? ($applied[$m['version']] === $m['checksum'] ? 'applied' : 'applied (FILE CHANGED SINCE)')
            : 'pending';
        echo str_pad($m['version'], 40) . $state . "\n";
    }
    exit(0);
}

try {
    $done = $migrator->migrate(static function (string $line): void {
        echo $line, "\n";
    });
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo $done === [] ? "Database is up to date.\n" : count($done) . " migration(s) applied.\n";

// Migration 002: the single debit-account setting becomes the first company bank account.
try {
    $legacy = $pdo->query("SELECT value FROM settings WHERE key = 'debit_account_number' AND btrim(value) <> ''")->fetch();
    if ($legacy && finance_table_exists($pdo, 'public.company_bank_accounts')) {
        if (!DataVault::isConfigured()) {
            fwrite(STDERR, "Settings still hold a debit account number: it moves to Bank accounts (encrypted) once FINANCE_DATA_KEY is set — re-run this script then.\n");
        } else {
            $row = (new CompanyBankAccountService($pdo))->adoptLegacySetting();
            if ($row !== null) {
                echo "Moved the debit account from Settings into Bank accounts as \"{$row['label']}\" ({$row['account_number_masked']}"
                    . ($row['is_default'] ? ', default' : '') . "). Add its bank name, IFSC and holder in Settings → Bank accounts.\n";
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not move the debit account setting into Bank accounts: ' . $e->getMessage() . "\n");
    exit(1);
}

function finance_table_exists(\PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL AS ok');
    $stmt->execute([$name]);

    return (bool) $stmt->fetch()['ok'];
}
