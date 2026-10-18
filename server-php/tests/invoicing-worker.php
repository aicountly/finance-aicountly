<?php
declare(strict_types=1);

/**
 * Concurrency helper for tests/invoicing-db.php (CLI only, test database only): issues invoices through the
 * phase-B entry point InvoiceService::issueForPayment() with a test PaymentInvoiceSource, from a separate process.
 *
 *   php tests/invoicing-worker.php <first_payment_id> <count> <cmp_id>
 *
 * Connection from FINANCE_DB_* in the environment. Prints one JSON line per invoice: {"payment_id","doc_no","seq"}.
 */

if (PHP_SAPI !== 'cli' || getenv('FINANCE_INVOICING_WORKER') !== '1') {
    fwrite(STDERR, "test helper only\n");
    exit(2);
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Database\Database;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PaymentInvoiceSource;

[$_, $first, $count, $cmp] = $argv + [null, '1', '1', '1'];

final class WorkerSource implements PaymentInvoiceSource
{
    public function __construct(private int $cmpId)
    {
    }

    public function forPayment(int $paymentId): ?array
    {
        return [
            'payment_id' => $paymentId, 'status' => 'verified', 'cmp_id' => $this->cmpId, 'buyer_uuid' => 'uuid-' . $paymentId,
            'buyer'      => ['legal_name' => 'Concurrent Buyer ' . $paymentId, 'gstin' => null, 'state_code' => '27', 'address' => '1 Test Road, Pune',
                'pincode' => '411001', 'country' => 'IN', 'email' => null],
            'lines'      => [['item_code' => 'books.business', 'item_kind' => 'subscription', 'description' => 'Smart Books — Business, monthly',
                'sac' => '997331', 'gst_rate' => '18', 'price_basis' => 'inclusive', 'quantity' => 1, 'amount_paise' => 118000]],
            'amount_paise' => 118000, 'gateway' => 'testpay', 'method' => 'upi', 'reference' => 'pay_' . $paymentId, 'paid_on' => date('Y-m-d'),
        ];
    }
}

$pdo = Database::pdo();
$svc = new InvoiceService($pdo);
for ($i = 0; $i < (int) $count; $i++) {
    $pid = (int) $first + $i;
    $inv = $svc->issueForPayment($pid, new WorkerSource((int) $cmp));
    $seq = $pdo->query('SELECT seq_no FROM fin_invoices WHERE id = ' . $inv['id'])->fetchColumn();
    echo json_encode(['payment_id' => $pid, 'doc_no' => $inv['doc_no'], 'seq' => (int) $seq]), "\n";
}
