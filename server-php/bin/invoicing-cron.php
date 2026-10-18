<?php
declare(strict_types=1);

/**
 * Invoicing housekeeping (cron, every 5 minutes; docs/INVOICING.md § Runbook). CLI only.
 *
 *   1. Retry verified offline payments whose invoice waits on configuration (invoice_state = blocked_config).
 *   2. Render the PDF of documents stored as HTML only (PDF service was unconfigured or down at issue), from exactly
 *      the HTML stored at issue. A stored PDF is never replaced.
 *   3. Purge AIC-S2S nonces older than 600 s, rate windows older than an hour and access-log rows older than 180 days.
 *
 * Does nothing (exit 0) while the database or the invoicing schema is absent, so it is safe to add before invoicing
 * is configured. Prints one summary line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
finance_load_env(dirname(__DIR__) . '/.env');

use App\Database\Database;
use App\Services\Invoicing\InvoiceService;

if (!Database::isConfigured()) {
    echo "Finance database is not configured; nothing to do.\n";
    exit(0);
}

try {
    $pdo = Database::pdo();
    if (!$pdo->query("SELECT to_regclass('public.fin_invoices') IS NOT NULL AS ok")->fetch()['ok']) {
        echo "Invoicing schema not migrated yet (php bin/migrate.php); nothing to do.\n";
        exit(0);
    }
    $svc     = new InvoiceService($pdo);
    $retried = $svc->retryBlocked();
    $pdfs    = $svc->renderPending();
    $purged  = $pdo->exec("DELETE FROM fin_s2s_nonces WHERE seen_at < now() - interval '600 seconds'")
        + $pdo->exec("DELETE FROM fin_s2s_rate WHERE window_start < now() - interval '1 hour'")
        + $pdo->exec("DELETE FROM fin_s2s_access_log WHERE occurred_at < now() - interval '180 days'");
    printf("invoicing: issued=%d still_blocked=%d pdf_stored=%d pdf_failed=%d purged=%d\n",
        $retried['issued'], $retried['blocked'], $pdfs['stored'], $pdfs['failed'], (int) $purged);
} catch (\Throwable $e) {
    fwrite(STDERR, 'invoicing cron failed: ' . $e->getMessage() . "\n");
    exit(1);
}
