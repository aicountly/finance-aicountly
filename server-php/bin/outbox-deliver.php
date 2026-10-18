<?php
declare(strict_types=1);

/**
 * Deliver queued payout-status callbacks to Engage (table outbox).
 *
 *   php bin/outbox-deliver.php [--limit=100]
 *
 * Cron, every 5 minutes (see docs/DEPLOYMENT.md § Cron):
 *   *\/5 * * * * php /home/<user>/public_html/api/bin/outbox-deliver.php >> /home/<user>/logs/finance-outbox.log 2>&1
 *
 * Needs ENGAGE_API_BASE_URL and ENGAGE_FINANCE_KEY (≥ 32 chars) in api/.env. Without them it
 * sends nothing, marks nothing delivered, prints why and exits 0 — events wait in the outbox.
 * CLI only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
finance_load_env(dirname(__DIR__) . '/.env');

use App\Database\Database;
use App\Services\Payouts\OutboxService;

$limit = 100;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

if (!Database::isConfigured()) {
    fwrite(STDERR, "[finance-outbox] database not configured; nothing to deliver.\n");
    exit(0);
}

try {
    $summary = (new OutboxService(Database::pdo()))->deliver($limit);
} catch (\Throwable $e) {
    fwrite(STDERR, '[finance-outbox] ' . date('c') . ' failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$summary['configured']) {
    fwrite(STDERR, '[finance-outbox] ' . date('c') . " ENGAGE_API_BASE_URL / ENGAGE_FINANCE_KEY not configured; events stay queued.\n");
    exit(0);
}

printf("[finance-outbox] %s attempted=%d delivered=%d retrying=%d dead=%d\n",
    date('c'), $summary['attempted'], $summary['delivered'], $summary['retrying'], $summary['dead']);
