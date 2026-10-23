<?php
declare(strict_types=1);

/**
 * Subscription invoicing end to end: PostgreSQL (the finance_test database payouts-db.php created and migrated), the
 * real API under `php -S` (staff JWT routes and the AIC-S2S Manage routes), a stub Gotenberg (fake-gotenberg.php),
 * two worker processes for concurrent numbering (invoicing-worker.php) and bin/invoicing-cron.php.
 * Included by tests/run.php after payouts-db.php (uses serve(), http(), api(), tok(), $T, $pdo, $payEnv, $dbEnv…).
 */

use App\Security\AicS2s;
use App\Services\Invoicing\CreditNoteService;
use App\Services\Invoicing\DocumentNumbering;
use App\Services\Invoicing\GotenbergClient;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PaymentInvoiceSource;
use App\Services\Invoicing\SellerProfileService;
use App\Support\FiscalYear;

echo "Invoicing (PostgreSQL + php -S)\n";

if (!isset($pdo, $papi, $payEnv)) {
    echo "  SKIP PostgreSQL unreachable (see above)\n";

    return;
}

const MANAGE_KEY_ID   = 'manage-read-2026-10';
const MANAGE_SECRET   = 'manage-to-finance-read-secret-0123456789abcdef';
const MANAGE_ISSUE_ID = 'manage-issue';
const MANAGE_ISSUE_SECRET = 'manage-to-finance-issue-secret-0123456789abcd';
const ENGAGE_READ_SECRET  = 'engage-to-finance-read-secret-0123456789abcdef';
const RL_KEY_ID       = 'manage-rl';
const RL_SECRET       = 'manage-rate-limited-secret-0123456789abcdefgh';

$payoutCounts = static fn (): string => json_encode($pdo->query(
    'SELECT (SELECT count(*) FROM payout_batches) b, (SELECT count(*) FROM payout_items) i, (SELECT count(*) FROM tds_ledger) t,
            (SELECT count(*) FROM outbox) o, (SELECT count(*) FROM bank_files) f, (SELECT count(*) FROM settings) s',
)->fetch());
$payoutsBefore = $payoutCounts();

$gotState = $cacheDir . '/gotenberg-state.json';
$gotLog   = $cacheDir . '/gotenberg-requests.log';
file_put_contents($gotState, '{}');
file_put_contents($gotLog, '');
$fakeGot = serve(__DIR__ . '/fake-gotenberg.php', ['FAKE_GOTENBERG_STATE' => $gotState, 'FAKE_GOTENBERG_LOG' => $gotLog]);

$s2sEnv = [
    AicS2s::secretEnvName('manage', MANAGE_KEY_ID)   => MANAGE_SECRET,
    AicS2s::secretEnvName('manage', MANAGE_ISSUE_ID) => MANAGE_ISSUE_SECRET,
    AicS2s::scopeEnvName('manage', MANAGE_ISSUE_ID)  => 'issue',
    AicS2s::secretEnvName('manage', 'manage-short')  => 'too-short-to-be-a-secret',
    AicS2s::secretEnvName('engage', 'engage-read')   => ENGAGE_READ_SECRET,
];
$iapi        = serve($serverRoot . '/public/index.php', $payEnv + $s2sEnv + ['GOTENBERG_URL' => $fakeGot, 'FINANCE_AUTHZ_CACHE_DIR' => $cacheDir . '/inv']);
$iapiNoPdf   = serve($serverRoot . '/public/index.php', $payEnv + $s2sEnv + ['FINANCE_AUTHZ_CACHE_DIR' => $cacheDir . '/inv-nopdf']);
$iapiSandbox = serve($serverRoot . '/public/index.php', $payEnv + $s2sEnv + ['FINANCE_S2S_ENVIRONMENT' => 'sandbox', 'FINANCE_AUTHZ_CACHE_DIR' => $cacheDir . '/inv-sb']);
$iapiLowRate = serve($serverRoot . '/public/index.php', $payEnv + [AicS2s::secretEnvName('manage', RL_KEY_ID) => RL_SECRET, 'FINANCE_S2S_READ_PER_MINUTE' => '6',
    'GOTENBERG_URL' => $fakeGot, 'FINANCE_AUTHZ_CACHE_DIR' => $cacheDir . '/inv-rl']);

$FY    = FiscalYear::current();
$YY    = substr($FY, 2, 2) . '-' . substr($FY, 5, 2);
$TODAY = FiscalYear::today();

$SELLER_GSTIN = testGstin('27', 'AAJCA4321K');
$BUYER = [
    'A' => ['legal_name' => 'Acme Traders Pvt Ltd', 'gstin' => testGstin('27', 'AACCA1111B'), 'state_code' => '27', 'address' => "4th Floor, Lotus Park\nAndheri East, Mumbai",
        'pincode' => '400069', 'email' => 'accounts@acme.test'],
    'B' => ['legal_name' => 'Bengaluru Labs LLP', 'gstin' => testGstin('29', 'AAKFB2222C'), 'state_code' => '29', 'address' => '12 MG Road, Bengaluru',
        'pincode' => '560001', 'email' => null],
    'C' => ['legal_name' => 'Ravi Sharma', 'gstin' => null, 'state_code' => '07', 'address' => 'B-12 Lajpat Nagar, New Delhi', 'pincode' => '110024'],
    'D' => ['legal_name' => 'Kanpur Mills (unregistered)', 'gstin' => null, 'state_code' => '09', 'address' => 'Civil Lines, Kanpur', 'pincode' => '208001'],
];

/** Signed GET to the Manage read API. */
function s2s(string $base, string $path, array $query, array $opt = []): array
{
    $qs     = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $signed = '/v1/inbound/manage/' . $path;
    $h = AicS2s::sign($opt['secret'] ?? MANAGE_SECRET, $opt['client'] ?? 'manage', $opt['key'] ?? MANAGE_KEY_ID, $opt['env'] ?? 'local', 'GET',
        $signed, $opt['signed_query'] ?? $qs, '', $opt['ts'] ?? null, $opt['nonce'] ?? null, $opt['actor'] ?? 'owner-uuid-101', $opt['request_id'] ?? null);
    foreach ($opt['override'] ?? [] as $k => $v) {
        if ($v === null) {
            unset($h[$k]);
        } else {
            $h[$k] = $v;
        }
    }
    $lines = array_merge(array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($h), $h), $opt['headers'] ?? []);

    return http($opt['method'] ?? 'GET', $base . '/api' . $signed . ($qs !== '' ? '?' . $qs : ''), $lines);
}

function s2sCode(array $res): ?string
{
    return $res['json']['error']['code'] ?? null;
}

/** An offline payment body: one subscription line. */
function offlineBody(int $cmp, array $buyer, string $amount, string $utr, array $line = [], array $extra = []): array
{
    global $TODAY;

    return $extra + [
        'cmp_id' => $cmp, 'buyer_uuid' => 'owner-' . $cmp, 'buyer' => $buyer,
        'lines'  => [$line + ['item_code' => 'books.business', 'item_kind' => 'subscription', 'description' => 'Smart Books — Business, annual', 'sac' => '997331',
            'gst_rate' => '18', 'price_basis' => 'inclusive', 'period_start' => $TODAY,
            'period_end' => (new DateTimeImmutable($TODAY))->modify('+1 year -1 day')->format('Y-m-d'), 'quantity' => 1, 'amount' => $amount]],
        'amount' => $amount, 'method' => 'neft', 'utr' => $utr, 'received_on' => $TODAY,
    ];
}

function runCron(array $env): string
{
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/invoicing-cron.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return $out;
}

function gotRequests(): int
{
    global $gotLog;

    return count(file($gotLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}

function invoiceRow(int $id): array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM fin_invoices WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: [];
}

function pdfRow(string $col, int $id): array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT html, html_sha256, pdf, pdf_sha256, render_attempts, last_error FROM fin_doc_pdfs WHERE {$col} = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: [];
    if (isset($row['pdf']) && is_resource($row['pdf'])) {
        $row['pdf'] = stream_get_contents($row['pdf']);
    }

    return $row;
}

/** Expect one SQL statement to be refused by a trigger / constraint whose message contains $needle. */
function refusedSql(string $sql, string $needle, string $what): void
{
    global $pdo;
    $msg = '';
    try {
        $pdo->exec($sql);
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    check(str_contains($msg, $needle), "{$what}: refused ({$needle}) — got: " . ($msg ?: 'no error'));
}

final class TestPaymentSource implements PaymentInvoiceSource
{
    /** @param array<int, array<string,mixed>> $payments */
    public function __construct(public array $payments)
    {
    }

    public function forPayment(int $paymentId): ?array
    {
        return $this->payments[$paymentId] ?? null;
    }

    public static function payment(int $id, int $cmp, string $status = 'verified', int $amount = 118000, array $buyer = []): array
    {
        return [
            'payment_id' => $id, 'status' => $status, 'cmp_id' => $cmp, 'buyer_uuid' => 'owner-' . $cmp,
            'buyer' => $buyer + ['legal_name' => 'Gateway Buyer ' . $id, 'gstin' => null, 'state_code' => '27', 'address' => '1 Test Road, Pune', 'pincode' => '411001'],
            'lines' => [['item_code' => 'books.business', 'item_kind' => 'subscription', 'description' => 'Smart Books — Business, monthly', 'sac' => '997331',
                'gst_rate' => '18', 'price_basis' => 'inclusive', 'quantity' => 1, 'amount_paise' => 118000]],
            'amount_paise' => $amount, 'gateway' => 'razorpay', 'method' => 'upi', 'reference' => 'pay_TEST' . $id, 'paid_on' => FiscalYear::today(),
        ];
    }
}

// In-process issuing logs its ops alerts (blocked_config…) with error_log: keep them out of the test output.
ini_set('error_log', $cacheDir . '/invoicing-error.log');

$fakeGotClient = new GotenbergClient(['base_url' => $fakeGot, 'auth_mode' => 'none', 'username' => '', 'password' => '', 'bearer_token' => '', 'timeout' => 10]);
$ids = [];

test('dormant until configured: health reports it, the Manage API answers 503, staff get seller_not_configured; payouts untouched', function () use ($papi, $T, $BUYER): void {
    $h = http('GET', $papi . '/api/health')['json']['data'];
    same('dormant', $h['invoicing']['status'], 'invoicing dormant');
    same(['schema' => true, 'seller_profile' => false, 'series_current_fy' => false, 'pdf_renderer' => false, 'manage_inbound' => false,
        'issuance_blocked' => false, 'pdfs_pending' => false], $h['invoicing']['checks'], 'invoicing checks (booleans only)');
    same(in_array(false, $h['checks'], true) ? 'misconfigured' : 'ok', $h['status'], 'top-level status is still the payout readiness');
    check(!array_key_exists('invoicing', $h['checks']), 'payout checks unchanged');

    $r = s2s($papi, 'documents', ['cmp_id' => 101]);
    same([503, false, 'inbound_not_configured', true], [$r['status'], $r['json']['success'], s2sCode($r), $r['json']['error']['retryable']], 'no secret → 503');
    check(is_string($r['json']['request_id'] ?? null) && $r['json']['request_id'] !== '', 'request_id in the envelope');

    $p = api('POST', 'v1/offline-payments/preview', $T['alice'], offlineBody(101, $BUYER['A'], '11800.00', 'X'));
    same([422, 'seller_not_configured'], [$p['status'], $p['json']['errors']['code'] ?? null], 'no seller profile → 422 seller_not_configured');
    $s = api('GET', 'v1/invoicing/settings', $T['viewer'])['json']['data'];
    same([null, false, false, false], [$s['profile'], $s['readiness']['seller_profile'], $s['readiness']['series']['tax_invoice'], $s['can_edit']], 'settings: nothing yet');
});

test('seller profile: finance_admin only; saving retires the previous one (history); rows are immutable', function () use ($iapi, $T, $pdo, $SELLER_GSTIN): void {
    $profile = ['legal_name' => 'Aicountly Interactive Services Private Limited', 'gstin' => $SELLER_GSTIN, 'address_line1' => '501 Test Towers', 'address_line2' => 'Lower Parel',
        'city' => 'Mumbai', 'pincode' => '400013', 'email' => 'billing@aicountly.test', 'signatory_name' => 'Old Signatory', 'signatory_designation' => 'Director',
        'effective_from' => '2026-04-01'];
    same(403, api('PUT', 'v1/invoicing/seller-profile', $T['carol'], $profile, [], $iapi)['status'], 'approver cannot');
    same(403, api('PUT', 'v1/invoicing/seller-profile', $T['viewer'], $profile, [], $iapi)['status'], 'viewer cannot');
    $bad = api('PUT', 'v1/invoicing/seller-profile', $T['dave'], ['gstin' => substr($SELLER_GSTIN, 0, 14) . ($SELLER_GSTIN[14] === 'A' ? 'B' : 'A')] + $profile, [], $iapi);
    same([422, true], [$bad['status'], isset($bad['json']['errors']['gstin'])], 'bad GSTIN check character → 422');
    $first = api('PUT', 'v1/invoicing/seller-profile', $T['dave'], $profile, [], $iapi);
    same([200, '27', 'AAJCA4321K'], [$first['status'], $first['json']['data']['state_code'], $first['json']['data']['pan']], 'saved; state and PAN from the GSTIN');
    $second = api('PUT', 'v1/invoicing/seller-profile', $T['dave'], ['signatory_name' => 'Rahul Gupta'] + $profile, [], $iapi)['json']['data'];
    $s = api('GET', 'v1/invoicing/settings', $T['viewer'], null, [], $iapi)['json']['data'];
    same([$second['id'], 'Rahul Gupta', 2, false], [$s['profile']['id'], $s['profile']['signatory_name'], count($s['history']), $s['history'][1]['active']], 'new active profile; old one retired');
    refusedSql("UPDATE fin_seller_profile SET legal_name = 'Someone Else' WHERE active", 'immutable', 'editing the profile in place');
    refusedSql('DELETE FROM fin_seller_profile', 'never deleted', 'deleting a profile');
    same(1, (int) $pdo->query('SELECT count(*) FROM fin_seller_profile WHERE active')->fetchColumn(), 'one active profile');
    $h = http('GET', $iapi . '/api/health')['json']['data']['invoicing'];
    same(['incomplete', true, false, true, true], [$h['status'], $h['checks']['seller_profile'], $h['checks']['series_current_fy'], $h['checks']['pdf_renderer'],
        $h['checks']['manage_inbound']], 'health: profile set, series missing');
});

test('verified payment without a series waits as blocked_config (health alert); nothing is numbered', function () use ($iapi, $T, $pdo, $BUYER, &$ids): void {
    $pv = api('POST', 'v1/offline-payments/preview', $T['alice'], offlineBody(101, $BUYER['A'], '11800.00', 'X'), [], $iapi);
    same([200, 'intra_state', 1000000, 90000, 90000, 0, 1180000], [$pv['status'], $pv['json']['data']['supply_type'], $pv['json']['data']['totals']['taxable_paise'],
        $pv['json']['data']['totals']['cgst_paise'], $pv['json']['data']['totals']['sgst_paise'], $pv['json']['data']['totals']['igst_paise'],
        $pv['json']['data']['totals']['total_paise']], 'preview: intra-state split');
    $r = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(101, $BUYER['A'], '11800.00', 'HDFCN52026101800100'), [], $iapi);
    same([201, 'recorded', 'not_issued'], [$r['status'], $r['json']['data']['status'], $r['json']['data']['invoice_state']], 'recorded');
    $ids['P0'] = $r['json']['data']['id'];
    $v = api('POST', "v1/offline-payments/{$ids['P0']}/verify", $T['carol'], [], [], $iapi);
    same([200, 'verified', 'blocked_config', null], [$v['status'], $v['json']['data']['status'], $v['json']['data']['invoice_state'], $v['json']['data']['invoice']], 'verified, invoice blocked');
    check(str_contains((string) $v['json']['data']['invoice_error'], 'series'), 'reason names the series: ' . $v['json']['data']['invoice_error']);
    same(0, (int) $pdo->query('SELECT count(*) FROM fin_invoices')->fetchColumn(), 'no invoice row');
    same(true, http('GET', $iapi . '/api/health')['json']['data']['invoicing']['checks']['issuance_blocked'], 'health: issuance_blocked');
    $retry = api('POST', "v1/offline-payments/{$ids['P0']}/retry-invoice", $T['carol'], [], [], $iapi);
    same([200, 'blocked_config'], [$retry['status'], $retry['json']['data']['invoice_state']], 'retry: still blocked');
});

test('series: settings gate, ≤ 16 characters, unique prefix per FY, editable until used', function () use ($iapi, $T, $FY, $YY, &$ids): void {
    same(403, api('POST', 'v1/invoicing/series', $T['carol'], ['fy' => $FY, 'doc_type' => 'tax_invoice', 'prefix' => "AIC/{$YY}/", 'width' => 6], [], $iapi)['status'], 'approver cannot');
    $long = api('POST', 'v1/invoicing/series', $T['dave'], ['fy' => $FY, 'doc_type' => 'credit_note', 'prefix' => "AIC-CN/{$YY}/", 'width' => 6], [], $iapi);
    same([422, true], [$long['status'], str_contains((string) ($long['json']['errors']['width'] ?? ''), '16')], 'AIC-CN/26-27/000004 is 19 characters → 422');
    $inv = api('POST', 'v1/invoicing/series', $T['dave'], ['fy' => $FY, 'doc_type' => 'tax_invoice', 'prefix' => strtolower("aic/{$YY}/"), 'width' => 6], [], $iapi);
    same([201, "AIC/{$YY}/000001"], [$inv['status'], $inv['json']['data']['next_doc_no']], 'tax invoice series');
    same(409, api('POST', 'v1/invoicing/series', $T['dave'], ['fy' => $FY, 'doc_type' => 'credit_note', 'prefix' => "AIC/{$YY}/", 'width' => 6], [], $iapi)['status'], 'same prefix in the FY → 409');
    same(409, api('POST', 'v1/invoicing/series', $T['dave'], ['fy' => $FY, 'doc_type' => 'tax_invoice', 'prefix' => "INV/{$YY}/", 'width' => 6], [], $iapi)['status'], 'second invoice series → 409');
    $cn = api('POST', 'v1/invoicing/series', $T['dave'], ['fy' => $FY, 'doc_type' => 'credit_note', 'prefix' => "CN/{$YY}/", 'width' => 4], [], $iapi);
    same(201, $cn['status'], 'credit note series');
    $ids['cn_series'] = $cn['json']['data']['id'];
    $ids['inv_series'] = $inv['json']['data']['id'];
    $e = api('PUT', "v1/invoicing/series/{$ids['cn_series']}", $T['dave'], ['width' => 6], [], $iapi);
    same([200, "CN/{$YY}/000001"], [$e['status'], $e['json']['data']['next_doc_no']], 'unused series can still change');
    same(true, http('GET', $iapi . '/api/health')['json']['data']['invoicing']['checks']['series_current_fy'], 'health: series for the current FY');
});

test('the cron issues the blocked invoice once configured — number 1, PDF stored', function () use ($dbEnv, $fakeGot, $YY, &$ids, $pdo): void {
    $out = runCron($dbEnv + ['GOTENBERG_URL' => $fakeGot]);
    check(str_contains($out, 'issued=1') && str_contains($out, 'still_blocked=0'), 'cron: ' . trim($out));
    $p = $pdo->query("SELECT invoice_state, invoice_attempts FROM fin_offline_payments WHERE id = {$ids['P0']}")->fetch();
    same(['issued', 3], [$p['invoice_state'], (int) $p['invoice_attempts']], 'issued on the third attempt');
    $inv = $pdo->query("SELECT * FROM fin_invoices WHERE offline_payment_id = {$ids['P0']}")->fetch();
    same(["AIC/{$YY}/000001", 1, 'system:invoicing'], [$inv['doc_no'], (int) $inv['seq_no'], $inv['issued_by']], 'first number of the series');
    $ids['INV0'] = (int) $inv['id'];
    check(str_starts_with((string) (pdfRow('invoice_id', $ids['INV0'])['pdf'] ?? ''), '%PDF-'), 'PDF stored');
});

test('offline payment four-eyes: maker records, a different person verifies → invoice issued with its PDF', function () use ($iapi, $T, $pdo, $BUYER, $YY, &$ids, $fakeGotClient): void {
    same(403, api('POST', 'v1/offline-payments', $T['viewer'], offlineBody(101, $BUYER['A'], '11800.00', 'HDFCN52026101800101'), [], $iapi)['status'], 'viewer cannot record');
    $mm = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(101, $BUYER['A'], '11000.00', 'HDFCN52026101800101', ['amount' => '11800.00']), [], $iapi);
    same([422, 'amount_mismatch'], [$mm['status'], $mm['json']['errors']['code'] ?? null], 'amount received ≠ invoice total → 422');
    check(str_contains((string) $mm['json']['errors']['amount'], '11800.00'), 'says the invoice total');
    $bad = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(101, ['gstin' => substr($BUYER['A']['gstin'], 0, 14) . ($BUYER['A']['gstin'][14] === 'A' ? 'B' : 'A')] + $BUYER['A'], '11800.00', 'HDFCN52026101800101'), [], $iapi);
    same([422, true], [$bad['status'], isset($bad['json']['errors']['buyer.gstin'])], 'invalid buyer GSTIN → 422');

    $p1 = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(101, $BUYER['A'], '11800.00', 'hdfcn 52026101800101'), [], $iapi);
    same([201, 'HDFCN52026101800101', 'alice@aicountly.com'], [$p1['status'], $p1['json']['data']['utr'], $p1['json']['data']['recorded_by']], 'recorded, UTR normalised');
    $ids['P1'] = $p1['json']['data']['id'];
    same([409, 'duplicate_utr'], [api('POST', 'v1/offline-payments', $T['alice'], offlineBody(101, $BUYER['A'], '11800.00', 'HDFCN52026101800101'), [], $iapi)['status'],
        api('POST', 'v1/offline-payments', $T['alice'], offlineBody(101, $BUYER['A'], '11800.00', 'HDFCN52026101800101'), [], $iapi)['json']['errors']['code'] ?? null], 'same UTR twice → 409');
    same(403, api('POST', "v1/offline-payments/{$ids['P1']}/verify", $T['alice'], [], [], $iapi)['status'], 'an accountant cannot verify');

    // Exclusive price, buyer in Karnataka: IGST on top.
    $p2 = api('POST', 'v1/offline-payments', $T['bob'], offlineBody(102, $BUYER['B'], '23600.00', 'ICICN52026101800102', ['price_basis' => 'exclusive', 'amount' => '20000.00']), [], $iapi);
    same(201, $p2['status'], 'bob records P2');
    $ids['P2'] = $p2['json']['data']['id'];
    $self = api('POST', "v1/offline-payments/{$ids['P2']}/verify", $T['bob'], [], [], $iapi);
    same([403, 'four_eyes'], [$self['status'], $self['json']['errors']['code'] ?? null], 'the maker cannot verify');
    $d = api('GET', "v1/offline-payments/{$ids['P2']}", $T['bob'], null, [], $iapi)['json']['data'];
    same([false, true], [$d['can']['verify'], is_string($d['can']['blocked_reason'])], 'UI: bob may not verify, and is told why');
    refusedSql("UPDATE fin_offline_payments SET status = 'verified', verified_by = 'BOB@aicountly.com', verified_at = now() WHERE id = {$ids['P2']}",
        'fin_offline_payments_four_eyes', 'database four-eyes');
    refusedSql("UPDATE fin_offline_payments SET amount_paise = 1 WHERE id = {$ids['P2']}", 'immutable', 'changing a recorded amount');

    $v1 = api('POST', "v1/offline-payments/{$ids['P1']}/verify", $T['carol'], [], [], $iapi);
    same([200, 'issued', "AIC/{$YY}/000002"], [$v1['status'], $v1['json']['data']['invoice_state'], $v1['json']['data']['invoice']['doc_no']], 'carol verifies → invoice 2');
    $ids['INV1'] = $v1['json']['data']['invoice']['id'];
    same(409, api('POST', "v1/offline-payments/{$ids['P1']}/verify", $T['carol'], [], [], $iapi)['status'], 'cannot verify twice');
    $inv = api('GET', "v1/invoices/{$ids['INV1']}", $T['viewer'], null, [], $iapi)['json']['data'];
    same(['intra_state', '27', 1000000, 90000, 90000, 0, 1180000, 'Rupees Eleven Thousand Eight Hundred Only', 'HDFCN52026101800101', 'issued'],
        [$inv['supply_type'], $inv['place_of_supply']['code'], $inv['totals']['taxable_paise'], $inv['totals']['cgst_paise'], $inv['totals']['sgst_paise'],
            $inv['totals']['igst_paise'], $inv['total_paise'], $inv['amount_in_words'], $inv['payment']['reference'], $inv['status']], 'invoice face');
    same(['9.00', '9.00', '997331', 'Rahul Gupta'], [$inv['lines'][0]['cgst_rate'], $inv['lines'][0]['sgst_rate'], $inv['lines'][0]['sac'], $inv['seller']['signatory_name']], 'rates, SAC, seller snapshot');
    same([true, false, true], [$inv['rendering']['pdf_stored'], $inv['can']['request_credit_note'], $inv['can']['approve_credit_note'] === false], 'PDF stored; viewer has no actions');
    $pdf = pdfRow('invoice_id', $ids['INV1']);
    same([hash('sha256', (string) $pdf['pdf']), hash('sha256', (string) $pdf['html'])], [$pdf['pdf_sha256'], $pdf['html_sha256']], 'checksums of the stored bytes');
    check(str_contains((string) $pdf['pdf'], 'html-sha256: ' . $pdf['html_sha256']), 'PDF rendered from exactly the stored HTML');
    check(str_contains((string) $pdf['html'], 'Acme Traders Pvt Ltd') && str_contains((string) $pdf['html'], "AIC/{$YY}/000002"), 'HTML is this invoice');

    $v2 = api('POST', "v1/offline-payments/{$ids['P2']}/verify", $T['carol'], [], [], $iapi)['json']['data'];
    $ids['INV2'] = $v2['invoice']['id'];
    $inv2 = invoiceRow($ids['INV2']);
    same(["AIC/{$YY}/000003", 'inter_state', '29', 2000000, 360000, 0, 2360000], [$inv2['doc_no'], $inv2['supply_type'], $inv2['place_of_supply'], (int) $inv2['taxable_paise'],
        (int) $inv2['igst_paise'], (int) $inv2['cgst_paise'], (int) $inv2['total_paise']], 'Karnataka buyer: IGST 18% on top');

    $p3 = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(103, $BUYER['C'], '1180.00', 'UPI5202610180103', ['amount' => '1180.00'], ['method' => 'upi']), [], $iapi);
    $ids['INV3'] = api('POST', "v1/offline-payments/{$p3['json']['data']['id']}/verify", $T['carol'], [], [], $iapi)['json']['data']['invoice']['id'];
    $inv3 = invoiceRow($ids['INV3']);
    same([null, '07', 'inter_state', 18000], [$inv3['buyer_gstin'], $inv3['place_of_supply'], $inv3['supply_type'], (int) $inv3['igst_paise']], 'unregistered Delhi buyer: POS = billing state');

    $p4 = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(104, $BUYER['D'], '236000.00', 'SBIN52026101800104', ['amount' => '236000.00']), [], $iapi);
    $ids['INV4'] = api('POST', "v1/offline-payments/{$p4['json']['data']['id']}/verify", $T['dave'], [], [], $iapi)['json']['data']['invoice']['id'];
    same("AIC/{$YY}/000005", invoiceRow($ids['INV4'])['doc_no'], 'consecutive numbers');

    $p5 = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(103, $BUYER['C'], '1180.00', 'CHQ000105', ['amount' => '1180.00'], ['method' => 'cheque']), [], $iapi);
    same(422, api('POST', "v1/offline-payments/{$p5['json']['data']['id']}/reject", $T['carol'], [], [], $iapi)['status'], 'reject needs a reason');
    $rj = api('POST', "v1/offline-payments/{$p5['json']['data']['id']}/reject", $T['carol'], ['reason' => 'Cheque bounced'], [], $iapi);
    same(['rejected', 'Cheque bounced'], [$rj['json']['data']['status'], $rj['json']['data']['reject_reason']], 'rejected with a reason');
    same(201, api('POST', 'v1/offline-payments', $T['alice'], offlineBody(103, $BUYER['C'], '1180.00', 'CHQ000105', ['amount' => '1180.00'], ['method' => 'cheque']), [], $iapi)['status'],
        'a rejected UTR can be recorded again');

    $svc = new InvoiceService($pdo, null, $fakeGotClient);
    $again = $svc->issueForOfflinePayment($ids['P1'], InvoiceService::SYSTEM);
    same(['issued', $ids['INV1'], false], [$again['state'], $again['invoice']['id'], $again['invoice']['created']], 'issuing again returns the same invoice');
    same(5, (int) $pdo->query('SELECT count(*) FROM fin_invoices')->fetchColumn(), 'still five invoices');
    $actions = array_column($pdo->query("SELECT DISTINCT action FROM audit_log WHERE entity_type IN ('offline_payment','invoice','seller_profile','doc_series')")->fetchAll(), 'action');
    foreach (['offline_payment.recorded', 'offline_payment.verified', 'offline_payment.rejected', 'invoice.issued', 'document.pdf_stored',
              'invoicing.seller_profile_saved', 'invoicing.series_created', 'invoicing.series_updated'] as $a) {
        check(in_array($a, $actions, true), "audited: {$a}");
    }
});

test('issued documents are immutable (database triggers); totals must equal the lines', function () use ($pdo, &$ids): void {
    $id = $ids['INV1'];
    refusedSql("UPDATE fin_invoices SET total_paise = total_paise + 100, taxable_paise = taxable_paise + 100 WHERE id = {$id}", 'immutable', 'changing the total');
    refusedSql("UPDATE fin_invoices SET buyer_legal_name = 'Someone Else' WHERE id = {$id}", 'immutable', 'changing the buyer');
    refusedSql("UPDATE fin_invoices SET doc_no = 'AIC/X/1' WHERE id = {$id}", 'immutable', 'renumbering');
    refusedSql("DELETE FROM fin_invoices WHERE id = {$id}", 'never deleted', 'deleting an invoice');
    refusedSql("UPDATE fin_invoice_lines SET taxable_paise = 1 WHERE invoice_id = {$id}", 'immutable', 'changing a line');
    refusedSql("DELETE FROM fin_invoice_lines WHERE invoice_id = {$id}", 'immutable', 'deleting a line');
    refusedSql("INSERT INTO fin_invoice_lines (invoice_id, line_no, item_code, item_kind, description, sac, gst_rate, price_basis, amount_paise, taxable_paise,
                    cgst_paise, sgst_paise, igst_paise, total_paise) VALUES ({$id}, 9, 'x', 'x', 'extra', '997331', 18, 'inclusive', 100, 100, 0, 0, 0, 100)",
        'only be added when the document is created', 'adding a line later');
    refusedSql("UPDATE fin_doc_pdfs SET html = 'tampered' WHERE invoice_id = {$id}", 'immutable', 'changing the stored HTML');
    refusedSql("UPDATE fin_doc_pdfs SET pdf = 'x'::bytea, pdf_sha256 = repeat('a', 64) WHERE invoice_id = {$id}", 'never replaced', 'replacing the stored PDF');
    refusedSql("UPDATE fin_invoices SET credited_paise = 100, status = 'partially_credited' WHERE id = {$id}; UPDATE fin_invoices SET credited_paise = 0, status = 'issued' WHERE id = {$id}",
        'cannot go down', 'credited amount going back');
    $pdo->exec("UPDATE fin_invoices SET credited_paise = 0 WHERE id = {$id}"); // no-op update is allowed
    same('issued', invoiceRow($id)['status'], 'unchanged');

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO fin_invoices (document_id, cmp_id, series_id, fy, seq_no, doc_no, doc_date, source, source_ref, payment_id, seller_profile_id, seller,
                    seller_gstin, seller_state_code, buyer_legal_name, buyer_state_code, buyer_address, place_of_supply, supply_type, taxable_paise, cgst_paise,
                    sgst_paise, igst_paise, total_paise, amount_in_words, payment_method, payment_reference, paid_on, issued_by)
                SELECT 'fin_inv_0123456789ABCDEFGHJKMNPQRS', cmp_id, series_id, fy, 999999, 'ZZ/999999', doc_date, 'payment', 'payment:999999999', 999999999,
                       seller_profile_id, seller, seller_gstin, seller_state_code, buyer_legal_name, buyer_state_code, buyer_address, place_of_supply, supply_type,
                       taxable_paise, cgst_paise, sgst_paise, igst_paise, total_paise, amount_in_words, payment_method, payment_reference, paid_on, issued_by
                  FROM fin_invoices WHERE id = {$id}");
    $msg = '';
    try {
        $pdo->commit();
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    check(str_contains($msg, 'totals do not match its lines'), 'an invoice without lines cannot commit: ' . $msg);
    same(0, (int) $pdo->query("SELECT count(*) FROM fin_invoices WHERE doc_no = 'ZZ/999999'")->fetchColumn(), 'nothing stored');
});

test('numbering is gapless under concurrency (two processes) and a rolled-back issue leaves no gap', function () use ($pdo, $dbEnv, $fakeGot, $FY, &$ids): void {
    $env = $dbEnv + ['FINANCE_INVOICING_WORKER' => '1', 'GOTENBERG_URL' => $fakeGot, 'PATH' => (string) getenv('PATH')];
    $start = static function (int $first, int $count) use ($env) {
        $proc = proc_open([PHP_BINARY, __DIR__ . '/invoicing-worker.php', (string) $first, (string) $count, '105'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

        return [$proc, $pipes];
    };
    $finish = static function (array $w): array {
        [$proc, $pipes] = $w;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        same(0, proc_close($proc), 'worker exit status: ' . $err);

        return array_map(static fn (string $l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) $out))));
    };
    $before = (int) $pdo->query("SELECT max(seq_no) FROM fin_invoices WHERE series_id = {$ids['inv_series']}")->fetchColumn();
    $a = $start(900001, 8);
    $b = $start(900101, 8);
    $rows = array_merge($finish($a), $finish($b));
    $seqs = array_column($rows, 'seq');
    sort($seqs);
    same(range($before + 1, $before + 16), $seqs, 'two processes, 16 invoices: consecutive numbers, none twice, none skipped');
    same(16, count(array_unique(array_column($rows, 'doc_no'))), 'unique document numbers');

    // A transaction takes the next number and rolls back; the waiting worker gets that same number.
    $pdo->beginTransaction();
    $held = (new DocumentNumbering($pdo))->next($FY, 'tax_invoice');
    same($before + 17, $held['seq_no'], 'number taken inside an open transaction');
    $w = $start(900201, 1);
    usleep(700_000);
    same(true, proc_get_status($w[0])['running'], 'the other issuer waits on the series lock');
    $pdo->rollBack();
    $got = $finish($w);
    same($before + 17, $got[0]['seq'], 'after the rollback the waiting issuer gets the same number: no gap');
    $all = array_map('intval', array_column($pdo->query("SELECT seq_no FROM fin_invoices WHERE series_id = {$ids['inv_series']} ORDER BY seq_no")->fetchAll(), 'seq_no'));
    same(range(1, count($all)), $all, 'the whole series is 1…n without gaps');
});

test('FY rollover: a new FY needs its own series and starts at 1; the old FY carries on', function () use ($pdo, $FY, $fakeGotClient): void {
    [, $fyEnd] = FiscalYear::range($FY);
    $nextStart = (new DateTimeImmutable($fyEnd))->modify('+1 day')->format('Y-m-d');
    $nextFy = FiscalYear::forDate($nextStart);
    $nyy = substr($nextFy, 2, 2) . '-' . substr($nextFy, 5, 2);
    $src = new TestPaymentSource([
        910001 => TestPaymentSource::payment(910001, 106),
        910002 => TestPaymentSource::payment(910002, 106),
        910003 => TestPaymentSource::payment(910003, 106),
    ]);
    $at = static fn (string $day) => new InvoiceService($pdo, static fn () => $day, $fakeGotClient);

    $last = $at($fyEnd)->issueForPayment(910001, $src);
    $row  = invoiceRow($last['id']);
    same([$FY, $fyEnd], [$row['fy'], $row['doc_date']], '31 March: still the old FY');
    $e = throwsApi(422, static fn () => $at($nextStart)->issueForPayment(910002, $src), '1 April without a series for the new FY');
    same('series_not_configured', $e->errorCode(), 'series_not_configured');
    same(0, (int) $pdo->query('SELECT count(*) FROM fin_invoices WHERE payment_id = 910002')->fetchColumn(), 'nothing issued');
    (new SellerProfileService($pdo))->createSeries(['fy' => $nextFy, 'doc_type' => 'tax_invoice', 'prefix' => "AIC/{$nyy}/", 'width' => 6], ['actor' => 'dave@aicountly.com']);
    $first = $at($nextStart)->issueForPayment(910002, $src);
    same(["AIC/{$nyy}/000001", $nextFy], [$first['doc_no'], invoiceRow($first['id'])['fy']], 'new FY starts at 1');
    $after = $at($fyEnd)->issueForPayment(910003, $src);
    same((int) $row['seq_no'] + 1, (int) invoiceRow($after['id'])['seq_no'], 'the old FY continues where it was');
});

test('issueForPayment (phase B entry point, test-only source): verified only, idempotent, bad data → blocked_data', function () use ($pdo, $fakeGotClient): void {
    $src = new TestPaymentSource([
        920001 => TestPaymentSource::payment(920001, 107, 'pending'),
        920002 => TestPaymentSource::payment(920002, 107),
        920003 => TestPaymentSource::payment(920003, 107, 'verified', 100000),
        920004 => TestPaymentSource::payment(920004, 107, 'verified', 118000, ['pincode' => '', 'address' => '']),
    ]);
    $svc = new InvoiceService($pdo, null, $fakeGotClient);
    throwsApi(404, static fn () => $svc->issueForPayment(920099, $src), 'unknown payment');
    same('payment_not_verified', throwsApi(409, static fn () => $svc->issueForPayment(920001, $src), 'pending payment')->errorCode(), 'not verified');
    $one = $svc->issueForPayment(920002, $src);
    same(true, $one['created'], 'issued');
    $two = $svc->issueForPayment(920002, $src);
    same([false, $one['id'], $one['doc_no']], [$two['created'], $two['id'], $two['doc_no']], 'the same payment again → the same invoice');
    $row = invoiceRow($one['id']);
    same(['payment', 'payment:920002', 920002, 'razorpay', 'pay_TEST920002'], [$row['source'], $row['source_ref'], (int) $row['payment_id'], $row['payment_gateway'],
        $row['payment_reference']], 'payment block');
    $e = throwsApi(422, static fn () => $svc->issueForPayment(920003, $src), 'under-paid');
    same('blocked_data', $e->errorCode(), 'amount mismatch → blocked_data');
    $e = throwsApi(422, static fn () => $svc->issueForPayment(920004, $src), 'no address');
    same(['blocked_data', true], [$e->errorCode(), isset($e->errors()['buyer.address'])], 'incomplete buyer → blocked_data');
    same(0, (int) $pdo->query('SELECT count(*) FROM fin_invoices WHERE payment_id IN (920001, 920003, 920004)')->fetchColumn(), 'nothing issued for them');
});

test('credit notes: four-eyes, partial then full, never more than the invoice; rejected ones stay out', function () use ($iapi, $T, $pdo, $YY, &$ids): void {
    $inv = api('GET', "v1/invoices/{$ids['INV1']}", $T['alice'], null, [], $iapi)['json']['data'];
    $lineId = $inv['lines'][0]['id'];
    same(true, $inv['can']['request_credit_note'], 'accountant may request');
    same(403, api('POST', "v1/invoices/{$ids['INV1']}/credit-notes", $T['viewer'], ['kind' => 'full', 'reason' => 'x'], [], $iapi)['status'], 'viewer cannot');
    same(422, api('POST', "v1/invoices/{$ids['INV1']}/credit-notes", $T['alice'], ['kind' => 'partial', 'reason' => 'x'], [], $iapi)['status'], 'partial needs lines');
    same(422, api('POST', "v1/invoices/{$ids['INV1']}/credit-notes", $T['alice'], ['kind' => 'full', 'reason' => ' '], [], $iapi)['status'], 'reason required');
    $over = api('POST', "v1/invoices/{$ids['INV1']}/credit-notes", $T['alice'], ['kind' => 'partial', 'reason' => 'x', 'lines' => [['invoice_line_id' => $lineId, 'amount' => '11800.01']]], [], $iapi);
    same([422, true], [$over['status'], str_contains((string) ($over['json']['errors']['lines[0].amount'] ?? ''), '11800.00')], 'more than the line → 422');

    $cn1 = api('POST', "v1/invoices/{$ids['INV1']}/credit-notes", $T['alice'], ['kind' => 'partial', 'reason' => 'Goodwill: 15 days of outage',
        'lines' => [['invoice_line_id' => $lineId, 'amount' => '590.00']]], [], $iapi);
    $c = $cn1['json']['data'];
    same([201, 'pending_approval', null, 59000, 50000, 4500, 4500], [$cn1['status'], $c['status'], $c['doc_no'], $c['total_paise'], $c['totals']['taxable_paise'],
        $c['totals']['cgst_paise'], $c['totals']['sgst_paise']], 'pending, no number yet; ₹590 incl. 18% split');
    same(403, api('POST', "v1/credit-notes/{$c['id']}/approve", $T['alice'], [], [], $iapi)['status'], 'an accountant cannot approve');

    $cn2 = api('POST', "v1/invoices/{$ids['INV1']}/credit-notes", $T['bob'], ['kind' => 'full', 'reason' => 'Refund: subscription cancelled'], [], $iapi)['json']['data'];
    same(1121000, $cn2['total_paise'], 'full = what is left after the pending ₹590');
    $own = api('POST', "v1/credit-notes/{$cn2['id']}/approve", $T['bob'], [], [], $iapi);
    same([403, 'four_eyes'], [$own['status'], $own['json']['errors']['code'] ?? null], 'the requester cannot approve');
    same(false, api('GET', "v1/credit-notes/{$cn2['id']}", $T['bob'], null, [], $iapi)['json']['data']['can']['approve'], 'UI: bob may not approve his own');
    same(409, api('POST', "v1/invoices/{$ids['INV1']}/credit-notes", $T['alice'], ['kind' => 'full', 'reason' => 'again'], [], $iapi)['status'], 'nothing left to request');
    same(1, count(array_filter(api('GET', 'v1/credit-notes?status=pending_approval', $T['viewer'], null, [], $iapi)['json']['data'], static fn ($x) => $x['id'] === $c['id'])), 'listed as pending');

    $a1 = api('POST', "v1/credit-notes/{$c['id']}/approve", $T['carol'], [], [], $iapi);
    same([200, 'issued', "CN/{$YY}/000001", 'carol@aicountly.com'], [$a1['status'], $a1['json']['data']['status'], $a1['json']['data']['doc_no'], $a1['json']['data']['approved_by']], 'carol issues CN 1');
    same(['partially_credited', 59000], [invoiceRow($ids['INV1'])['status'], (int) invoiceRow($ids['INV1'])['credited_paise']], 'invoice partially credited');
    same(409, api('POST', "v1/credit-notes/{$c['id']}/approve", $T['dave'], [], [], $iapi)['status'], 'cannot approve twice');
    $a2 = api('POST', "v1/credit-notes/{$cn2['id']}/approve", $T['dave'], [], [], $iapi);
    same("CN/{$YY}/000002", $a2['json']['data']['doc_no'], 'CN 2');
    $ids['CN1'] = $c['id'];
    $ids['CN2'] = $cn2['id'];
    $sum = $pdo->query("SELECT sum(taxable_paise) t, sum(cgst_paise) c, sum(sgst_paise) s, sum(igst_paise) i, sum(total_paise) tot FROM fin_credit_notes WHERE invoice_id = {$ids['INV1']} AND status = 'issued'")->fetch();
    same([1000000, 90000, 90000, 0, 1180000], [(int) $sum['t'], (int) $sum['c'], (int) $sum['s'], (int) $sum['i'], (int) $sum['tot']], 'fully credited: every tax head nets to zero');
    same(['credited', 1180000], [invoiceRow($ids['INV1'])['status'], (int) invoiceRow($ids['INV1'])['credited_paise']], 'invoice credited');
    same(true, pdfRow('credit_note_id', $ids['CN2'])['pdf'] !== null, 'credit note PDF stored');
    check(str_contains((string) pdfRow('credit_note_id', $ids['CN1'])['html'], 'Against invoice'), 'credit note names the original invoice');

    $inv2 = api('GET', "v1/invoices/{$ids['INV2']}", $T['alice'], null, [], $iapi)['json']['data'];
    $r = api('POST', "v1/invoices/{$ids['INV2']}/credit-notes", $T['alice'], ['kind' => 'partial', 'reason' => 'Price correction',
        'lines' => [['invoice_line_id' => $inv2['lines'][0]['id'], 'amount' => '1000.00']]], [], $iapi)['json']['data'];
    same([84746, 15254, 0, 0], [$r['totals']['taxable_paise'], $r['totals']['igst_paise'], $r['totals']['cgst_paise'], $r['totals']['sgst_paise']], 'inter-state partial: IGST round(1000 × 18/118) = ₹152.54');
    same(422, api('POST', "v1/credit-notes/{$r['id']}/reject", $T['carol'], [], [], $iapi)['status'], 'reject needs a reason');
    same('rejected', api('POST', "v1/credit-notes/{$r['id']}/reject", $T['carol'], ['reason' => 'Wrong amount'], [], $iapi)['json']['data']['status'], 'rejected');
    same(409, api('POST', "v1/credit-notes/{$r['id']}/approve", $T['dave'], [], [], $iapi)['status'], 'a rejected note cannot be approved');
    $ids['CN_REJECTED_DOC'] = $r['document_id'];
    refusedSql("UPDATE fin_credit_notes SET total_paise = 1 WHERE id = {$ids['CN1']}", 'immutable', 'changing an issued credit note');
    refusedSql("UPDATE fin_credit_notes SET reason = 'x' WHERE id = {$r['id']}", 'immutable', 'changing a rejected credit note');
    refusedSql("DELETE FROM fin_credit_notes WHERE id = {$ids['CN1']}", 'never deleted', 'deleting a credit note');

    $late = new CreditNoteService($pdo, static fn () => '2099-01-01');
    same('credit_note_deadline_passed', throwsApi(422, static fn () => $late->request($ids['INV2'], ['kind' => 'full', 'reason' => 'late'], ['actor' => 'alice@aicountly.com']), 'after 30 Nov')->errorCode(), 's.34 deadline');
    $actions = array_column($pdo->query("SELECT DISTINCT action FROM audit_log WHERE entity_type = 'credit_note'")->fetchAll(), 'action');
    foreach (['credit_note.requested', 'credit_note.issued', 'credit_note.rejected', 'document.pdf_stored'] as $x) {
        check(in_array($x, $actions, true), "audited: {$x}");
    }
});

test('Manage read API: signed, per company, cursor-paged; another company\'s document is a 404', function () use ($iapi, $pdo, $YY, $FY, &$ids): void {
    $l = s2s($iapi, 'documents', ['cmp_id' => 101, 'limit' => 25], ['request_id' => 'req-0001-abcdef']);
    same(200, $l['status'], 'list');
    same(['success', 'data', 'page', 'request_id'], array_keys($l['json']), 'contract envelope');
    same('req-0001-abcdef', $l['json']['request_id'], 'request id echoed');
    check(str_contains($l['headers'], 'X-AIC-Request-Id: req-0001-abcdef'), 'and as a header');
    same(["CN/{$YY}/000002", "CN/{$YY}/000001", "AIC/{$YY}/000002", "AIC/{$YY}/000001"], array_column($l['json']['data'], 'doc_no'), 'cmp 101 only: doc_date DESC, id DESC');
    same(['next_cursor' => null, 'limit' => 25], $l['json']['page'], 'last page');
    $row = $l['json']['data'][2];
    same(['tax_invoice', 'credited', 1180000, 'Smart Books — Business, annual', $FY], [$row['doc_type'], $row['status'], $row['total_paise'], $row['summary'], $row['fy']], 'invoice row');
    same(["CN/{$YY}/000001", "CN/{$YY}/000002"], array_column($row['related'], 'doc_no'), 'related credit notes');
    same("AIC/{$YY}/000002", $l['json']['data'][0]['related'][0]['doc_no'], 'a credit note points at its invoice');

    $p1 = s2s($iapi, 'documents', ['cmp_id' => 101, 'limit' => 3]);
    same([3, true], [count($p1['json']['data']), is_string($p1['json']['page']['next_cursor'])], 'page 1 of 2');
    $p2 = s2s($iapi, 'documents', ['cmp_id' => 101, 'limit' => 3, 'cursor' => $p1['json']['page']['next_cursor']]);
    same([["AIC/{$YY}/000001"], null], [array_column($p2['json']['data'], 'doc_no'), $p2['json']['page']['next_cursor']], 'page 2, last');
    same(2, count(s2s($iapi, 'documents', ['cmp_id' => 101, 'doc_type' => 'credit_note'])['json']['data']), 'doc_type filter');
    same([], s2s($iapi, 'documents', ['cmp_id' => 101, 'fy' => '2019-20'])['json']['data'], 'other FY: empty list, not 404');
    same([], s2s($iapi, 'documents', ['cmp_id' => 999])['json']['data'], 'company without documents: empty list');
    foreach ([['cmp_id' => 'abc'], [], ['cmp_id' => 101, 'limit' => 101], ['cmp_id' => 101, 'doc_type' => 'receipt'], ['cmp_id' => 101, 'cursor' => 'garbage'], ['cmp_id' => 101, 'fy' => '2026']] as $q) {
        $r = s2s($iapi, 'documents', $q);
        same([400, 'bad_request', false], [$r['status'], s2sCode($r), $r['json']['error']['retryable']], 'bad query ' . json_encode($q));
    }

    $docId = invoiceRow($ids['INV1'])['document_id'];
    $d = s2s($iapi, "documents/{$docId}", ['cmp_id' => 101]);
    $doc = $d['json']['data'];
    same([200, "AIC/{$YY}/000002", 'tax_invoice', '27', 'Maharashtra', '997331', 'Rupees Eleven Thousand Eight Hundred Only', 'HDFCN52026101800101', null, null],
        [$d['status'], $doc['doc_no'], $doc['doc_type'], $doc['place_of_supply']['code'], $doc['place_of_supply']['name'], $doc['lines'][0]['sac'], $doc['amount_in_words'],
            $doc['payment']['reference'], $doc['irn'], $doc['original_invoice']], 'document face');
    same([pdfRow('invoice_id', $ids['INV1'])['pdf_sha256'], 'Aicountly Interactive Services Private Limited', 'AAJCA4321K', 'Acme Traders Pvt Ltd'],
        [$doc['pdf_sha256'], $doc['seller']['legal_name'], $doc['seller']['pan'], $doc['buyer']['legal_name']], 'seller, buyer, pdf_sha256');
    foreach (['id', 'source', 'issued_by', 'credit_notes', 'remaining_paise'] as $k) {
        check(!array_key_exists($k, $doc), "no internal field {$k}");
    }
    check(!array_key_exists('id', $doc['lines'][0]), 'no internal line id');
    $cnDoc = s2s($iapi, 'documents/' . $pdo->query("SELECT document_id FROM fin_credit_notes WHERE id = {$ids['CN1']}")->fetchColumn(), ['cmp_id' => 101])['json']['data'];
    same(['credit_note', "AIC/{$YY}/000002", 'Goodwill: 15 days of outage', null], [$cnDoc['doc_type'], $cnDoc['original_invoice']['doc_no'], $cnDoc['reason'], $cnDoc['payment']], 'credit note face');
    check(!array_key_exists('requested_by', $cnDoc) && !array_key_exists('id', $cnDoc['original_invoice']), 'no staff fields on a credit note');

    $other   = s2s($iapi, "documents/{$docId}", ['cmp_id' => 102]);
    $missing = s2s($iapi, 'documents/fin_inv_0000000000000000000000ZZZZ', ['cmp_id' => 101]);
    same([404, 'not_found'], [$other['status'], s2sCode($other)], 'another company\'s invoice → 404');
    same(array_diff_key($missing['json'], ['request_id' => 1]), array_diff_key($other['json'], ['request_id' => 1]), 'answered exactly like a missing one');
    same(404, s2s($iapi, "documents/{$ids['CN_REJECTED_DOC']}", ['cmp_id' => 102])['status'], 'a rejected credit note does not exist for Manage');
    same(404, s2s($iapi, 'documents/../../health', ['cmp_id' => 101])['status'], 'no path tricks');
    $n = (int) $pdo->query("SELECT count(*) FROM fin_s2s_access_log WHERE key_id = '" . MANAGE_KEY_ID . "' AND cmp_id = 102 AND document_id = '{$docId}' AND http_status = 404")->fetchColumn();
    same(1, $n, 'reads go to the access log (with the 404)');
});

test('Manage PDF: the bytes stored at issue (no re-render), ETag + no-store, every download audited', function () use ($iapi, $pdo, $YY, &$ids): void {
    $docId  = invoiceRow($ids['INV1'])['document_id'];
    $stored = pdfRow('invoice_id', $ids['INV1']);
    $calls  = gotRequests();
    $r = s2s($iapi, "documents/{$docId}/pdf", ['cmp_id' => 101], ['actor' => 'f3a9c0de-owner']);
    same(200, $r['status'], 'PDF');
    same([$stored['pdf'], $calls], [$r['body'], gotRequests()], 'exactly the stored bytes; Gotenberg not called');
    check((bool) preg_match('#Content-Type: application/pdf#i', $r['headers']), 'application/pdf');
    check(str_contains($r['headers'], 'Content-Disposition: attachment; filename="AIC-' . $YY . '-000002.pdf"'), 'file name from the number');
    check(str_contains($r['headers'], 'ETag: "' . $stored['pdf_sha256'] . '"'), 'ETag = sha256');
    check((bool) preg_match('#Cache-Control: private, no-store#i', $r['headers']), 'private, no-store');
    $a = $pdo->query("SELECT actor, actor_name, after FROM audit_log WHERE action = 'document.pdf_downloaded' ORDER BY id DESC LIMIT 1")->fetch();
    $after = json_decode($a['after'], true);
    same(['service:manage', 'f3a9c0de-owner', 101, $docId, MANAGE_KEY_ID], [$a['actor'], $a['actor_name'], $after['cmp_id'], $after['document_id'], $after['key_id']], 'audited: who, which company, which document');
    check(is_string($after['request_id']) && $after['request_id'] !== '', 'with the request id');
    same(404, s2s($iapi, "documents/{$docId}/pdf", ['cmp_id' => 102])['status'], 'another company → 404');
    $cnDoc = $pdo->query("SELECT document_id FROM fin_credit_notes WHERE id = {$ids['CN2']}")->fetchColumn();
    same(200, s2s($iapi, "documents/{$cnDoc}/pdf", ['cmp_id' => 101])['status'], 'credit note PDF');
});

test('AIC-S2S over HTTP: replay, stale, bad signature, environment, unknown / short / issue key, staff JWT, no CORS', function () use ($iapi, $iapiSandbox, $T): void {
    $nonce = AicS2s::uuid4();
    same(200, s2s($iapi, 'documents', ['cmp_id' => 101], ['nonce' => $nonce])['status'], 'first use of a nonce');
    $replay = s2s($iapi, 'documents', ['cmp_id' => 101], ['nonce' => $nonce]);
    same([401, 'nonce_replayed', false], [$replay['status'], s2sCode($replay), $replay['json']['error']['retryable']], 'replay → 401');
    same([401, 'timestamp_stale'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['ts' => time() - 400]))['status'], s2sCode($r)], 'stale timestamp');
    same([401, 'signature_invalid'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['override' => ['X-AIC-Signature' => 'v1=' . str_repeat('ab', 32)]]))['status'], s2sCode($r)], 'bad signature');
    same([401, 'signature_invalid'], [($r = s2s($iapi, 'documents', ['cmp_id' => 102], ['signed_query' => 'cmp_id=101']))['status'], s2sCode($r)], 'signed for 101, asked for 102');
    same([401, 'signature_invalid'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['secret' => MANAGE_ISSUE_SECRET]))['status'], s2sCode($r)], 'signed with another key\'s secret');
    same([403, 'environment_mismatch'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['env' => 'production']))['status'], s2sCode($r)], 'production caller at a local receiver');
    same([400, 'environment_required'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['override' => ['X-AIC-Environment' => null]]))['status'], s2sCode($r)], 'no environment');
    same(200, s2s($iapiSandbox, 'documents', ['cmp_id' => 101], ['env' => 'sandbox'])['status'], 'FINANCE_S2S_ENVIRONMENT=sandbox accepts sandbox');
    same(403, s2s($iapiSandbox, 'documents', ['cmp_id' => 101])['status'], '…and refuses local');
    same([401, 'unknown_key'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['key' => 'manage-retired']))['status'], s2sCode($r)], 'unknown key id');
    same([401, 'unknown_key'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['key' => 'manage-short', 'secret' => 'too-short-to-be-a-secret']))['status'], s2sCode($r)], 'a secret under 32 bytes is ignored');
    same([401, 'unknown_key'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['client' => 'engage', 'key' => 'engage-read', 'secret' => ENGAGE_READ_SECRET]))['status'], s2sCode($r)], 'Engage may not read Manage documents');
    same([403, 'route_not_allowed_for_key'], [($r = s2s($iapi, 'documents', ['cmp_id' => 101], ['key' => MANAGE_ISSUE_ID, 'secret' => MANAGE_ISSUE_SECRET]))['status'], s2sCode($r)], 'issue key on a read route');
    $jwt = http('GET', $iapi . '/api/v1/inbound/manage/documents?cmp_id=101', ['Authorization: Bearer ' . $T['dave'], 'Origin: https://finance.test']);
    same([401, 'signature_missing'], [$jwt['status'], s2sCode($jwt)], 'a finance_admin staff token is not accepted');
    check(!str_contains(strtolower($jwt['headers']), 'access-control-allow-origin'), 'no CORS headers on S2S routes');
    check(str_contains(strtolower(http('GET', $iapi . '/api/v1/auth/me', ['Authorization: Bearer ' . $T['dave'], 'Origin: https://finance.test'])['headers']), 'access-control-allow-origin'),
        'staff routes keep their CORS headers');
    $pre = http('OPTIONS', $iapi . '/api/v1/inbound/manage/documents', ['Origin: https://finance.test', 'Access-Control-Request-Method: GET']);
    check($pre['status'] !== 204 && !str_contains(strtolower($pre['headers']), 'access-control-allow-origin'), 'no preflight grant (' . $pre['status'] . ')');
    same(404, s2s($iapi, 'documents', ['cmp_id' => 101], ['method' => 'POST'])['status'], 'no write route exists');
});

test('rate limits per key id: 429 + Retry-After; a PDF counts 5', function () use ($iapiLowRate, $iapi, &$ids): void {
    if ((int) date('s') >= 50) {
        sleep(61 - (int) date('s'));
    }
    $docId = invoiceRow($ids['INV1'])['document_id'];
    $o = ['key' => RL_KEY_ID, 'secret' => RL_SECRET];
    same(200, s2s($iapiLowRate, "documents/{$docId}/pdf", ['cmp_id' => 101], $o)['status'], 'PDF: 5 of 6');
    same(200, s2s($iapiLowRate, 'documents', ['cmp_id' => 101], $o)['status'], 'list: 6 of 6');
    $r = s2s($iapiLowRate, 'documents', ['cmp_id' => 101], $o);
    same([429, 'rate_limited', true], [$r['status'], s2sCode($r), $r['json']['error']['retryable']], '7th unit → 429');
    check((bool) preg_match('/Retry-After: ([1-9]\d?)\r/', $r['headers'], $m) && (int) $m[1] <= 60, 'Retry-After in seconds');
    same(200, s2s($iapi, 'documents', ['cmp_id' => 101])['status'], 'another key id is not affected');
});

test('no PDF renderer: stored as HTML, PDF routes 503, health says so; the cron renders the stored HTML later (retrying failures)', function () use ($iapiNoPdf, $iapi, $T, $BUYER, $dbEnv, $fakeGot, $gotState, &$ids): void {
    $p = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(101, $BUYER['A'], '5900.00', 'HDFCN52026101800106', ['amount' => '5900.00']), [], $iapiNoPdf);
    $v = api('POST', "v1/offline-payments/{$p['json']['data']['id']}/verify", $T['carol'], [], [], $iapiNoPdf)['json']['data'];
    same('issued', $v['invoice_state'], 'invoice issued without a PDF service');
    $id = $v['invoice']['id'];
    $detail = api('GET', "v1/invoices/{$id}", $T['viewer'], null, [], $iapiNoPdf)['json']['data'];
    same([false, false, null], [$detail['rendering']['pdf_stored'], $detail['rendering']['renderer_configured'], $detail['pdf_sha256']], 'HTML only');
    $staff = api('GET', "v1/invoices/{$id}/pdf", $T['viewer'], null, [], $iapiNoPdf);
    same([503, 'pdf_unavailable'], [$staff['status'], $staff['json']['errors']['code'] ?? null], 'staff PDF → 503');
    check(str_contains((string) $staff['json']['message'], 'GOTENBERG_URL'), 'says why');
    $m = s2s($iapiNoPdf, 'documents/' . $v['invoice']['document_id'] . '/pdf', ['cmp_id' => 101]);
    same([503, 'pdf_unavailable', true], [$m['status'], s2sCode($m), $m['json']['error']['retryable']], 'Manage PDF → 503, retryable');
    $h = http('GET', $iapiNoPdf . '/api/health')['json']['data']['invoicing'];
    same(['incomplete', false, true], [$h['status'], $h['checks']['pdf_renderer'], $h['checks']['pdfs_pending']], 'health: no renderer, PDFs pending');

    file_put_contents($gotState, json_encode(['statuses' => [503, 503]]));
    $out = runCron($dbEnv + ['GOTENBERG_URL' => $fakeGot]);
    check(str_contains($out, 'pdf_stored=0 pdf_failed=1'), 'PDF service down: retried later — ' . trim($out));
    $row = pdfRow('invoice_id', $id);
    same([null, 1, 'PDF service unavailable.'], [$row['pdf'], (int) $row['render_attempts'], $row['last_error']], 'failure recorded');
    $out = runCron($dbEnv + ['GOTENBERG_URL' => $fakeGot]);
    check(str_contains($out, 'pdf_stored=1'), 'stored on the next run — ' . trim($out));
    $row = pdfRow('invoice_id', $id);
    check(str_contains((string) $row['pdf'], 'html-sha256: ' . $row['html_sha256']), 'rendered from the HTML stored at issue');
    same(200, s2s($iapiNoPdf, 'documents/' . $v['invoice']['document_id'] . '/pdf', ['cmp_id' => 101])['status'], 'now served');
    $dl = api('GET', "v1/invoices/{$id}/pdf", $T['viewer'], null, [], $iapi);
    same([200, $row['pdf']], [$dl['status'], $dl['body']], 'staff download');
    $ids['INV6'] = $id;
});

test('GSTR-1 CSV: B2B / B2CL / B2CS / CDNR, GSTIN, POS, rates, tax heads, SAC; edit gate; audited. Register filters', function () use ($iapi, $T, $pdo, $FY, $YY, $BUYER): void {
    same(403, api('GET', "v1/invoices/export?fy={$FY}", $T['viewer'], null, [], $iapi)['status'], 'viewer cannot export');
    $x = api('GET', "v1/invoices/export?fy={$FY}", $T['alice'], null, [], $iapi);
    same(200, $x['status'], 'accountant exports');
    $lines = explode("\r\n", trim($x['body']));
    same('GSTR-1 section,Document type,Document number,Document date,Original invoice number,Original invoice date,Company (cmp_id),Buyer name,Buyer GSTIN,'
        . 'Place of supply,Supply type,Reverse charge,Document value,SAC,Rate (%),Taxable value,IGST,CGST,SGST,Cess', $lines[0], 'header');
    $find = static function (string $prefix) use ($lines): string {
        foreach ($lines as $l) {
            if (str_starts_with($l, $prefix)) {
                return $l;
            }
        }

        return '';
    };
    $dmy = DateTimeImmutable::createFromFormat('Y-m-d', FiscalYear::today())->format('d/m/Y');
    same("B2B,Invoice,AIC/{$YY}/000002,{$dmy},,,101,Acme Traders Pvt Ltd,{$BUYER['A']['gstin']},27-Maharashtra,Intra-state,N,11800.00,997331,18.00,10000.00,0.00,900.00,900.00,0.00",
        $find("B2B,Invoice,AIC/{$YY}/000002,"), 'B2B intra-state row');
    check(str_contains($find("B2B,Invoice,AIC/{$YY}/000003,"), ',29-Karnataka,Inter-state,N,23600.00,997331,18.00,20000.00,3600.00,0.00,0.00,'), 'B2B inter-state row');
    check(str_contains($find("B2CS,Invoice,AIC/{$YY}/000004,"), ',Ravi Sharma,,07-Delhi,Inter-state,'), 'B2CS: unregistered, small');
    check(str_contains($find("B2CL,Invoice,AIC/{$YY}/000005,"), ',09-Uttar Pradesh,Inter-state,N,236000.00,'), 'B2CL: inter-state unregistered above ₹1 lakh');
    same("CDNR,Credit Note,CN/{$YY}/000001,{$dmy},AIC/{$YY}/000002,{$dmy},101,Acme Traders Pvt Ltd,{$BUYER['A']['gstin']},27-Maharashtra,Intra-state,N,590.00,997331,18.00,500.00,0.00,45.00,45.00,0.00",
        $find("CDNR,Credit Note,CN/{$YY}/000001,"), 'CDNR row with the original invoice');
    same(1, (int) $pdo->query("SELECT count(*) FROM audit_log WHERE action = 'invoices.exported' AND actor = 'alice@aicountly.com'")->fetchColumn(), 'export audited');

    $reg = api('GET', "v1/invoices?fy={$FY}&cmp_id=101", $T['viewer'], null, [], $iapi)['json']['data'];
    same([5, 2950000, 1180000], [$reg['total'], $reg['totals']['invoiced_paise'], $reg['totals']['credited_paise']], 'register for cmp 101: 3 invoices + 2 credit notes');
    same(2, api('GET', 'v1/invoices?doc_type=credit_note', $T['viewer'], null, [], $iapi)['json']['data']['total'], 'issued credit notes');
    same(1, api('GET', 'v1/invoices?doc_type=credit_note&status=rejected', $T['viewer'], null, [], $iapi)['json']['data']['total'], 'rejected ones on request');
    same(1, api('GET', 'v1/invoices?status=credited', $T['viewer'], null, [], $iapi)['json']['data']['total'], 'credited invoices');
    same(['Acme Traders Pvt Ltd'], array_values(array_unique(array_column(api('GET', 'v1/invoices?q=acme', $T['viewer'], null, [], $iapi)['json']['data']['rows'], 'buyer_legal_name'))), 'search');
    same(422, api('GET', 'v1/invoices?fy=2026', $T['viewer'], null, [], $iapi)['status'], 'bad fy → 422');
});

test('discounted lines: offline payment → invoice with list / discount / taxable / GST; credit note copies them; old invoices unchanged', function () use ($iapi, $T, $pdo, $BUYER, &$ids): void {
    $disc = ['amount' => null, 'list_amount' => '11800.00', 'discount' => '2360.00', 'discount_ref' => 'DIWALI26', 'description' => 'Books Pro (coupon DIWALI26)'];
    $pv = api('POST', 'v1/offline-payments/preview', $T['alice'], ['buyer' => $BUYER['A'], 'lines' => offlineBody(120, $BUYER['A'], '9440.00', 'X', $disc)['lines']], [], $iapi);
    same(200, $pv['status'], 'preview');
    same([944000, 1180000, 236000, 'DIWALI26', 800000, 944000], [$pv['json']['data']['lines'][0]['amount_paise'], $pv['json']['data']['lines'][0]['list_amount_paise'],
        $pv['json']['data']['lines'][0]['discount_paise'], $pv['json']['data']['lines'][0]['discount_ref'], $pv['json']['data']['totals']['taxable_paise'],
        $pv['json']['data']['totals']['total_paise']], 'preview shows the discount and GST on the net');
    $bad = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(120, $BUYER['A'], '9440.00', 'HDFCN52026101800120', ['discount' => '11799.50'] + $disc), [], $iapi);
    same([422, true], [$bad['status'], isset($bad['json']['errors']['lines[0].discount'])], 'discount above list − ₹1 → 422');
    $mm = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(120, $BUYER['A'], '11800.00', 'HDFCN52026101800120', $disc), [], $iapi);
    same([422, 'amount_mismatch'], [$mm['status'], $mm['json']['errors']['code'] ?? null], 'the amount received is the discounted total');

    $p = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(120, $BUYER['A'], '9440.00', 'HDFCN52026101800120', $disc), [], $iapi);
    same(201, $p['status'], 'recorded');
    $stored = json_decode((string) $pdo->query("SELECT lines FROM fin_offline_payments WHERE id = {$p['json']['data']['id']}")->fetchColumn(), true);
    same([944000, 1180000, 236000, 'DIWALI26'], [$stored[0]['amount_paise'], $stored[0]['list_amount_paise'], $stored[0]['discount_paise'], $stored[0]['discount_ref']], 'stored with the payment');
    $plainStored = json_decode((string) $pdo->query('SELECT lines FROM fin_offline_payments WHERE cmp_id = 101 ORDER BY id LIMIT 1')->fetchColumn(), true);
    check(!array_key_exists('discount_paise', $plainStored[0]), 'an ordinary payment line is stored as before');

    $v = api('POST', "v1/offline-payments/{$p['json']['data']['id']}/verify", $T['carol'], [], [], $iapi);
    same([200, 'issued'], [$v['status'], $v['json']['data']['invoice_state']], 'verified → invoice issued');
    $invId = $v['json']['data']['invoice']['id'];
    $ids['INV_DISC'] = $invId;
    $line = $pdo->query("SELECT * FROM fin_invoice_lines WHERE invoice_id = {$invId}")->fetch();
    same([944000, 1180000, 236000, 'DIWALI26', 800000, 72000, 72000, 944000], [(int) $line['amount_paise'], (int) $line['list_amount_paise'], (int) $line['discount_paise'],
        $line['discount_ref'], (int) $line['taxable_paise'], (int) $line['cgst_paise'], (int) $line['sgst_paise'], (int) $line['total_paise']], 'line stored: GST on list − discount');
    $doc = api('GET', "v1/invoices/{$invId}", $T['viewer'], null, [], $iapi)['json']['data'];
    same([1180000, 236000, 'DIWALI26', 944000], [$doc['lines'][0]['list_amount_paise'], $doc['lines'][0]['discount_paise'], $doc['lines'][0]['discount_ref'], $doc['total_paise']], 'staff detail');
    $html = (string) pdfRow('invoice_id', $invId)['html'];
    foreach (['List price', 'Discount', '₹11,800.00', '&minus;₹2,360.00', 'DIWALI26', '₹8,000.00', '₹720.00', '₹9,440.00'] as $needle) {
        check(str_contains($html, $needle), "PDF HTML shows {$needle}");
    }
    check(!str_contains((string) pdfRow('invoice_id', $ids['INV2'])['html'], 'List price'), 'an invoice without discount has no discount columns');
    same([null, null, null], array_values(array_intersect_key(api('GET', "v1/invoices/{$ids['INV2']}", $T['viewer'], null, [], $iapi)['json']['data']['lines'][0],
        array_flip(['list_amount_paise', 'discount_paise', 'discount_ref']))), 'old lines: the new fields are null');
    $m = s2s($iapi, "documents/{$doc['document_id']}", ['cmp_id' => 120]);
    same([200, 'DIWALI26', 236000], [$m['status'], $m['json']['data']['lines'][0]['discount_ref'], $m['json']['data']['lines'][0]['discount_paise']], 'Manage sees the discount (additive fields)');
    same(1, (int) $pdo->query("SELECT count(*) FROM pg_constraint WHERE conname IN ('fin_invoice_lines_discount_chk')")->fetchColumn(), 'DB check present');

    // Exclusive basis: ₹10,000 − ₹2,000 → IGST 18 % on ₹8,000.
    $ex = api('POST', 'v1/offline-payments', $T['alice'], offlineBody(121, $BUYER['B'], '9440.00', 'ICICN52026101800121',
        ['price_basis' => 'exclusive', 'amount' => null, 'list_amount' => '10000.00', 'discount' => '2000.00', 'discount_ref' => 'QTE-000012']), [], $iapi);
    same(201, $ex['status'], 'exclusive discounted payment recorded');
    $exInv = api('POST', "v1/offline-payments/{$ex['json']['data']['id']}/verify", $T['carol'], [], [], $iapi)['json']['data']['invoice']['id'];
    $exRow = invoiceRow($exInv);
    same([800000, 144000, 944000], [(int) $exRow['taxable_paise'], (int) $exRow['igst_paise'], (int) $exRow['total_paise']], 'exclusive: taxable = list − discount, GST on top');

    // Full credit note: refunds the net invoiced, copies the discount for reference.
    $cn = api('POST', "v1/invoices/{$invId}/credit-notes", $T['alice'], ['kind' => 'full', 'reason' => 'Subscription cancelled'], [], $iapi)['json']['data'];
    same([944000, 800000], [$cn['total_paise'], $cn['totals']['taxable_paise']], 'credit = the net paid, never the discount');
    same([1180000, 236000, 'DIWALI26'], [$cn['lines'][0]['list_amount_paise'], $cn['lines'][0]['discount_paise'], $cn['lines'][0]['discount_ref']], 'credit note line copies the discount');
    $ok = api('POST', "v1/credit-notes/{$cn['id']}/approve", $T['carol'], [], [], $iapi);
    same(200, $ok['status'], 'credit note issued');
    check(str_contains((string) pdfRow('credit_note_id', $cn['id'])['html'], 'Original invoice line: list ₹11,800.00 less discount ₹2,360.00 (DIWALI26)'), 'credit note PDF names the discount');
    $cnl = $pdo->query("SELECT l.* FROM fin_credit_note_lines l WHERE l.credit_note_id = {$cn['id']}")->fetch();
    same([1180000, 236000, 'DIWALI26', 944000], [(int) $cnl['list_amount_paise'], (int) $cnl['discount_paise'], $cnl['discount_ref'], (int) $cnl['total_paise']], 'stored on the credit note line');
    // Partial credit note on the exclusive invoice also copies the reference.
    $exLine = api('GET', "v1/invoices/{$exInv}", $T['alice'], null, [], $iapi)['json']['data']['lines'][0];
    $pc = api('POST', "v1/invoices/{$exInv}/credit-notes", $T['alice'], ['kind' => 'partial', 'reason' => 'Goodwill', 'lines' => [['invoice_line_id' => $exLine['id'], 'amount' => '118.00']]], [], $iapi)['json']['data'];
    same([11800, 'QTE-000012'], [$pc['total_paise'], $pc['lines'][0]['discount_ref']], 'partial: GST-inclusive amount credited; discount ref copied');
    refusedSql("UPDATE fin_invoice_lines SET discount_paise = 0 WHERE invoice_id = {$invId}", 'immutable', 'a discounted line is immutable like any line');
});

test('health: invoicing ready once configured; top-level status still the payout readiness; payouts untouched', function () use ($iapi, $payoutCounts, $payoutsBefore): void {
    $h = http('GET', $iapi . '/api/health');
    $d = $h['json']['data'];
    same(['status' => 'ready', 'checks' => ['schema' => true, 'seller_profile' => true, 'series_current_fy' => true, 'pdf_renderer' => true, 'manage_inbound' => true,
        'issuance_blocked' => false, 'pdfs_pending' => false]], $d['invoicing'], 'invoicing ready');
    same(in_array(false, $d['checks'], true) ? 'misconfigured' : 'ok', $d['status'], 'top-level status unchanged by invoicing');
    foreach ([MANAGE_SECRET, MANAGE_ISSUE_SECRET, 'GOTENBERG', 'http://127.0.0.1'] as $secret) {
        check(!str_contains($h['body'], $secret), 'health shows no secret or URL');
    }
    same($payoutsBefore, $payoutCounts(), 'no payout table touched by invoicing');
});
