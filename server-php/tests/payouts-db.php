<?php
declare(strict_types=1);

/**
 * Payouts end to end: PostgreSQL (own database finance_test, dropped afterwards), the real API
 * under `php -S`, and a stub Engage (fake-engage.php) for the status outbox.
 * Included by tests/run.php after its HTTP section (uses serve(), http(), mintToken(), test()…).
 */

use App\Database\Database;
use App\Database\Migrator;
use App\Services\CompanyBankAccountService;
use App\Services\DataVault;
use App\Support\FiscalYear;

echo "Payouts (PostgreSQL + php -S)\n";

$dbHost = getenv('FINANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('FINANCE_TEST_DB_PORT') ?: 5432);
$dbUser = getenv('FINANCE_TEST_DB_USER') ?: 'postgres';
$dbPass = getenv('FINANCE_TEST_DB_PASSWORD') !== false ? (string) getenv('FINANCE_TEST_DB_PASSWORD') : 'postgres';
$dbName = preg_replace('/[^a-z0-9_]/', '', strtolower(getenv('FINANCE_TEST_DB_NAME') ?: 'finance_test')) ?: 'finance_test';

try {
    $adminPdo = Database::connect($dbHost, $dbPort, 'postgres', $dbUser, $dbPass);
} catch (\Throwable $e) {
    if (getenv('FINANCE_TESTS_REQUIRE_DB') === '1') {
        fwrite(STDERR, "FAIL PostgreSQL unreachable at {$dbHost}:{$dbPort}: {$e->getMessage()}\n");
        exit(1);
    }
    echo "  SKIP PostgreSQL unreachable at {$dbHost}:{$dbPort} (start it: pg_ctlcluster 16 main start; FINANCE_TESTS_REQUIRE_DB=1 makes this fatal)\n";

    return;
}

$adminPdo->exec("DROP DATABASE IF EXISTS {$dbName} WITH (FORCE)");
$adminPdo->exec("CREATE DATABASE {$dbName}");
register_shutdown_function(static function () use (&$adminPdo, $dbName): void {
    global $pdo;
    $pdo = null;
    Database::use(null);
    usleep(200_000);
    $adminPdo->exec("DROP DATABASE IF EXISTS {$dbName} WITH (FORCE)");
    $adminPdo = null;
});

$pdo = Database::connect($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

const DATA_KEY_HEX   = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
const INBOUND_KEY    = 'engage-to-finance-inbound-key-0123456789abcdef';
const ENGAGE_OUT_KEY = 'finance-to-engage-callback-key-0123456789abcdef';

test('migrations: applied once, re-run is a no-op, an edited migration is refused', function () use ($pdo, $cacheDir): void {
    $m = new Migrator($pdo, __DIR__ . '/../database/migrations');
    same(['001_payouts', '002_company_bank_accounts', '003_invoicing'], $m->migrate(), 'first run applies 001, 002 and 003');
    same([], $m->migrate(), 'second run applies nothing');
    same([], $m->pending(), 'nothing pending');

    $dir = $cacheDir . '/migrations-edited';
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/001_payouts.sql', file_get_contents(__DIR__ . '/../database/migrations/001_payouts.sql') . "\n-- edited\n");
    $refused = false;
    try {
        (new Migrator($pdo, $dir))->migrate();
    } catch (\RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'checksum mismatch');
    }
    check($refused, 'checksum mismatch refused');
    same('2.00', $pdo->query("SELECT value FROM settings WHERE key = 'tds_rate_percent'")->fetch()['value'], 'default TDS rate seeded');
    same('20000.00', $pdo->query("SELECT value FROM settings WHERE key = 'tds_threshold_per_fy'")->fetch()['value'], 'default threshold seeded');
});

$engageState = $cacheDir . '/engage-state.json';
$engageLog   = $cacheDir . '/engage-requests.log';
file_put_contents($engageState, '{}');
file_put_contents($engageLog, '');
$fakeEngage = serve(__DIR__ . '/fake-engage.php', ['FAKE_ENGAGE_STATE' => $engageState, 'FAKE_ENGAGE_LOG' => $engageLog]);

$dbEnv = [
    'FINANCE_DB_HOST'     => $dbHost,
    'FINANCE_DB_PORT'     => (string) $dbPort,
    'FINANCE_DB_NAME'     => $dbName,
    'FINANCE_DB_USER'     => $dbUser,
    'FINANCE_DB_PASSWORD' => $dbPass,
];
$payEnv = $apiEnv + $dbEnv + [
    'FINANCE_DATA_KEY'           => DATA_KEY_HEX,
    'FINANCE_INBOUND_ENGAGE_KEY' => INBOUND_KEY,
    'ENGAGE_API_BASE_URL'        => $fakeEngage . '/api',
    'ENGAGE_FINANCE_KEY'         => ENGAGE_OUT_KEY,
    'FINANCE_AUTHZ_CACHE_DIR'    => $cacheDir . '/pay',
];
$papi = serve($serverRoot . '/public/index.php', $payEnv);
$noInbound = $payEnv;
unset($noInbound['FINANCE_INBOUND_ENGAGE_KEY']);
$papiNoInbound = serve($serverRoot . '/public/index.php', $noInbound);
$noVault = $payEnv;
unset($noVault['FINANCE_DATA_KEY']);
$papiNoVault = serve($serverRoot . '/public/index.php', $noVault);

$vault = new DataVault((string) hex2bin(DATA_KEY_HEX));

function tok(string $email, string ...$roles): string
{
    return mintToken(['email' => $email, 'name' => ucfirst(strtok($email, '@')), 'sub' => crc32($email), 'roles' => $roles]);
}

$T = [
    'viewer'   => tok('vic@aicountly.com', 'finance_viewer'),
    'alice'    => tok('alice@aicountly.com', 'accountant'),
    'bob'      => tok('bob@aicountly.com', 'payout_approver'),
    'carol'    => tok('carol@aicountly.com', 'payout_approver'),
    'dave'     => tok('dave@aicountly.com', 'finance_admin'),
];

/** @return array{status:int, headers:string, json:?array, body:string} */
function api(string $method, string $path, ?string $token, mixed $body = null, array $headers = [], ?string $base = null): array
{
    global $papi;
    $h = $headers;
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
    }

    return http($method, ($base ?? $papi) . '/api/' . ltrim($path, '/'), $h, $body === null ? null : (is_string($body) ? $body : json_encode($body)));
}

function inbound(array $payload, ?string $key = INBOUND_KEY, ?string $base = null): array
{
    return api('POST', 'v1/inbound/engage/payout-batches', null, $payload, $key === null ? [] : ['X-Engage-Key: ' . $key], $base);
}

$periodEnd   = (new DateTimeImmutable('-1 day', FiscalYear::tz()))->format('Y-m-d');
$periodStart = (new DateTimeImmutable('-7 days', FiscalYear::tz()))->format('Y-m-d');

const SELLERS = [
    'SLR-000001' => ['name' => 'Asha Traders', 'pan' => 'ABCPA1234F', 'gstin' => '27ABCPA1234F1Z5', 'type' => 'proprietorship', 'acct' => '001234567890', 'ifsc' => 'HDFC0001234'],
    'SLR-000002' => ['name' => 'Ravi Kumar', 'pan' => 'BCDPB2345G', 'gstin' => null, 'type' => 'individual', 'acct' => '998877665544', 'ifsc' => 'ICIC0000001'],
    'SLR-000003' => ['name' => 'Nimbus Pvt Ltd', 'pan' => 'CDECN3456H', 'gstin' => null, 'type' => 'company', 'acct' => '556677889900', 'ifsc' => 'SBIN0000123'],
];

/** @param list<array{0:string,1:string,2:string}> $items [item_ref, seller_code, gross] */
function batchPayload(string $ref, array $items): array
{
    global $periodStart, $periodEnd;
    $out = [];
    foreach ($items as [$itemRef, $seller, $gross]) {
        $s = SELLERS[$seller];
        $out[] = [
            'item_ref'     => $itemRef,
            'seller_code'  => $seller,
            'payee'        => ['legal_name' => $s['name'], 'pan' => $s['pan'], 'gstin' => $s['gstin'], 'entity_type' => $s['type'],
                'bank' => ['account_holder' => $s['name'], 'account_number' => $s['acct'], 'ifsc' => $s['ifsc']]],
            'gross_amount' => $gross,
            'entries'      => [crc32($itemRef) % 1000, 7],
        ];
    }

    return ['batch_ref' => $ref, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'currency' => 'INR', 'items' => $out];
}

function itemRow(string $itemRef): array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM payout_items WHERE item_ref = ?');
    $stmt->execute([$itemRef]);

    return $stmt->fetch() ?: [];
}

function batchRow(int $id): array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM payout_batches WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: [];
}

function itemId(string $itemRef): int
{
    return (int) itemRow($itemRef)['id'];
}

$W1 = batchPayload('PAYRUN-W1', [['W1-1', 'SLR-000001', '15000.00'], ['W1-2', 'SLR-000002', '25000.00'], ['W1-3', 'SLR-000003', '1000.00']]);
$ids = [];

test('inbound auth: 503 when Finance has no key, 401 without or with a wrong X-Engage-Key', function () use ($W1, $papiNoInbound): void {
    $r = inbound($W1, INBOUND_KEY, $papiNoInbound);
    same(503, $r['status'], 'unset FINANCE_INBOUND_ENGAGE_KEY → 503');
    check(str_contains((string) $r['json']['message'], 'not configured'), 'says not configured');
    same(401, inbound($W1, null)['status'], 'missing header → 401');
    same(401, inbound($W1, INBOUND_KEY . 'x')['status'], 'wrong key → 401');
    same(401, inbound($W1, substr(INBOUND_KEY, 0, -1))['status'], 'prefix of the key → 401');
});

test('inbound without FINANCE_DATA_KEY fails closed (503) and stores nothing', function () use ($W1, $papiNoVault, $pdo): void {
    $r = inbound($W1, INBOUND_KEY, $papiNoVault);
    same(503, $r['status'], '503');
    check(str_contains((string) $r['json']['message'], 'FINANCE_DATA_KEY'), 'names the missing key');
    same(0, (int) $pdo->query('SELECT count(*) AS n FROM payout_batches')->fetch()['n'], 'nothing stored');
});

test('inbound: invalid batch → 422 with every error, nothing stored', function () use ($W1, $pdo): void {
    $bad = $W1;
    $bad['items'][1]['payee']['pan'] = 'NOTAPAN';
    $bad['items'][2]['gross_amount'] = '12.345';
    $r = inbound($bad);
    same(422, $r['status'], '422');
    check(isset($r['json']['errors']['items[1].payee.pan'], $r['json']['errors']['items[2].gross_amount']), 'both errors reported');
    same(0, (int) $pdo->query('SELECT count(*) AS n FROM payout_items')->fetch()['n'], 'no item stored');
});

test('inbound: DRAFT batch with GST, TDS (194H) and net computed — 201 {batch_id, status:"received"}', function () use ($W1, &$ids): void {
    $r = inbound($W1);
    same(201, $r['status'], '201');
    same('received', $r['json']['data']['status'], 'status received');
    check(is_int($r['json']['data']['batch_id']), 'batch_id');
    $ids['W1'] = $r['json']['data']['batch_id'];
    same('draft', batchRow($ids['W1'])['status'], 'batch is a draft');

    $a = itemRow('W1-1');
    same(['15000.00', 'exclusive', '2700.00', '0.00', '17700.00', 'draft'], [$a['gross_amount'], $a['gst_mode'], $a['gst_amount'], $a['tds_amount'], $a['net_amount'], $a['status']],
        'GSTIN payee: 18% GST on top, below the TDS threshold');
    $b = itemRow('W1-2');
    same(['25000.00', 'none', '0.00', '2.00', '500.00', '24500.00'], [$b['gross_amount'], $b['gst_mode'], $b['gst_amount'], $b['tds_rate'], $b['tds_amount'], $b['net_amount']],
        'no GSTIN: no GST; above threshold: 2% TDS');
    same('[' . (crc32('W1-1') % 1000) . ', 7]', $a['entry_ids'], 'Engage entry ids kept');
});

test('inbound is idempotent on batch_ref: replay → 200 same batch; changed content → 409', function () use ($W1, &$ids, $pdo): void {
    $r = inbound($W1);
    same(200, $r['status'], 'replay 200');
    same($ids['W1'], $r['json']['data']['batch_id'], 'same batch_id');
    same(true, $r['json']['data']['duplicate'], 'duplicate flag');
    $changed = $W1;
    $changed['items'][0]['gross_amount'] = '15000.01';
    same(409, inbound($changed)['status'], 'same batch_ref, different content → 409');
    same(3, (int) $pdo->query('SELECT count(*) AS n FROM payout_items')->fetch()['n'], 'still 3 items');
});

test('PAN and bank account encrypted at rest (libsodium) and masked in every API answer', function () use ($pdo, $vault, &$ids, $T): void {
    $p = $pdo->query("SELECT * FROM payees WHERE seller_code = 'SLR-000002'")->fetch();
    check(str_starts_with($p['pan_enc'], 'v1:') && str_starts_with($p['bank_account_enc'], 'v1:'), 'ciphertext stored');
    same('BCDPB2345G', $vault->decrypt($p['pan_enc']), 'PAN decrypts with FINANCE_DATA_KEY');
    same('5544', $p['bank_account_last4'], 'last 4 in clear');
    $dump = '';
    foreach (['payees', 'payout_items', 'payout_batches', 'tds_ledger', 'audit_log', 'outbox', 'bank_files'] as $t) {
        $dump .= json_encode($pdo->query("SELECT * FROM {$t}")->fetchAll());
    }
    foreach (SELLERS as $s) {
        check(!str_contains($dump, $s['pan']), "no plaintext PAN {$s['pan']} anywhere in the database");
        check(!str_contains($dump, $s['acct']), "no plaintext account {$s['acct']} anywhere in the database");
    }
    $answers = json_encode([
        api('GET', 'v1/payout-batches/' . $ids['W1'], $T['dave'])['json'],
        api('GET', 'v1/payees', $T['dave'])['json'],
        api('GET', 'v1/payees/' . $p['id'], $T['dave'])['json'],
        api('GET', 'v1/audit-log', $T['dave'])['json'],
    ]);
    foreach (SELLERS as $s) {
        check(!str_contains($answers, $s['pan']) && !str_contains($answers, $s['acct']), "API never shows {$s['pan']} / {$s['acct']}");
    }
    check(str_contains($answers, 'XXXXXX345G') && str_contains($answers, 'XXXXXX5544'), 'masked values shown');
});

test('role gates: view for all, edit for accountant+, approve for approver+, settings for admin', function () use (&$ids, $T): void {
    $id = $ids['W1'];
    same(200, api('GET', 'v1/payout-batches', $T['viewer'])['status'], 'viewer lists batches');
    $v = api('GET', "v1/payout-batches/{$id}", $T['viewer'])['json']['data'];
    same(false, $v['can']['edit'] || $v['can']['approve'] || $v['can']['generate_file'], 'viewer sees no actions');
    same(403, api('POST', 'v1/payout-items/' . itemId('W1-3') . '/hold', $T['viewer'], ['reason' => 'x'])['status'], 'viewer cannot hold');
    same(403, api('POST', "v1/payout-batches/{$id}/submit", $T['viewer'], [])['status'], 'viewer cannot submit');
    same(403, api('POST', "v1/payout-batches/{$id}/approve", $T['alice'], ['version' => 1])['status'], 'accountant cannot approve');
    same(403, api('POST', "v1/payout-batches/{$id}/bank-file", $T['alice'], [])['status'], 'accountant cannot generate the bank file');
    same(403, api('POST', 'v1/payout-items/' . itemId('W1-1') . '/payment', $T['alice'], ['utr' => 'ABCDEF123'])['status'], 'accountant cannot record payments');
    same(403, api('PUT', 'v1/settings', $T['carol'], ['minimum_payout' => '1'])['status'], 'approver cannot change settings');
    same(403, api('GET', 'v1/tds-register/export', $T['viewer'])['status'], 'viewer cannot export full PANs');
    same(200, api('GET', 'v1/settings', $T['viewer'])['status'], 'viewer reads settings');
    same(401, api('GET', 'v1/dashboard', null)['status'], 'no token → 401');
    same(['view', 'edit'], api('GET', 'v1/auth/me', $T['alice'])['json']['data']['gates'], '/me lists the gates');
});

test('review: hold / unhold / adjust need reasons; adjustments change net and are kept', function () use (&$ids, $T): void {
    $w13 = itemId('W1-3');
    same(422, api('POST', "v1/payout-items/{$w13}/hold", $T['alice'], ['reason' => ' '])['status'], 'hold without reason → 422');
    $h = api('POST', "v1/payout-items/{$w13}/hold", $T['alice'], ['reason' => 'KYC address mismatch']);
    same(200, $h['status'], 'hold ok');
    same('on_hold', $h['json']['data']['status'], 'on_hold');

    $w12 = itemId('W1-2');
    same(422, api('POST', "v1/payout-items/{$w12}/adjustments", $T['alice'], ['amount' => '-500.00'])['status'], 'adjust without reason → 422');
    same(422, api('POST', "v1/payout-items/{$w12}/adjustments", $T['alice'], ['amount' => '-99999.00', 'reason' => 'x'])['status'], 'negative net refused');
    $a = api('POST', "v1/payout-items/{$w12}/adjustments", $T['alice'], ['amount' => '-500.00', 'reason' => 'Recover W40 overpayment']);
    same('24000.00', $a['json']['data']['net_amount'], 'net 24500 − 500');
    same('500.00', itemRow('W1-2')['tds_amount'], 'TDS unchanged by the adjustment');
    $detail = api('GET', "v1/payout-batches/{$ids['W1']}", $T['viewer'])['json']['data'];
    $item = array_values(array_filter($detail['items'], static fn ($i) => $i['item_ref'] === 'W1-2'))[0];
    same('Recover W40 overpayment', $item['adjustments'][0]['reason'], 'adjustment reason listed');
    same('alice@aicountly.com', batchRow($ids['W1'])['last_edited_by'], 'last editor tracked');
});

test('state machine over HTTP: no approval from draft, no payment before approval and file', function () use (&$ids, $T): void {
    same(409, api('POST', "v1/payout-batches/{$ids['W1']}/approve", $T['carol'], ['version' => batchRow($ids['W1'])['version']])['status'], 'draft cannot be approved');
    same(409, api('POST', "v1/payout-batches/{$ids['W1']}/bank-file", $T['carol'], [])['status'], 'no bank file for a draft');
    same(409, api('POST', 'v1/payout-items/' . itemId('W1-1') . '/payment', $T['carol'], ['utr' => 'HDFCN5202610180001'])['status'], 'draft item cannot be paid');
    same('draft', itemRow('W1-1')['status'], 'still draft');
});

test('four-eyes: the last editor cannot approve; a stale version is refused; someone else approves', function () use (&$ids, $T): void {
    $id = $ids['W1'];
    same(200, api('POST', "v1/payout-batches/{$id}/submit", $T['alice'], [])['status'], 'accountant submits for review');
    same('alice@aicountly.com', batchRow($id)['prepared_by'], 'prepared_by');
    // bob (approver) touches the batch while it is in review…
    $w13 = itemId('W1-3');
    same(200, api('POST', "v1/payout-items/{$w13}/unhold", $T['bob'], ['reason' => 'Checking'])['status'], 'bob releases the hold');
    same(200, api('POST', "v1/payout-items/{$w13}/hold", $T['bob'], ['reason' => 'KYC address mismatch'])['status'], 'and holds it again');
    $version = (int) batchRow($id)['version'];
    $detail = api('GET', "v1/payout-batches/{$id}", $T['bob'])['json']['data'];
    same(false, $detail['can']['approve'], 'UI: bob may not approve');
    check(is_string($detail['four_eyes']['blocked_reason']), 'UI explains why');
    $r = api('POST', "v1/payout-batches/{$id}/approve", $T['bob'], ['version' => $version]);
    same(403, $r['status'], '…so bob cannot approve it');
    check(str_contains((string) $r['json']['message'], 'Four-eyes'), 'four-eyes message');

    same(409, api('POST', "v1/payout-batches/{$id}/approve", $T['carol'], ['version' => $version - 1])['status'], 'stale version → 409');
    $ok = api('POST', "v1/payout-batches/{$id}/approve", $T['carol'], ['version' => $version]);
    same(200, $ok['status'], 'carol approves');
    same('approved', $ok['json']['data']['status'], 'batch approved');
    same(['approved', 'approved', 'on_hold'], [itemRow('W1-1')['status'], itemRow('W1-2')['status'], itemRow('W1-3')['status']], 'held item stays on hold');
    same('carol@aicountly.com', batchRow($id)['approved_by'], 'approved_by');
    same(409, api('POST', 'v1/payout-items/' . itemId('W1-1') . '/adjustments', $T['alice'], ['amount' => '1', 'reason' => 'late'])['status'], 'approved items are frozen');
});

$acct = [];
const ACCT_A = '123456789012';
const ACCT_B = '000111222333';
const ACCT_C = '445566778899';

/** A valid new company bank account body. */
function bankAccount(array $extra = []): array
{
    return $extra + [
        'label' => 'HDFC current', 'bank_name' => 'HDFC Bank', 'account_holder' => 'Aicountly Technologies Pvt Ltd',
        'account_number' => ACCT_A, 'account_number_confirm' => ACCT_A, 'ifsc' => 'hdfc0000001', 'branch' => 'Fort, Mumbai',
        'account_type' => 'current',
    ];
}

function accountsDump(): string
{
    global $pdo;
    $dump = '';
    foreach (['company_bank_accounts', 'audit_log', 'settings', 'bank_files'] as $t) {
        $dump .= json_encode($pdo->query("SELECT * FROM {$t}")->fetchAll());
    }

    return $dump;
}

test('bank file refused while no company bank account exists', function () use (&$ids, $T): void {
    $id = $ids['W1'];
    $detail = api('GET', "v1/payout-batches/{$id}", $T['carol'])['json']['data'];
    same([true, false, []], [$detail['can']['generate_file'], $detail['debit_account_configured'], $detail['bank_accounts']], 'UI: may generate, but no account to debit');
    $r = api('POST', "v1/payout-batches/{$id}/bank-file", $T['carol'], []);
    same(422, $r['status'], 'no account → 422');
    check(str_contains((string) $r['json']['message'], 'Settings → Bank accounts'), 'message says where to add it: ' . $r['json']['message']);
    same(422, api('PUT', 'v1/settings', $T['dave'], ['debit_account_number' => ACCT_A])['status'], 'the old single setting can no longer be written');
    same('approved', batchRow($id)['status'], 'still approved');
});

test('company bank accounts: gates, validation, default rules, deactivate / activate, masked everywhere', function () use (&$ids, $T, $pdo, $vault, &$acct): void {
    $l = api('GET', 'v1/bank-accounts', $T['viewer']);
    same(200, $l['status'], 'viewer lists');
    same([[], false], [$l['json']['data']['accounts'], $l['json']['data']['can_edit']], 'none yet; viewer cannot edit');
    check(isset($l['json']['data']['options']['account_type']['od'], $l['json']['data']['options']['bank_file_format']['generic_neft_csv']), 'options');
    foreach (['viewer', 'alice', 'carol'] as $who) {
        same(403, api('POST', 'v1/bank-accounts', $T[$who], bankAccount())['status'], "{$who} cannot add");
    }

    $bad = api('POST', 'v1/bank-accounts', $T['dave'], bankAccount(['ifsc' => 'HDFC1000001', 'account_number' => '12345', 'account_number_confirm' => '12345', 'bank_name' => '']));
    same(422, $bad['status'], 'invalid → 422');
    same(['bank_name', 'ifsc', 'account_number'], array_keys($bad['json']['errors']), 'errors per field');
    $mismatch = api('POST', 'v1/bank-accounts', $T['dave'], bankAccount(['account_number_confirm' => '123456789013']));
    same(['account_number_confirm'], array_keys($mismatch['json']['errors'] ?? []), 'confirmation must match');
    same(0, (int) $pdo->query('SELECT count(*) AS n FROM company_bank_accounts')->fetch()['n'], 'nothing stored');

    $a = api('POST', 'v1/bank-accounts', $T['dave'], bankAccount());
    same(201, $a['status'], 'admin adds the first account');
    $A = $a['json']['data'];
    same([true, true, 'XXXXXX9012', '9012', 'HDFC0000001', false], [$A['is_default'], $A['active'], $A['account_number_masked'], $A['account_number_last4'], $A['ifsc'], $A['incomplete']], 'first account is the default; masked; IFSC normalised');
    same(409, api('POST', 'v1/bank-accounts', $T['dave'], bankAccount(['label' => 'Again']))['status'], 'same number twice → 409');

    $b = api('POST', 'v1/bank-accounts', $T['dave'], bankAccount(['label' => 'ICICI OD', 'bank_name' => 'ICICI Bank', 'account_number' => ACCT_B,
        'account_number_confirm' => ACCT_B, 'ifsc' => 'ICIC0000104', 'branch' => null, 'account_type' => 'od', 'bank_file_format' => 'icici_hdfc_style_csv']));
    same(201, $b['status'], 'second account');
    $B = $b['json']['data'];
    same([false, 'icici_hdfc_style_csv', 'Overdraft (OD)'], [$B['is_default'], $B['bank_file_format'], $B['account_type_label']], 'not the default; own file format');
    $row = $pdo->query("SELECT * FROM company_bank_accounts WHERE id = {$B['id']}")->fetch();
    check(str_starts_with($row['account_number_enc'], 'v1:'), 'number encrypted at rest');
    same(ACCT_B, $vault->decrypt($row['account_number_enc']), 'decrypts with FINANCE_DATA_KEY');

    // Edit.
    same(403, api('PUT', "v1/bank-accounts/{$B['id']}", $T['carol'], ['label' => 'x'])['status'], 'approver cannot edit');
    same(422, api('PUT', "v1/bank-accounts/{$B['id']}", $T['dave'], ['account_number' => ACCT_C])['status'], 'new number without confirmation → 422');
    same(409, api('PUT', "v1/bank-accounts/{$B['id']}", $T['dave'], ['account_number' => ACCT_A, 'account_number_confirm' => ACCT_A])['status'], 'another account\'s number → 409');
    $e = api('PUT', "v1/bank-accounts/{$B['id']}", $T['dave'], ['label' => 'ICICI overdraft', 'branch' => 'BKC']);
    same([200, 'ICICI overdraft', 'BKC', 'XXXXXX2333', false], [$e['status'], $e['json']['data']['label'], $e['json']['data']['branch'], $e['json']['data']['account_number_masked'], $e['json']['data']['is_default']], 'edited; number and default kept');
    same(404, api('PUT', 'v1/bank-accounts/99999', $T['dave'], ['label' => 'x'])['status'], 'unknown → 404');

    // Default: exactly one, moved explicitly.
    same(403, api('POST', "v1/bank-accounts/{$B['id']}/default", $T['carol'], [])['status'], 'approver cannot change the default');
    same(200, api('POST', "v1/bank-accounts/{$B['id']}/default", $T['dave'], [])['status'], 'B becomes the default');
    same([$B['id']], array_map('intval', array_column($pdo->query('SELECT id FROM company_bank_accounts WHERE is_default')->fetchAll(), 'id')), 'only B is the default');
    same(200, api('POST', "v1/bank-accounts/{$A['id']}/default", $T['dave'], [])['status'], 'and back to A');
    $refused = '';
    try {
        $pdo->exec('UPDATE company_bank_accounts SET is_default = true');
    } catch (\PDOException $ex) {
        $refused = $ex->getMessage();
    }
    check(str_contains($refused, 'company_bank_accounts_one_default'), 'database refuses two defaults: ' . $refused);
    $refused = '';
    try {
        $pdo->exec('UPDATE company_bank_accounts SET is_default = false');
    } catch (\PDOException $ex) {
        $refused = $ex->getMessage();
    }
    check(str_contains($refused, 'exactly one active account must be the default'), 'database refuses no default among active accounts: ' . $refused);
    same(1, (int) $pdo->query('SELECT count(*) AS n FROM company_bank_accounts WHERE is_default')->fetch()['n'], 'still one default');

    // Deactivate / activate.
    same(403, api('POST', "v1/bank-accounts/{$A['id']}/deactivate", $T['carol'], [])['status'], 'approver cannot deactivate');
    $d = api('POST', "v1/bank-accounts/{$A['id']}/deactivate", $T['dave'], []);
    same(422, $d['status'], 'the default cannot be deactivated while another is active without choosing a new default');
    check(isset($d['json']['errors']['new_default_id']), 'asks for new_default_id');
    same(422, api('POST', "v1/bank-accounts/{$A['id']}/deactivate", $T['dave'], ['new_default_id' => $A['id']])['status'], 'not itself');
    $d = api('POST', "v1/bank-accounts/{$A['id']}/deactivate", $T['dave'], ['new_default_id' => $B['id']]);
    same([200, false, false], [$d['status'], $d['json']['data']['active'], $d['json']['data']['is_default']], 'A deactivated');
    same(true, (bool) $pdo->query("SELECT is_default FROM company_bank_accounts WHERE id = {$B['id']}")->fetch()['is_default'], 'B is the default now');
    same(409, api('POST', "v1/bank-accounts/{$A['id']}/default", $T['dave'], [])['status'], 'an inactive account cannot be the default');
    $detail = api('GET', "v1/payout-batches/{$ids['W1']}", $T['carol'])['json']['data'];
    same([$B['id']], array_column($detail['bank_accounts'], 'id'), 'picker offers active accounts only');
    same(422, api('POST', "v1/payout-batches/{$ids['W1']}/bank-file", $T['carol'], ['bank_account_id' => $A['id']])['status'], 'an inactive account cannot be debited');

    $d = api('POST', "v1/bank-accounts/{$B['id']}/deactivate", $T['dave'], []);
    same(200, $d['status'], 'the only active account may be deactivated');
    same(0, (int) $pdo->query('SELECT count(*) AS n FROM company_bank_accounts WHERE active')->fetch()['n'], 'none active');
    $r = api('POST', "v1/payout-batches/{$ids['W1']}/bank-file", $T['carol'], []);
    same(422, $r['status'], 'no active account → bank file refused');
    check(str_contains((string) $r['json']['message'], 'No active company bank account'), 'says so');
    same('', api('GET', 'v1/settings', $T['viewer'])['json']['data']['values']['debit_account_number'], 'no default → legacy key empty');

    $act = api('POST', "v1/bank-accounts/{$A['id']}/activate", $T['dave'], []);
    same([200, true, true], [$act['status'], $act['json']['data']['active'], $act['json']['data']['is_default']], 'A re-activated becomes the default (none was)');
    $act = api('POST', "v1/bank-accounts/{$B['id']}/activate", $T['dave'], []);
    same([200, true, false], [$act['status'], $act['json']['data']['active'], $act['json']['data']['is_default']], 'B re-activated, not the default');
    same(409, api('POST', "v1/bank-accounts/{$B['id']}/activate", $T['dave'], [])['status'], 'already active → 409');

    // Masking: no full number in any answer, audit row or plaintext column — for finance_admin too.
    $answers = json_encode([
        api('GET', 'v1/bank-accounts', $T['dave'])['json'],
        api('GET', 'v1/bank-accounts', $T['viewer'])['json'],
        api('GET', 'v1/settings', $T['dave'])['json'],
        api('GET', "v1/payout-batches/{$ids['W1']}", $T['dave'])['json'],
        api('GET', 'v1/audit-log?entity_type=company_bank_account', $T['dave'])['json'],
        $a['json'], $b['json'], $e['json'], $d['json'], $act['json'],
    ]);
    foreach ([ACCT_A, ACCT_B] as $n) {
        check(!str_contains($answers, $n), "API never shows {$n}");
        check(!str_contains(accountsDump(), $n), "{$n} nowhere in clear in the database");
    }
    same('XXXXXX9012', api('GET', 'v1/settings', $T['dave'])['json']['data']['values']['debit_account_number'], 'legacy key: default account, masked even for finance_admin');
    $actions = array_column($pdo->query("SELECT DISTINCT action FROM audit_log WHERE entity_type = 'company_bank_account'")->fetchAll(), 'action');
    foreach (['bank_account.created', 'bank_account.updated', 'bank_account.default_changed', 'bank_account.deactivated', 'bank_account.activated'] as $x) {
        check(in_array($x, $actions, true), "audited: {$x}");
    }
    $upd = $pdo->query("SELECT before, after, actor FROM audit_log WHERE action = 'bank_account.updated' ORDER BY id DESC LIMIT 1")->fetch();
    same(['dave@aicountly.com', 'ICICI overdraft'], [$upd['actor'], json_decode($upd['after'], true)['label']], 'who and what changed');
    $acct = ['A' => $A['id'], 'B' => $B['id']];
});

test('bank file: default debit account; checksum; full account numbers only in the file; download audited', function () use (&$ids, $T, $pdo, &$acct): void {
    $id = $ids['W1'];
    $g = api('POST', "v1/payout-batches/{$id}/bank-file", $T['carol'], ['format' => 'generic_neft_csv']);
    same(201, $g['status'], 'generated');
    same(2, $g['json']['data']['item_count'], 'held item excluded');
    same('41700.00', $g['json']['data']['total_amount'], '17700 + 24000');
    same(['id' => $acct['A'], 'label' => 'HDFC current', 'account_number_masked' => 'XXXXXX9012'], $g['json']['data']['debit_account'], 'debits the default account');
    same('file_generated', batchRow($id)['status'], 'batch file_generated');
    same($acct['A'], (int) $pdo->query("SELECT bank_account_id FROM bank_files WHERE id = {$g['json']['data']['id']}")->fetch()['bank_account_id'], 'bank_account_id stored');

    $fileId = $g['json']['data']['id'];
    same(403, api('GET', "v1/payout-batches/{$id}/bank-files/{$fileId}", $T['alice'])['status'], 'accountant cannot download');
    $d = api('GET', "v1/payout-batches/{$id}/bank-files/{$fileId}", $T['carol']);
    same(200, $d['status'], 'approver downloads');
    check(str_contains($d['headers'], 'text/csv'), 'csv');
    check(str_contains($d['body'], '001234567890') && str_contains($d['body'], '998877665544'), 'full account numbers in the file');
    check(str_contains($d['body'], ',' . ACCT_A . ','), 'the default account is the debit account in the file');
    check(!str_contains($d['body'], '556677889900'), 'held payee not in the file');
    check(str_contains($d['body'], 'W1-1') && str_contains($d['body'], '24000.00'), 'reference and net amount');
    preg_match('/X-Content-SHA256:\s*([0-9a-f]{64})/i', $d['headers'], $m);
    same(hash('sha256', $d['body']), $m[1] ?? null, 'checksum header matches the bytes');
    same($m[1] ?? null, $g['json']['data']['sha256'], 'stored checksum');
    same(1, (int) $pdo->query("SELECT count(*) AS n FROM audit_log WHERE action = 'bank_file.downloaded'")->fetch()['n'], 'download audited');
    $stored = $pdo->query('SELECT content_enc FROM bank_files LIMIT 1')->fetch()['content_enc'];
    check(str_starts_with($stored, 'v1:') && !str_contains($stored, '001234567890'), 'file encrypted at rest');

    same(422, api('POST', "v1/payout-batches/{$id}/bank-file", $T['carol'], [])['status'], 'regenerating needs a reason');
    $re = api('POST', "v1/payout-batches/{$id}/bank-file", $T['carol'], ['reason' => 'Wrong value date', 'format' => 'icici_hdfc_style_csv']);
    same(201, $re['status'], 'regenerated with a reason');
    same(true, $re['json']['data']['confirm_format'], 'bank-style flagged: confirm format with your bank');
    same(1, (int) $pdo->query('SELECT count(*) AS n FROM bank_files WHERE superseded')->fetch()['n'], 'previous file superseded');
});

test('bank file: chosen debit account and its format override; batch view shows label + last four', function () use (&$ids, $T, $pdo, &$acct): void {
    $id = $ids['W1'];
    $r = api('POST', "v1/payout-batches/{$id}/bank-file", $T['carol'], ['reason' => 'Pay from the OD account', 'bank_account_id' => $acct['B']]);
    same(201, $r['status'], 'regenerated from the chosen account');
    same(['icici_hdfc_style_csv', $acct['B'], 'XXXXXX2333'], [$r['json']['data']['format'], $r['json']['data']['debit_account']['id'], $r['json']['data']['debit_account']['account_number_masked']],
        'no format given → the account\'s own format');
    $row = $pdo->query("SELECT bank_account_id, debit_account_label, debit_account_last4 FROM bank_files WHERE id = {$r['json']['data']['id']}")->fetch();
    same([$acct['B'], 'ICICI overdraft', '2333'], [(int) $row['bank_account_id'], $row['debit_account_label'], $row['debit_account_last4']], 'stored on bank_files');
    $body = api('GET', "v1/payout-batches/{$id}/bank-files/{$r['json']['data']['id']}", $T['carol'])['body'];
    check(str_starts_with($body, 'PYMT_PROD_TYPE_CODE') && str_contains($body, 'PAB_VENDOR,NEFT,' . ACCT_B . ','), 'file debits the chosen account in its format');
    check(!str_contains($body, ACCT_A), 'not the default account');

    $explicit = api('POST', "v1/payout-batches/{$id}/bank-file", $T['carol'], ['reason' => 'Generic layout after all', 'bank_account_id' => $acct['B'], 'format' => 'generic_neft_csv']);
    same('generic_neft_csv', $explicit['json']['data']['format'], 'an explicit format still wins');
    same(422, api('POST', "v1/payout-batches/{$id}/bank-file", $T['carol'], ['reason' => 'x', 'bank_account_id' => 99999])['status'], 'unknown account → 422');

    $detail = api('GET', "v1/payout-batches/{$id}", $T['viewer'])['json']['data'];
    $active = array_values(array_filter($detail['bank_files'], static fn ($f) => $f['kind'] === 'payment' && !$f['superseded']));
    same(1, count($active), 'one current file');
    same(['id' => $acct['B'], 'label' => 'ICICI overdraft', 'account_number_masked' => 'XXXXXX2333'], $active[0]['debit_account'], 'batch view: which account the file debits');
    same($acct['A'], $detail['bank_accounts'][0]['id'], 'picker lists the default first');
    same('generic_neft_csv', $detail['default_bank_file_format'], 'settings default format shown for accounts without their own');
    check(!str_contains(json_encode($detail), ACCT_B), 'masked in the batch view');
    $audit = $pdo->query("SELECT after FROM audit_log WHERE action = 'bank_file.regenerated' ORDER BY id DESC LIMIT 1")->fetch()['after'];
    check(str_contains($audit, 'XXXXXX2333') && !str_contains($audit, ACCT_B), 'bank file audit names the debit account, masked');
});

test('nothing is marked paid without a recorded UTR (API and database)', function () use ($T, $pdo): void {
    $w11 = itemId('W1-1');
    $r = api('POST', "v1/payout-items/{$w11}/payment", $T['carol'], []);
    same(422, $r['status'], 'no UTR → 422');
    check(str_contains((string) $r['json']['message'], 'UTR is required'), 'message names the UTR');
    same(422, api('POST', "v1/payout-items/{$w11}/payment", $T['carol'], ['utr' => '   '])['status'], 'blank UTR → 422');
    same(422, api('POST', "v1/payout-items/{$w11}/payment", $T['carol'], ['utr' => 'AB#1'])['status'], 'malformed UTR → 422');
    same('approved', itemRow('W1-1')['status'], 'still approved');
    $blocked = false;
    try {
        $pdo->exec("UPDATE payout_items SET status = 'paid', paid_at = now() WHERE item_ref = 'W1-1'");
    } catch (\PDOException $e) {
        $blocked = str_contains($e->getMessage(), 'payout_items_paid_needs_utr');
    }
    check($blocked, 'database CHECK refuses paid without UTR');
});

test('record a UTR: item paid, TDS deducted, batch partially paid; a UTR is never reused', function () use (&$ids, $T, $pdo): void {
    $w11 = itemId('W1-1');
    $r = api('POST', "v1/payout-items/{$w11}/payment", $T['carol'], ['utr' => 'hdfcn52026101800001']);
    same(200, $r['status'], 'paid');
    same(['paid', 'HDFCN52026101800001'], [$r['json']['data']['status'], $r['json']['data']['utr']], 'status + normalised UTR');
    same('partially_paid', batchRow($ids['W1'])['status'], 'batch partially paid');
    same('deducted', $pdo->query("SELECT status FROM tds_ledger WHERE item_id = {$w11}")->fetch()['status'], 'ledger deducted');
    same(409, api('POST', 'v1/payout-items/' . itemId('W1-2') . '/payment', $T['carol'], ['utr' => 'HDFCN52026101800001'])['status'], 'duplicate UTR → 409');
    same(409, api('POST', "v1/payout-items/{$w11}/payment", $T['carol'], ['utr' => 'HDFCN52026101800009'])['status'], 'already paid → 409');
});

test('bank response file: dry run changes nothing; apply marks paid; unknown / held lines skipped', function () use (&$ids, $T): void {
    $id  = $ids['W1'];
    $csv = "Customer Reference,UTR Number,Status,Value Date\nW1-2,ICICN52026101800002,SUCCESS,{$GLOBALS['periodEnd']}\nW1-3,X,SUCCESS,\nNOPE-9,ICICN52026101800003,SUCCESS,\n";
    $dry = api('POST', "v1/payout-batches/{$id}/bank-response", $T['carol'], ['csv' => $csv]);
    same(200, $dry['status'], 'preview');
    same(true, $dry['json']['data']['dry_run'], 'dry run by default');
    same(['paid' => 1, 'failed' => 0, 'skipped' => 2], $dry['json']['data']['counts'], 'plan');
    same('approved', itemRow('W1-2')['status'], 'nothing changed by the preview');

    $app = api('POST', "v1/payout-batches/{$id}/bank-response", $T['carol'], ['csv' => $csv, 'dry_run' => false]);
    same(['paid' => 1, 'failed' => 0, 'skipped' => 2], $app['json']['data']['counts'], 'applied');
    $w12 = itemRow('W1-2');
    same(['paid', 'ICICN52026101800002'], [$w12['status'], $w12['utr']], 'W1-2 paid with the bank UTR');
    check(str_starts_with((string) $w12['paid_at'], $GLOBALS['periodEnd']), 'paid_at from the file');
    same('on_hold', itemRow('W1-3')['status'], 'held item untouched');
});

test('held item released back to Engage after approval (failed); batch settles', function () use (&$ids, $T, $pdo): void {
    $w13 = itemId('W1-3');
    same(403, api('POST', "v1/payout-items/{$w13}/fail", $T['alice'], ['reason' => 'x'])['status'], 'accountant cannot');
    $r = api('POST', "v1/payout-items/{$w13}/fail", $T['carol'], ['reason' => 'KYC not resolved this week']);
    same('failed', $r['json']['data']['status'], 'failed');
    check(str_starts_with((string) $r['json']['data']['failure_reason'], 'Released back to Engage'), 'reason');
    same('void', $pdo->query("SELECT status FROM tds_ledger WHERE item_id = {$w13}")->fetch()['status'], 'its TDS row is void');
    same('partially_paid', batchRow($ids['W1'])['status'], 'two paid, one released');
});

test('TDS threshold crossed mid-FY across batches; four-eyes on the preparer; switch it off', function () use (&$ids, $T): void {
    $W2 = batchPayload('PAYRUN-W2', [['W2-1', 'SLR-000001', '10000.00'], ['W2-2', 'SLR-000003', '25000.00'], ['W2-3', 'SLR-000002', '1000.00']]);
    $r = inbound($W2);
    same(201, $r['status'], 'W2 received');
    $ids['W2'] = $r['json']['data']['batch_id'];
    $a = itemRow('W2-1');
    // SLR-000001: ₹15,000 earlier this FY (no TDS), now ₹10,000 → TDS on the cumulative ₹25,000.
    same(['500.00', '1800.00', '11300.00'], [$a['tds_amount'], $a['gst_amount'], $a['net_amount']], 'crossing: 2% of 25,000; GST 18% of 10,000; net 10,000 + 1,800 − 500');
    check(str_contains((string) $a['tds_note'], 'Crosses the FY threshold'), 'note');
    same('500.00', itemRow('W2-2')['tds_amount'], 'released W1-3 does not count: 25,000 alone → 500');
    same('20.00', itemRow('W2-3')['tds_amount'], 'already crossed: 2% of 1,000');

    $id = $ids['W2'];
    same(200, api('POST', "v1/payout-batches/{$id}/submit", $T['carol'], [])['status'], 'approver submits');
    $v = (int) batchRow($id)['version'];
    $r = api('POST', "v1/payout-batches/{$id}/approve", $T['carol'], ['version' => $v]);
    same(403, $r['status'], 'preparer cannot approve');
    check(str_contains((string) $r['json']['message'], 'submitted this batch'), 'preparer message');

    same(200, api('PUT', 'v1/settings', $T['dave'], ['four_eyes_enabled' => false])['status'], 'admin turns four-eyes off');
    same(200, api('POST', "v1/payout-batches/{$id}/approve", $T['carol'], ['version' => $v])['status'], 'now the preparer may approve');
    same(200, api('PUT', 'v1/settings', $T['dave'], ['four_eyes_enabled' => true])['status'], 'back on');
});

test('W2: bank response with a rejection; a paid item reversed', function () use (&$ids, $T, $pdo): void {
    $id = $ids['W2'];
    same(201, api('POST', "v1/payout-batches/{$id}/bank-file", $T['bob'], [])['status'], 'file');
    $csv = "REF_NO,UTR,TRANSACTION STATUS,REMARKS\nW2-1,SBIN52026101800011,Processed,\nW2-2,,Rejected,Account closed\nW2-3,SBIN52026101800013,Processed,\n";
    $r = api('POST', "v1/payout-batches/{$id}/bank-response", $T['bob'], ['csv' => $csv, 'dry_run' => false]);
    same(['paid' => 2, 'failed' => 1, 'skipped' => 0], $r['json']['data']['counts'], 'two paid, one failed');
    same(['failed', 'Account closed'], [itemRow('W2-2')['status'], itemRow('W2-2')['failure_reason']], 'rejection recorded');
    same('partially_paid', batchRow($id)['status'], 'partially paid');

    $w23 = itemId('W2-3');
    same(422, api('POST', "v1/payout-items/{$w23}/reverse", $T['bob'], [])['status'], 'reversal needs a reason');
    same('reversed', api('POST', "v1/payout-items/{$w23}/reverse", $T['bob'], ['reason' => 'Returned by beneficiary bank'])['json']['data']['status'], 'reversed');
    same('void', $pdo->query("SELECT status FROM tds_ledger WHERE item_id = {$w23}")->fetch()['status'], 'reversed TDS row void');
    same('SBIN52026101800013', itemRow('W2-3')['utr'], 'UTR kept on the reversed item');
});

test('TDS register per quarter (Form 26Q data) and CSV export with full PAN', function () use ($T, $pdo): void {
    $fy = FiscalYear::current();
    $q  = FiscalYear::quarterFor(FiscalYear::today());
    $qEnd = FiscalYear::quarterFor($GLOBALS['periodEnd']);
    $r = api('GET', "v1/tds-register?fy={$fy}&quarter={$q}", $T['viewer']);
    same(200, $r['status'], 'viewer reads the register');
    $refs = array_map(static fn ($row) => $row['item_ref'], $r['json']['data']['rows']);
    sort($refs);
    // W1-2 was paid on the value date in the bank file (yesterday); the rest today.
    $expected = $q === $qEnd ? ['W1-1', 'W1-2', 'W2-1'] : ['W1-1', 'W2-1'];
    same($expected, $refs, 'paid items only (released, failed and reversed excluded)');
    same($q === $qEnd ? '1000.00' : '500.00', $r['json']['data']['totals']['tds_amount'], 'TDS total');
    $row = array_values(array_filter($r['json']['data']['rows'], static fn ($x) => $x['item_ref'] === 'W2-1'))[0];
    same(['XXXXXX234F', '194H', '10000.00', '500.00', '02'], [$row['deductee_pan'], $row['section'], $row['amount_paid'], $row['tds_amount'], $row['deductee_code']], '26Q fields, PAN masked');
    check(!str_contains(json_encode($r['json']), 'ABCPA1234F'), 'no full PAN in the JSON register');

    $x = api('GET', "v1/tds-register/export?fy={$fy}&quarter={$q}", $T['alice']);
    same(200, $x['status'], 'accountant exports');
    check(str_starts_with($x['body'], 'Deductee PAN,Deductee Name,Deductee Code,Section,Amount Paid/Credited'), 'csv header');
    check(str_contains($x['body'], 'ABCPA1234F,Asha Traders,02,194H,10000.00,2.00,500.00'), 'full PAN in the export');
    same(1, (int) $pdo->query("SELECT count(*) AS n FROM audit_log WHERE action = 'tds_register.exported' AND actor = 'alice@aicountly.com'")->fetch()['n'], 'export audited');
    same(422, api('GET', 'v1/tds-register?fy=2026-28&quarter=Q1', $T['viewer'])['status'], 'bad fy → 422');
});

test('cancel: unpaid items failed back to Engage, TDS voided; not once a file exists', function () use (&$ids, $T, $pdo): void {
    $r = inbound(batchPayload('PAYRUN-W3', [['W3-1', 'SLR-000002', '2000.00']]));
    $id = $r['json']['data']['batch_id'];
    same(422, api('POST', "v1/payout-batches/{$id}/cancel", $T['dave'], [])['status'], 'reason required');
    same(403, api('POST', "v1/payout-batches/{$id}/cancel", $T['alice'], ['reason' => 'x'])['status'], 'accountant cannot cancel');
    same(200, api('POST', "v1/payout-batches/{$id}/cancel", $T['dave'], ['reason' => 'Duplicate run'])['status'], 'admin cancels');
    same(['cancelled', 'Duplicate run'], [batchRow($id)['status'], batchRow($id)['cancel_reason']], 'cancelled');
    same('failed', itemRow('W3-1')['status'], 'item failed');
    same('void', $pdo->query('SELECT status FROM tds_ledger WHERE item_id = ' . itemId('W3-1'))->fetch()['status'], 'TDS void');
    same(409, api('POST', "v1/payout-batches/{$ids['W1']}/cancel", $T['dave'], ['reason' => 'x'])['status'], 'file generated → cannot cancel');
});

test('recalculate applies changed settings to a draft (GST mode)', function () use ($T): void {
    $id = inbound(batchPayload('PAYRUN-W4', [['W4-1', 'SLR-000001', '1000.00']]))['json']['data']['batch_id'];
    same('180.00', itemRow('W4-1')['gst_amount'], 'exclusive GST');
    same(200, api('PUT', 'v1/settings', $T['dave'], ['gst_mode' => 'none'])['status'], 'GST mode none');
    $r = api('POST', "v1/payout-batches/{$id}/recalculate", $T['alice'], []);
    same(200, $r['status'], 'recalculated');
    same(['0.00', 'none', '980.00'], [itemRow('W4-1')['gst_amount'], itemRow('W4-1')['gst_mode'], itemRow('W4-1')['net_amount']], 'no GST; TDS 2% (threshold crossed)');
    same(200, api('PUT', 'v1/settings', $T['dave'], ['gst_mode' => 'exclusive'])['status'], 'restore');
    same(422, api('PUT', 'v1/settings', $T['dave'], ['tds_rate_percent' => 'abc'])['status'], 'invalid setting → 422');
});

/** Run bin/outbox-deliver.php with the given environment. */
function deliver(array $env): string
{
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/outbox-deliver.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return $out;
}

function engageRequests(): array
{
    global $engageLog;

    return array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($engageLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
}

test('outbox: unconfigured sends nothing; retry with back-off; in order per item; contract payload + keys', function () use ($pdo, $dbEnv, $fakeEngage, $engageState): void {
    $pending = (int) $pdo->query("SELECT count(*) AS n FROM outbox WHERE status = 'pending'")->fetch()['n'];
    check($pending >= 10, "events queued ({$pending})");

    $out = deliver($dbEnv);
    check(str_contains($out, 'not configured'), 'unconfigured: says so');
    same([], engageRequests(), 'unconfigured: nothing sent');
    same($pending, (int) $pdo->query("SELECT count(*) AS n FROM outbox WHERE status = 'pending'")->fetch()['n'], 'nothing marked delivered');

    $env = $dbEnv + ['ENGAGE_API_BASE_URL' => $fakeEngage . '/api', 'ENGAGE_FINANCE_KEY' => ENGAGE_OUT_KEY];
    file_put_contents($engageState, json_encode(['statuses' => [500]]));
    $out = deliver($env);
    check(str_contains($out, 'retrying=1'), 'one retrying: ' . trim($out));
    $first = $pdo->query('SELECT * FROM outbox ORDER BY id LIMIT 1')->fetch();
    same(['pending', 1, 500], [$first['status'], (int) $first['attempts'], (int) $first['last_http_status']], 'failed event kept with attempts 1');
    check($pdo->query("SELECT next_attempt_at > now() + interval '1 minute' AS later FROM outbox WHERE id = {$first['id']}")->fetch()['later'], 'backed off');
    $blocked = $pdo->prepare("SELECT count(*) AS n FROM outbox WHERE aggregate_key = ? AND id > ? AND status = 'pending' AND attempts = 0");
    $blocked->execute([$first['aggregate_key'], $first['id']]);
    check((int) $blocked->fetch()['n'] >= 1, 'later events for the same item wait');

    $pdo->exec("UPDATE outbox SET next_attempt_at = now() WHERE status = 'pending'");
    deliver($env);
    same(0, (int) $pdo->query("SELECT count(*) AS n FROM outbox WHERE status <> 'delivered'")->fetch()['n'], 'all delivered');

    $log = engageRequests();
    same('/api/v1/inbound/finance/payout-status', $log[0]['uri'], 'contract path');
    same(ENGAGE_OUT_KEY, $log[0]['finance_key'], 'X-Finance-Key');
    same($first['event_key'], $log[0]['idempotency_key'], 'Idempotency-Key = event_key');
    $retried = array_values(array_filter($log, static fn ($r) => $r['idempotency_key'] === $first['event_key']));
    same([500, 200], array_map(static fn ($r) => $r['answered'], $retried), 'same key on the retry');

    $accepted = array_values(array_filter($log, static fn ($r) => $r['answered'] < 300));
    $w11 = array_values(array_filter($accepted, static fn ($r) => ($r['body']['item_ref'] ?? '') === 'W1-1'));
    same(['approved', 'paid'], array_map(static fn ($r) => $r['body']['status'], $w11), 'W1-1: approved then paid, in order');
    same(['batch_ref', 'item_ref', 'status', 'tds_amount', 'gst_amount', 'net_amount', 'utr', 'paid_at', 'reason'], array_keys($w11[1]['body']), 'contract fields');
    same(['PAYRUN-W1', 'HDFCN52026101800001', '0.00', '2700.00', '17700.00'], [$w11[1]['body']['batch_ref'], $w11[1]['body']['utr'], $w11[1]['body']['tds_amount'], $w11[1]['body']['gst_amount'], $w11[1]['body']['net_amount']], 'paid payload');
    check(is_string($w11[1]['body']['paid_at']) && $w11[0]['body']['utr'] === null, 'paid_at on paid, no UTR on approved');
    $w13 = array_values(array_filter($accepted, static fn ($r) => ($r['body']['item_ref'] ?? '') === 'W1-3'));
    same(['on_hold', 'failed'], array_map(static fn ($r) => $r['body']['status'], $w13), 'W1-3: on_hold at approval, then failed when released');
    same('KYC address mismatch', $w13[0]['body']['reason'], 'hold reason sent');
    $w23 = array_values(array_filter($accepted, static fn ($r) => ($r['body']['item_ref'] ?? '') === 'W2-3'));
    same(['approved', 'paid', 'reversed'], array_map(static fn ($r) => $r['body']['status'], $w23), 'W2-3: approved, paid, reversed');
});

test('outbox: dead after 12 attempts; only an admin re-queues', function () use ($pdo, $dbEnv, $fakeEngage, $engageState, $T): void {
    $id = (int) $pdo->query('SELECT id FROM outbox ORDER BY id DESC LIMIT 1')->fetch()['id'];
    $pdo->exec("UPDATE outbox SET status = 'pending', attempts = 11, next_attempt_at = now() WHERE id = {$id}");
    file_put_contents($engageState, json_encode(['statuses' => [503]]));
    $out = deliver($dbEnv + ['ENGAGE_API_BASE_URL' => $fakeEngage . '/api', 'ENGAGE_FINANCE_KEY' => ENGAGE_OUT_KEY]);
    check(str_contains($out, 'dead=1'), 'dead: ' . trim($out));
    same('dead', $pdo->query("SELECT status FROM outbox WHERE id = {$id}")->fetch()['status'], 'status dead');
    same(1, api('GET', 'v1/outbox', $T['viewer'])['json']['data']['counts']['dead'], 'visible in the outbox overview');
    same(403, api('POST', "v1/outbox/{$id}/requeue", $T['carol'], [])['status'], 'approver cannot re-queue');
    same(200, api('POST', "v1/outbox/{$id}/requeue", $T['dave'], [])['status'], 'admin re-queues');
    same(['pending', 0], [$pdo->query("SELECT status FROM outbox WHERE id = {$id}")->fetch()['status'], (int) $pdo->query("SELECT attempts FROM outbox WHERE id = {$id}")->fetch()['attempts']], 'pending again');
});

// Found end to end: ENGAGE_API_BASE_URL without /api reaches engage's SPA fallback, which answers the POST with
// 200 index.html. That is not Engage's receipt, so the callback must stay undelivered.
test('outbox: a 2xx that is not Engage\'s receipt (SPA 200 text/html, wrong item) is not delivered', function () use ($pdo, $dbEnv, $fakeEngage, $engageState): void {
    $id = (int) $pdo->query("SELECT id FROM outbox WHERE status = 'pending' ORDER BY id LIMIT 1")->fetch()['id'];
    check($id > 0, 'a pending event (re-queued above)');
    $env = $dbEnv + ['ENGAGE_API_BASE_URL' => $fakeEngage . '/api', 'ENGAGE_FINANCE_KEY' => ENGAGE_OUT_KEY];
    file_put_contents($engageState, json_encode(['statuses' => ['spa']]));
    $out = deliver($env);
    check(str_contains($out, 'delivered=0') && str_contains($out, 'retrying=1'), 'not delivered: ' . trim($out));
    $row = $pdo->query("SELECT * FROM outbox WHERE id = {$id}")->fetch();
    same(['pending', 1, 200], [$row['status'], (int) $row['attempts'], (int) $row['last_http_status']], 'kept pending with the 200 recorded');
    check(str_contains((string) $row['last_error'], 'ENGAGE_API_BASE_URL'), 'error names ENGAGE_API_BASE_URL: ' . $row['last_error']);
    same(false, \App\Services\Payouts\OutboxService::isEngageReceipt('{"ok":true,"data":{"item_ref":"W9-9"}}', 'W1-1'), 'receipt for another item is not this one');
    same(false, \App\Services\Payouts\OutboxService::isEngageReceipt('{"success":true}', 'W1-1'), 'a bare success flag is not a receipt');
    same(true, \App\Services\Payouts\OutboxService::isEngageReceipt('{"ok":true,"data":{"item_ref":"W1-1","status":"paid","result":"unchanged"}}', 'W1-1'), 'Engage receipt');

    $pdo->exec("UPDATE outbox SET next_attempt_at = now() WHERE id = {$id}");
    file_put_contents($engageState, json_encode(['statuses' => []]));
    deliver($env);
    same('delivered', $pdo->query("SELECT status FROM outbox WHERE id = {$id}")->fetch()['status'], 'delivered once Engage itself answers');
});

test('audit log: who / what / when / before / after; append-only', function () use ($T, $pdo): void {
    $r = api('GET', 'v1/audit-log?entity_type=payout_item&entity_id=' . itemId('W1-2'), $T['viewer']);
    $adj = array_values(array_filter($r['json']['data']['rows'], static fn ($x) => $x['action'] === 'item.adjusted'))[0];
    same(['alice@aicountly.com', '24500.00', '24000.00', 'Recover W40 overpayment'], [$adj['actor'], $adj['before']['net_amount'], $adj['after']['net_amount'], $adj['after']['reason']], 'adjustment audited');
    check($adj['occurred_at'] !== null, 'when');
    $actions = array_column($pdo->query('SELECT DISTINCT action FROM audit_log')->fetchAll(), 'action');
    foreach (['batch.received', 'payee.created', 'batch.submitted', 'batch.approved', 'item.held', 'item.paid', 'item.failed', 'item.reversed',
              'bank_file.generated', 'bank_file.regenerated', 'bank_response.imported', 'settings.updated', 'batch.cancelled', 'outbox.requeued'] as $a) {
        check(in_array($a, $actions, true), "audited: {$a}");
    }
    $refused = false;
    try {
        $pdo->exec("UPDATE audit_log SET actor = 'someone-else'");
    } catch (\PDOException $e) {
        $refused = str_contains($e->getMessage(), 'append-only');
    }
    check($refused, 'UPDATE refused');
    $refused = false;
    try {
        $pdo->exec('DELETE FROM audit_log');
    } catch (\PDOException $e) {
        $refused = str_contains($e->getMessage(), 'append-only');
    }
    check($refused, 'DELETE refused');
});

test('dashboard metrics and health', function () use ($T, $papi, $papiNoVault): void {
    $d = api('GET', 'v1/dashboard', $T['viewer'])['json']['data'];
    same(FiscalYear::current(), $d['fy'], 'fy');
    same(3, $d['payees'], 'payees');
    same(1, $d['batches_by_status']['cancelled'], 'cancelled batches');
    same(2, $d['batches_by_status']['partially_paid'], 'partially paid batches');
    same(1, $d['batches_by_status']['draft'], 'W4 draft');
    same(['items' => 1, 'net_amount' => '980.00'], $d['awaiting_review'], 'W4 awaiting review (as last recalculated)');
    same(3, $d['paid_this_fy']['items'], 'three paid');

    $h = http('GET', $papi . '/api/health')['json']['data']['checks'];
    same([true, true, true, true], [$h['database'], $h['data_key'], $h['engage_inbound'], $h['engage_callbacks']], 'payout checks true when configured');
    same(false, http('GET', $papiNoVault . '/api/health')['json']['data']['checks']['data_key'], 'no data key → false');
});

test('payee sync: changed bank details flag the item for the reviewer and are audited (masked)', function () use ($pdo, $T): void {
    $p = batchPayload('PAYRUN-W5', [['W5-1', 'SLR-000002', '3000.00']]);
    $p['items'][0]['payee']['bank'] = ['account_holder' => 'Ravi Kumar', 'account_number' => '111122223333', 'ifsc' => 'UTIB0000042'];
    $id = inbound($p)['json']['data']['batch_id'];
    same(true, (bool) itemRow('W5-1')['bank_changed'], 'bank_changed on the new item');
    $detail = api('GET', "v1/payout-batches/{$id}", $T['viewer'])['json']['data'];
    same(1, $detail['bank_changed_count'], 'counted on the batch');
    same('XXXXXX3333', $detail['items'][0]['bank_account_masked'], 'new account masked');
    $audit = $pdo->query("SELECT before, after FROM audit_log WHERE action = 'payee.updated' ORDER BY id DESC LIMIT 1")->fetch();
    check(str_contains($audit['before'], 'XXXXXX5544') && str_contains($audit['after'], 'XXXXXX3333'), 'before/after masked');
    check(!str_contains($audit['after'], '111122223333'), 'no full account in the audit log');
    same(false, (bool) itemRow('W1-2')['bank_changed'], 'earlier item unchanged');
    same('27XXXXXXXXXX1Z5', api('GET', 'v1/payees', $T['viewer'])['json']['data'][0]['gstin'], 'GSTIN masked (it embeds the PAN)');
});

/** Run bin/migrate.php with the given environment; stdout + stderr. */
function runMigrate(array $env): string
{
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return $out;
}

test('migration 002 moves the old debit_account_number setting into a default "Primary account" (encrypted, audited, once)', function () use ($adminPdo, $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $cacheDir, $vault): void {
    $legacyDb = $dbName . '_legacy';
    $adminPdo->exec("DROP DATABASE IF EXISTS {$legacyDb} WITH (FORCE)");
    $adminPdo->exec("CREATE DATABASE {$legacyDb}");
    $lp = null;
    try {
        $lp  = Database::connect($dbHost, $dbPort, $legacyDb, $dbUser, $dbPass);
        $dir = $cacheDir . '/migrations-001-only';
        @mkdir($dir, 0700, true);
        copy(__DIR__ . '/../database/migrations/001_payouts.sql', $dir . '/001_payouts.sql');
        same(['001_payouts'], (new Migrator($lp, $dir))->migrate(), 'a database deployed before 002');
        $lp->exec("UPDATE settings SET value = '50100012345678' WHERE key = 'debit_account_number'");
        $env = ['FINANCE_DB_HOST' => $dbHost, 'FINANCE_DB_PORT' => (string) $dbPort, 'FINANCE_DB_NAME' => $legacyDb,
            'FINANCE_DB_USER' => $dbUser, 'FINANCE_DB_PASSWORD' => $dbPass];
        $count = static fn (): int => (int) $lp->query('SELECT count(*) AS n FROM company_bank_accounts')->fetch()['n'];
        $setting = static fn (): string => (string) $lp->query("SELECT value FROM settings WHERE key = 'debit_account_number'")->fetch()['value'];

        $out = runMigrate($env);
        check(str_contains($out, 'applied 002_company_bank_accounts'), 'deploy applies 002: ' . $out);
        check(str_contains($out, 'once FINANCE_DATA_KEY is set'), 'without the data key the number waits: ' . $out);
        same([0, '50100012345678'], [$count(), $setting()], 'nothing moved without the key');

        $out = runMigrate($env + ['FINANCE_DATA_KEY' => DATA_KEY_HEX]);
        check(str_contains($out, 'Moved the debit account from Settings into Bank accounts as "Primary account" (XXXXXX5678, default)'), 'moved: ' . $out);
        check(!str_contains($out, '50100012345678'), 'the number is not printed');
        $row = $lp->query('SELECT * FROM company_bank_accounts')->fetch();
        same(['Primary account', true, true, null, null, '', '5678', 'legacy_setting', 'current', null],
            [$row['label'], $row['is_default'], $row['active'], $row['bank_name'], $row['ifsc'], $row['account_holder'], $row['account_number_last4'], $row['source'], $row['account_type'], $row['bank_file_format']],
            'first account, the default; unknown fields left blank');
        same('50100012345678', $vault->decrypt($row['account_number_enc']), 'encrypted with FINANCE_DATA_KEY');
        same('', $setting(), 'the setting no longer holds the number in clear (key kept)');
        $audit = $lp->query("SELECT actor, before, after FROM audit_log WHERE action = 'bank_account.migrated_from_setting'")->fetchAll();
        same(1, count($audit), 'move audited');
        check(str_contains($audit[0]['before'] . $audit[0]['after'], 'XXXXXX5678'), 'audit masked');
        $dump = '';
        foreach (['settings', 'company_bank_accounts', 'audit_log', 'schema_migrations'] as $t) {
            $dump .= json_encode($lp->query("SELECT * FROM {$t}")->fetchAll());
        }
        check(!str_contains($dump, '50100012345678'), 'no plaintext number left in the database');

        runMigrate($env + ['FINANCE_DATA_KEY' => DATA_KEY_HEX]);
        same(1, $count(), 're-running migrate moves nothing twice');

        $svc   = new CompanyBankAccountService($lp, $vault);
        $actor = ['actor' => 'dave@aicountly.com', 'name' => 'Dave', 'ip' => null];
        same(true, $svc->list()[0]['incomplete'], 'flagged incomplete for the admin');
        $e = throwsApi(422, static fn () => $svc->update((int) $row['id'], ['label' => 'Main account'], $actor), 'editing the moved account asks for the missing details');
        same(['bank_name', 'account_holder', 'ifsc'], array_keys($e->errors()), 'bank name, holder and IFSC');
        $u = $svc->update((int) $row['id'], ['bank_name' => 'HDFC Bank', 'account_holder' => 'Aicountly Technologies Pvt Ltd', 'ifsc' => 'HDFC0000001'], $actor);
        same([false, 'XXXXXX5678', true], [$u['incomplete'], $u['account_number_masked'], $u['is_default']], 'completed; number and default kept');

        // A value left in the setting while accounts already exist (e.g. written while the key was missing): adopted on
        // first use, not as the default; the same number again is only cleared.
        $lp->exec("UPDATE settings SET value = '60200011112222' WHERE key = 'debit_account_number'");
        $list = $svc->list();
        same([2, 'Primary account', false, '2222'], [count($list), $list[1]['label'], $list[1]['is_default'], $list[1]['account_number_last4']], 'adopted lazily, not the default');
        same('', $setting(), 'cleared');
        $lp->exec("UPDATE settings SET value = '60200011112222' WHERE key = 'debit_account_number'");
        same(['XXXXXX5678', 2, ''], [$svc->defaultMasked(), count($svc->list()), $setting()], 'known number: just cleared');
    } finally {
        $lp = null;
        usleep(100_000);
        $adminPdo->exec("DROP DATABASE IF EXISTS {$legacyDb} WITH (FORCE)");
    }
});
