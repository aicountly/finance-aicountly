<?php
declare(strict_types=1);

/**
 * Aicountly Academy fees housekeeping (cron, every 5 minutes; docs/ACADEMY_PAYMENTS.md § Runbook). CLI only.
 *
 *   php bin/academy-fees-cron.php [--limit=50]
 *
 *   1. Re-verify gateway payments and refunds still pending (the provider's API was slow or down at webhook time).
 *   2. Issue invoices of verified payments still waiting (blocked_config after the seller profile / series is fixed).
 *   3. Resume link creations interrupted mid-way (looked up at the provider first: never created twice).
 *   4. Re-read open links past their expiry (an expiry webhook may never come).
 *   5. Deliver queued payment events to Engage (AIC-S2S signed), with back-off.
 *
 * Does nothing (exit 0) while the database or the schema (migration 005) is absent. Prints one summary line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
finance_load_env(dirname(__DIR__) . '/.env');

use App\Database\Database;
use App\Services\AcademyFees\EngagePaymentEventOutbox;
use App\Services\AcademyFees\GatewayConnectionService;
use App\Services\AcademyFees\GatewayPaymentService;
use App\Services\AcademyFees\PaymentLinkService;

$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

if (!Database::isConfigured()) {
    echo "Finance database is not configured; nothing to do.\n";
    exit(0);
}

try {
    $pdo = Database::pdo();
    if (!$pdo->query("SELECT to_regclass('public.fin_payment_links') IS NOT NULL AS ok")->fetch()['ok']) {
        echo "Academy fees schema not migrated yet (php bin/migrate.php); nothing to do.\n";
        exit(0);
    }
    $conn     = new GatewayConnectionService($pdo);
    $payments = new GatewayPaymentService($pdo, $conn);
    $h        = $payments->housekeeping(new PaymentLinkService($pdo, $conn, $payments), $limit);
    $o        = (new EngagePaymentEventOutbox($pdo))->deliver($limit * 2);
    printf("academy-fees: verified=%d still_pending=%d refunds_verified=%d invoices_issued=%d invoices_blocked=%d links_resumed=%d links_synced=%d errors=%d"
        . " engage=%s attempted=%d delivered=%d retrying=%d dead=%d\n",
        $h['verified'], $h['still_pending'], $h['refunds_verified'], $h['invoices_issued'], $h['invoices_blocked'], $h['links_resumed'], $h['links_synced'], $h['errors'],
        $o['configured'] ? 'on' : 'not_configured', $o['attempted'], $o['delivered'], $o['retrying'], $o['dead']);
} catch (\Throwable $e) {
    fwrite(STDERR, 'academy-fees cron failed: ' . $e->getMessage() . "\n");
    exit(1);
}
