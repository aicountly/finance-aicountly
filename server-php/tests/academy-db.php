<?php
declare(strict_types=1);

/**
 * Aicountly Academy fees end to end: PostgreSQL (the finance_test database, already migrated and with the seller profile
 * — Maharashtra, 27 — and the current FY's series that invoicing-db.php created), the real API under `php -S`, a stub
 * Razorpay / Cashfree (fake-gateway.php), the stub Engage (fake-engage.php) for payment events, the stub Gotenberg, and
 * bin/academy-fees-cron.php. Included by tests/run.php after invoicing-db.php.
 */

use App\Security\AicS2s;

echo "Academy fees (PostgreSQL + php -S)\n";

if (!isset($pdo, $papi, $payEnv, $fakeGot)) {
    echo "  SKIP PostgreSQL unreachable (see above)\n";

    return;
}

const ENG_KEY_ID      = 'engage-issue-2026-10';
const ENG_SECRET      = 'engage-to-finance-issue-secret-0123456789abcd';
const ENG_READ_ID     = 'engage-read-only';
const ENG_READ_SECRET = 'engage-to-finance-readonly-secret-0123456789';
const FIN_OUT_KEY_ID  = 'finance-issue-2026-10';
const FIN_OUT_SECRET  = 'finance-to-engage-s2s-secret-0123456789abcdef';
const RZP_TEST_KEY    = 'rzp_test_FinTest00001';
const RZP_TEST_SECRET = 'rzp-test-api-secret-DO-NOT-LEAK-42';
const RZP_HOOK_SECRET = 'rzp-webhook-secret-DO-NOT-LEAK-43';
const CF_TEST_APP     = 'TEST10FinanceApp0001';
const CF_TEST_SECRET  = 'cfsk_ma_test_DO-NOT-LEAK-44';
const SAC_TRAINING    = '999293';

$gwState = $cacheDir . '/gateway-state.json';
$gwLog   = $cacheDir . '/gateway-requests.log';
file_put_contents($gwState, '{}');
file_put_contents($gwLog, '');
$fakeGw = serve(__DIR__ . '/fake-gateway.php', ['FAKE_GATEWAY_STATE' => $gwState, 'FAKE_GATEWAY_LOG' => $gwLog]);

$academyEnv = $payEnv + [
    AicS2s::secretEnvName('engage', ENG_KEY_ID)  => ENG_SECRET,
    AicS2s::scopeEnvName('engage', ENG_KEY_ID)   => 'issue',
    AicS2s::secretEnvName('engage', ENG_READ_ID) => ENG_READ_SECRET,
    'FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID' => FIN_OUT_KEY_ID,
    'FINANCE_OUTBOUND_ENGAGE_S2S_SECRET' => FIN_OUT_SECRET,
    'FINANCE_RAZORPAY_API_BASE'          => $fakeGw,
    'FINANCE_CASHFREE_API_BASE'          => $fakeGw,
    'BASE_URL'                           => 'https://finance.test/api',
    'FINANCE_ACADEMY_RETURN_URL'         => 'https://aicountly.test/academy/thank-you',
    'GOTENBERG_URL'                      => $fakeGot,
    'FINANCE_S2S_ISSUE_PER_MINUTE'       => '500',
    'FINANCE_AUTHZ_CACHE_DIR'            => $cacheDir . '/academy',
];
$aapi = serve($serverRoot . '/public/index.php', $academyEnv);
$secrets = [RZP_TEST_SECRET, RZP_HOOK_SECRET, CF_TEST_SECRET, ENG_SECRET, FIN_OUT_SECRET, DATA_KEY_HEX];
$seen = [];   // every answer body, checked for secrets at the end

function aapi(string $method, string $path, ?string $token, mixed $body = null): array
{
    global $aapi, $seen;
    $res = api($method, $path, $token, $body, [], $aapi);
    $seen[] = $res['body'];

    return $res;
}

/** Signed Engage → Finance call (AIC-S2S v1). */
function engage(string $method, string $path, ?array $body = null, array $opt = []): array
{
    global $aapi, $seen;
    $signed = '/v1/inbound/engage/payment-links' . ($path === '' ? '' : '/' . $path);
    $raw    = $body === null ? '' : json_encode($body);
    $h      = AicS2s::sign($opt['secret'] ?? ENG_SECRET, 'engage', $opt['key'] ?? ENG_KEY_ID, 'local', $method, $signed, '', $raw, null, null, 'engage-staff-7');
    if (!empty($opt['unsigned'])) {
        $h = [];
    }
    $lines = array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($h), $h);
    if ($body !== null) {
        $lines[] = 'Content-Type: application/json';
    }
    if (array_key_exists('idem', $opt) ? $opt['idem'] !== null : $method === 'POST' && $path === '') {
        $lines[] = 'Idempotency-Key: ' . ($opt['idem'] ?? 'missing');
    }
    foreach ($opt['headers'] ?? [] as $l) {
        $lines[] = $l;
    }
    $res = http($method, $aapi . '/api' . $signed, $lines, $body === null ? null : $raw);
    $seen[] = $res['body'];

    return $res;
}

function gw(string $control, array $body): array
{
    global $fakeGw;

    return http('POST', $fakeGw . '/__control/' . $control, ['Content-Type: application/json'], json_encode($body))['json'] ?? [];
}

/** @return list<array<string,mixed>> */
function gwCalls(?string $prefix = null): array
{
    global $gwLog;
    $rows = array_map(static fn (string $l) => json_decode($l, true), file($gwLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

    return array_values(array_filter($rows, static fn (array $r) => $prefix === null || str_starts_with($r['method'] . ' ' . $r['uri'], $prefix)));
}

function rzpHook(array $payload, string $secret = RZP_HOOK_SECRET, ?string $eventId = null): array
{
    global $aapi;
    $raw = json_encode($payload);
    $h   = ['Content-Type: application/json', 'X-Razorpay-Signature: ' . hash_hmac('sha256', $raw, $secret)];
    if ($eventId !== null) {
        $h[] = 'X-Razorpay-Event-Id: ' . $eventId;
    }

    return http('POST', $aapi . '/api/v1/webhooks/razorpay', $h, $raw);
}

function cfHook(array $payload, string $secret = CF_TEST_SECRET): array
{
    global $aapi;
    $raw = json_encode($payload);
    $ts  = (string) (time() * 1000);

    return http('POST', $aapi . '/api/v1/webhooks/cashfree', ['Content-Type: application/json', 'x-webhook-timestamp: ' . $ts,
        'x-webhook-signature: ' . base64_encode(hash_hmac('sha256', $ts . $raw, $secret, true)), 'x-webhook-version: 2023-08-01'], $raw);
}

function linkRow(string $linkId): array
{
    global $pdo;
    $s = $pdo->prepare('SELECT * FROM fin_payment_links WHERE link_id = ?');
    $s->execute([$linkId]);

    return $s->fetch() ?: [];
}

function paymentsOf(int $linkId): array
{
    global $pdo;

    return $pdo->query("SELECT * FROM fin_gateway_payments WHERE link_id = {$linkId} ORDER BY id")->fetchAll();
}

function engageEvents(): array
{
    global $engageLog;

    return array_values(array_filter(array_map(static fn (string $l) => json_decode($l, true), file($engageLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
        static fn (array $r) => str_ends_with((string) parse_url($r['uri'], PHP_URL_PATH), '/payment-events')));
}

function academyCron(): string
{
    global $academyEnv;
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/academy-fees-cron.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        $academyEnv + ['PATH' => (string) getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return trim($out);
}

/** An Engage link request: one fee, GST 18 % inclusive, split for the student's state against the seller's (27). */
function feeRequest(string $academyRef, string $state = '27', int $amount = 1180000, array $extra = []): array
{
    $taxable = $amount - intdiv($amount * 1800 * 2 + 11800, 11800 * 2);
    $tax     = $amount - $taxable;
    $intra   = $state === '27';

    return $extra + [
        'source_ref'   => 'LD-2026-' . substr(md5($academyRef), 0, 6),
        'academy_ref'  => $academyRef,
        'stream'       => 'edge',
        'product_code' => 'edge',
        'seller_code'  => 'SLR-000001',
        'description'  => 'Aicountly Edge — Accounting & GST programme: fee instalment 1 of 2',
        'payer'        => ['name' => 'Ravi Kumar', 'email' => 'ravi.student@example.test', 'mobile' => '9876543210', 'state_code' => $state],
        'amount_paise' => $amount,
        'fee'          => ['taxable_paise' => $taxable, 'cgst_paise' => $intra ? $tax - intdiv($tax, 2) : 0, 'sgst_paise' => $intra ? intdiv($tax, 2) : 0,
            'igst_paise' => $intra ? 0 : $tax, 'sac' => SAC_TRAINING, 'gst_rate' => '18', 'price_basis' => 'inclusive'],
    ];
}

function rzpPaidEvent(array $link, array $payment, string $event = 'payment_link.paid'): array
{
    return ['entity' => 'event', 'account_id' => 'acc_FAKE', 'event' => $event, 'contains' => ['payment_link', 'order', 'payment'], 'created_at' => time(), 'payload' => [
        'payment_link' => ['entity' => ['id' => $link['id'], 'reference_id' => $link['reference_id'], 'status' => 'paid', 'amount' => $link['amount'],
            'amount_paid' => $link['amount_paid'], 'customer' => $link['customer']]],
        'payment'      => ['entity' => $payment],
    ]];
}

ini_set('error_log', $cacheDir . '/academy-error.log');
$L = [];   // link ids by name

test('dormant until configured: health says so; Engage gets 503 gateway_not_configured; webhooks 503; nothing stored', function () use ($papi, $pdo): void {
    $h = http('GET', $papi . '/api/health')['json']['data'];
    same('dormant', $h['academy_fees']['status'], 'no connection, no Engage key: dormant');
    same(true, $h['academy_fees']['checks']['schema'], 'schema migrated');
    $r = engage('POST', '', feeRequest('EDGE-ADM-0001'), ['idem' => 'lead-1-fee-1']);
    same([503, 'gateway_not_configured'], [$r['status'], $r['json']['error']['code'] ?? null], 'no gateway connected yet');
    same(true, $r['json']['error']['retryable'] ?? null, 'retryable');
    $w = rzpHook(['event' => 'payment_link.paid']);
    same(503, $w['status'], 'webhook without a configured secret');
    same(0, (int) $pdo->query('SELECT count(*) FROM fin_payment_links')->fetchColumn(), 'no link stored');
    same(1, (int) $pdo->query("SELECT count(*) FROM fin_gateway_webhook_events WHERE NOT signature_valid AND payload IS NULL AND fingerprint IS NULL")->fetchColumn(),
        'the refused webhook is logged as rejected, without payload');
});

test('Settings → Payment gateways: finance_admin only; secrets encrypted, write-only, never in an answer or the audit log; test connection', function () use ($T, $pdo): void {
    foreach (['viewer', 'alice', 'bob'] as $who) {
        same(403, aapi('GET', 'v1/gateway-connections', $T[$who])['status'], "{$who} cannot see gateway settings");
    }
    same(403, aapi('POST', 'v1/gateway-connections', $T['bob'], ['provider' => 'razorpay'])['status'], 'approver cannot add one');
    $list = aapi('GET', 'v1/gateway-connections', $T['dave']);
    same([200, [], 'https://finance.test/api/v1/webhooks/razorpay'], [$list['status'], $list['json']['data']['connections'], $list['json']['data']['webhook_urls']['razorpay']],
        'admin: empty, with the webhook URLs to paste into the dashboards');

    $bad = aapi('POST', 'v1/gateway-connections', $T['dave'], ['provider' => 'razorpay', 'mode' => 'test', 'key_id' => 'rzp_live_Wrong0001', 'key_secret' => '']);
    same(422, $bad['status'], 'invalid');
    same(['key_id', 'key_secret'], array_keys(array_diff_key($bad['json']['errors'], ['code' => 1])), 'test key must be rzp_test_…; secret required');

    $c = aapi('POST', 'v1/gateway-connections', $T['dave'], ['provider' => 'razorpay', 'mode' => 'test', 'label' => 'Razorpay (test)', 'key_id' => RZP_TEST_KEY,
        'key_secret' => RZP_TEST_SECRET, 'webhook_secret' => RZP_HOOK_SECRET]);
    same(201, $c['status'], 'saved');
    $rzp = $c['json']['data'];
    same([RZP_TEST_KEY, 'set (…K-42)', true, false, false], [$rzp['key_id'], $rzp['key_secret'], $rzp['webhook_secret_set'], $rzp['enabled'], $rzp['is_default']],
        'masked: key id, "set (…last4)", webhook secret set; disabled until enabled');
    $row = $pdo->query("SELECT * FROM fin_gateway_connections WHERE id = {$rzp['id']}")->fetch();
    check(str_starts_with($row['key_secret_enc'], 'v1:') && str_starts_with($row['webhook_secret_enc'], 'v1:'), 'both secrets stored as DataVault ciphertext');
    check(!str_contains(json_encode($row), RZP_TEST_SECRET) && !str_contains(json_encode($row), RZP_HOOK_SECRET), 'no secret in clear in the row');
    same(409, aapi('POST', 'v1/gateway-connections', $T['dave'], ['provider' => 'razorpay', 'mode' => 'test', 'key_id' => RZP_TEST_KEY, 'key_secret' => 'another-secret-1'])['status'],
        'one connection per provider and mode');

    $t = aapi('POST', "v1/gateway-connections/{$rzp['id']}/test", $T['dave']);
    same([200, false], [$t['status'], $t['json']['data']['ok']], 'the provider does not know the key yet: test fails');
    check(str_contains($t['json']['data']['message'], 'HTTP 401'), 'the provider\'s refusal is shown: ' . $t['json']['data']['message']);
    gw('keys', ['razorpay' => RZP_TEST_KEY . ':' . RZP_TEST_SECRET, 'cashfree' => CF_TEST_APP . ':' . CF_TEST_SECRET]);
    $t = aapi('POST', "v1/gateway-connections/{$rzp['id']}/test", $T['dave']);
    same(true, $t['json']['data']['ok'], 'test connection: the provider accepts the key');
    same(['GET /v1/payments?count=1', true], [gwCalls()[count(gwCalls()) - 1]['method'] . ' ' . gwCalls()[count(gwCalls()) - 1]['uri'], gwCalls()[count(gwCalls()) - 1]['auth_ok']],
        'a read-only authenticated call');
    $e = aapi('POST', "v1/gateway-connections/{$rzp['id']}/enable", $T['dave'])['json']['data'];
    same([true, true], [$e['enabled'], $e['is_default']], 'the first enabled connection becomes the default');

    $cf = aapi('POST', 'v1/gateway-connections', $T['dave'], ['provider' => 'cashfree', 'mode' => 'test', 'key_id' => CF_TEST_APP, 'key_secret' => CF_TEST_SECRET])['json']['data'];
    same([false, 'Cashfree signs webhooks with the client secret.'], [$cf['webhook_secret_set'], $cf['webhook_secret_note']], 'Cashfree without a separate webhook secret');
    same(true, aapi('POST', "v1/gateway-connections/{$cf['id']}/test", $T['dave'])['json']['data']['ok'], 'Cashfree test: 404 on an unknown link = credentials work');
    $cfE = aapi('POST', "v1/gateway-connections/{$cf['id']}/enable", $T['dave'])['json']['data'];
    same([true, false], [$cfE['enabled'], $cfE['is_default']], 'second one enabled, not default');

    $u = aapi('PUT', "v1/gateway-connections/{$rzp['id']}", $T['dave'], ['label' => 'Razorpay — Aicountly (test)', 'key_secret' => '']);
    same([200, 'Razorpay — Aicountly (test)', 'set (…K-42)', true], [$u['status'], $u['json']['data']['label'], $u['json']['data']['key_secret'], $u['json']['data']['last_test_ok']],
        'a blank secret keeps the stored one (and its last test)');
    same(true, aapi('POST', "v1/gateway-connections/{$rzp['id']}/test", $T['dave'])['json']['data']['ok'], 'still works');
    $audit = json_encode($pdo->query("SELECT before, after FROM audit_log WHERE entity_type = 'gateway_connection'")->fetchAll());
    check(substr_count($audit, 'razorpay') > 0 && !str_contains($audit, RZP_TEST_SECRET) && !str_contains($audit, RZP_HOOK_SECRET) && !str_contains($audit, CF_TEST_SECRET)
        && !str_contains($audit, 'K-42'), 'audited without secrets (not even their last four)');
    $o = aapi('GET', 'v1/academy/overview', $T['viewer'])['json']['data'];
    same([['provider' => 'razorpay', 'mode' => 'test', 'is_default' => true], ['provider' => 'cashfree', 'mode' => 'test', 'is_default' => false]],
        array_map(static fn (array $p) => ['provider' => $p['provider'], 'mode' => $p['mode'], 'is_default' => $p['is_default']], $o['providers']), 'every role sees which providers are connected');
    check(!str_contains(json_encode($o), RZP_TEST_KEY), '…but not the key ids');
});

test('Engage creates a link (AIC-S2S, issue key, Idempotency-Key): GST split checked, created at Razorpay, idempotent, one open link per fee', function () use ($T, $pdo, &$L): void {
    $req = feeRequest('EDGE-ADM-0001');
    same([401, 'signature_missing'], [($r = engage('POST', '', $req, ['unsigned' => true, 'idem' => 'k-unsigned-1']))['status'], $r['json']['error']['code'] ?? null], 'unsigned');
    same([403, 'route_not_allowed_for_key'], [($r = engage('POST', '', $req, ['key' => ENG_READ_ID, 'secret' => ENG_READ_SECRET, 'idem' => 'k-read-1']))['status'],
        $r['json']['error']['code'] ?? null], 'a read key may not create links');
    same([401, 'signature_missing'], [($r = http('POST', $GLOBALS['aapi'] . '/api/v1/inbound/engage/payment-links', ['Authorization: Bearer ' . $T['dave'],
        'Content-Type: application/json'], json_encode($req)))['status'], $r['json']['error']['code'] ?? null], 'a staff token is not accepted');
    same([400, 'idempotency_key_required'], [($r = engage('POST', '', $req, ['idem' => null]))['status'], $r['json']['error']['code'] ?? null], 'Idempotency-Key required');
    $v = engage('POST', '', ['payer' => []] + $req, ['idem' => 'k-invalid-1']);
    same([422, 'validation_failed'], [$v['status'], $v['json']['error']['code'] ?? null], 'invalid payer');
    check(isset($v['json']['error']['details']['payer.name'], $v['json']['error']['details']['payer.mobile']), 'field details');
    $inter = $req;
    $inter['payer']['state_code'] = '29';
    $m = engage('POST', '', $inter, ['idem' => 'k-mismatch-1']);
    same([422, 'fee_breakdown_mismatch', 180000, 'inter_state'], [$m['status'], $m['json']['error']['code'] ?? null, $m['json']['error']['details']['expected']['igst_paise'] ?? null,
        $m['json']['error']['details']['expected']['supply_type'] ?? null], 'CGST/SGST sent for a Karnataka student: refused with Finance\'s IGST figures');
    same(0, (int) $pdo->query('SELECT count(*) FROM fin_payment_links')->fetchColumn(), 'nothing stored for refused requests');

    $before = count(gwCalls('POST /v1/payment_links'));
    $c = engage('POST', '', $req, ['idem' => 'lead-1-fee-1']);
    same(201, $c['status'], 'created: ' . $c['body']);
    $d = $c['json']['data'];
    check((bool) preg_match('/^fin_pl_[0-9A-Z]{26}$/', $d['link_id']), 'public link id');
    same(['created', 'razorpay', 'test', 1180000, 0, 'EDGE-ADM-0001', $req['source_ref'], 'edge'], [$d['status'], $d['provider'], $d['mode'], $d['amount_paise'], $d['amount_paid_paise'],
        $d['academy_ref'], $d['source_ref'], $d['product_code']], 'link view');
    check(str_starts_with((string) $d['url'], 'https://rzp.test/i/plink_FAKE'), 'the Razorpay short URL');
    same(['taxable_paise' => 1000000, 'cgst_paise' => 90000, 'sgst_paise' => 90000, 'igst_paise' => 0, 'sac' => SAC_TRAINING, 'gst_rate' => '18.00', 'price_basis' => 'inclusive',
        'discount' => null], $d['fee'], 'fee snapshot (no discount)');
    $calls = gwCalls('POST /v1/payment_links');
    same($before + 1, count($calls), 'one call to Razorpay');
    $b = end($calls)['body'];
    same([true, 1180000, $d['link_id'], '+919876543210', $d['link_id'], 'EDGE-ADM-0001', 'https://aicountly.test/academy/thank-you'], [end($calls)['auth_ok'], $b['amount'], $b['reference_id'],
        $b['customer']['contact'], $b['notes']['fin_link_id'], $b['notes']['academy_ref'], $b['callback_url']], 'what Razorpay was asked');
    $L['A'] = $d['link_id'];

    $again = engage('POST', '', $req, ['idem' => 'lead-1-fee-1']);
    same([200, $d['link_id'], true], [$again['status'], $again['json']['data']['link_id'], $again['json']['data']['duplicate'] ?? null], 'same request again: same link');
    same($before + 1, count(gwCalls('POST /v1/payment_links')), '…and no second link at Razorpay');
    same([409, 'idempotency_conflict'], [($r = engage('POST', '', ['amount_paise' => 1180001] + $req, ['idem' => 'lead-1-fee-1']))['status'], $r['json']['error']['code'] ?? null],
        'same key, different request');
    $dup = engage('POST', '', $req, ['idem' => 'lead-1-fee-1-again']);
    same([409, 'open_link_exists', $d['link_id']], [$dup['status'], $dup['json']['error']['code'] ?? null, $dup['json']['error']['details']['link_id'] ?? null],
        'another key for the same fee: refused, pointing at the open link');
    $g = engage('GET', $d['link_id']);
    same([200, 'created', []], [$g['status'], $g['json']['data']['status'], $g['json']['data']['payments']], 'GET the link');
    same([404, 'not_found'], [($r = engage('GET', 'fin_pl_0000000000000000000000000X'))['status'], $r['json']['error']['code'] ?? null], 'unknown link');
    $a = $pdo->query("SELECT actor, actor_name FROM audit_log WHERE action = 'payment_link.requested'")->fetch();
    same(['service:engage', 'engage-staff-7'], [$a['actor'], $a['actor_name']], 'audited as Engage, with X-AIC-Actor');
    check(!str_contains($c['headers'], 'Access-Control-Allow-Origin'), 'no CORS on the S2S route');
});

test('provider failure: 502 provider_error (retryable), link failed; the same request again finds the link the provider already made — never two', function () use ($pdo, &$L): void {
    $req = feeRequest('EDGE-ADM-0002');
    gw('fail', ['key' => 'POST /v1/payment_links', 'times' => 1, 'status' => 503]);
    $f = engage('POST', '', $req, ['idem' => 'lead-2-fee-1']);
    same([502, 'provider_error', true, 'failed'], [$f['status'], $f['json']['error']['code'] ?? null, $f['json']['error']['retryable'] ?? null, $f['json']['error']['details']['status'] ?? null],
        'Razorpay answered 503');
    $linkId = (string) $f['json']['error']['details']['link_id'];
    same(['failed', null], [linkRow($linkId)['status'], linkRow($linkId)['provider_link_id']], 'stored as failed');
    // The provider did create it after all (a timeout from Finance's side): the retry must adopt it, not create another.
    $made = http('POST', $GLOBALS['fakeGw'] . '/v1/payment_links', ['Authorization: Basic ' . base64_encode(RZP_TEST_KEY . ':' . RZP_TEST_SECRET), 'Content-Type: application/json'],
        json_encode(['amount' => 1180000, 'currency' => 'INR', 'reference_id' => $linkId, 'description' => 'x', 'customer' => ['contact' => '+919876543210'], 'expire_by' => time() + 9999]));
    $posts = count(gwCalls('POST /v1/payment_links'));
    $r = engage('POST', '', $req, ['idem' => 'lead-2-fee-1']);
    same([200, 'created', $made['json']['short_url']], [$r['status'], $r['json']['data']['status'], $r['json']['data']['url']], 'retried: the existing provider link is adopted');
    same($posts, count(gwCalls('POST /v1/payment_links')), 'looked up by reference_id, not created twice');
    same(2, (int) linkRow($linkId)['create_attempts'], 'two attempts recorded');
    $L['B'] = $linkId;
});

test('Razorpay webhook: signature over the raw body, re-read from Razorpay, link paid, B2C invoice (CGST + SGST), Engage event queued; replay is a no-op', function () use ($pdo, $T, &$L): void {
    $link = linkRow($L['A']);
    $paid = gw('razorpay/pay', ['plink_id' => $link['provider_link_id'], 'method' => 'upi']);
    $payload = rzpPaidEvent($paid['link'], $paid['payment']);

    $bad = rzpHook($payload, 'not-the-webhook-secret', 'evt_A1');
    same(401, $bad['status'], 'wrong signature refused');
    same(0, count(paymentsOf((int) $link['id'])), 'nothing recorded');
    $tampered = $payload;
    $tampered['payload']['payment']['entity']['amount'] = 100;
    $raw = json_encode($tampered);
    same(401, http('POST', $GLOBALS['aapi'] . '/api/v1/webhooks/razorpay', ['Content-Type: application/json',
        'X-Razorpay-Signature: ' . hash_hmac('sha256', json_encode($payload), RZP_HOOK_SECRET)], $raw)['status'], 'a body changed after signing is refused');

    $ok = rzpHook($payload, RZP_HOOK_SECRET, 'evt_A1');
    same([200, 'processed'], [$ok['status'], $ok['json']['outcome'] ?? null], 'processed: ' . $ok['body']);
    $pays = paymentsOf((int) $link['id']);
    same(1, count($pays), 'one payment');
    $p = $pays[0];
    same(['verified', $paid['payment']['id'], $paid['payment']['id'], 1180000, 'upi', 'issued'], [$p['verification_status'], $p['provider_payment_id'], $p['gateway_payment_ref'],
        (int) $p['amount_paise'], $p['method'], $p['invoice_state']], 'verified by re-reading the link at Razorpay; invoiced');
    check(count(gwCalls('GET /v1/payment_links/' . $link['provider_link_id'])) >= 1, 'Finance read the link back from Razorpay');
    $l = linkRow($L['A']);
    same(['paid', 1180000], [$l['status'], (int) $l['amount_paid_paise']], 'link paid');

    $inv = $pdo->query("SELECT * FROM fin_invoices WHERE source_ref = 'payment:{$p['id']}'")->fetch();
    same(['academy_student', null, 'payment', null, 'Ravi Kumar', '27', '27', 'intra_state', 1000000, 90000, 90000, 0, 1180000, 'Razorpay', $paid['payment']['id']],
        [$inv['buyer_kind'], $inv['cmp_id'], $inv['source'], $inv['buyer_gstin'], $inv['buyer_legal_name'], $inv['buyer_state_code'], $inv['place_of_supply'], $inv['supply_type'],
            (int) $inv['taxable_paise'], (int) $inv['cgst_paise'], (int) $inv['sgst_paise'], (int) $inv['igst_paise'], (int) $inv['total_paise'], $inv['payment_gateway'],
            $inv['payment_reference']], 'tax invoice to the student: no company, unregistered, place of supply Maharashtra, CGST + SGST');
    same('Maharashtra, India', $inv['buyer_address'], 'below ₹50,000 taxable the address may be just the state');
    $line = $pdo->query("SELECT * FROM fin_invoice_lines WHERE invoice_id = {$inv['id']}")->fetch();
    same(['edge', 'academy_fee', SAC_TRAINING, '18.00', 'inclusive', 1180000], [$line['item_code'], $line['item_kind'], $line['sac'], $line['gst_rate'], $line['price_basis'],
        (int) $line['amount_paise']], 'the fee line as fixed on the link');
    same(1, (int) $pdo->query("SELECT count(*) FROM fin_doc_pdfs WHERE invoice_id = {$inv['id']} AND pdf IS NOT NULL")->fetchColumn(), 'PDF stored');

    $ev = $pdo->query("SELECT * FROM fin_engage_payment_events WHERE link_id = {$link['id']}")->fetchAll();
    same([1, 'payment.succeeded', 'pending'], [count($ev), $ev[0]['event_type'], $ev[0]['status']], 'payment.succeeded queued in the verifying transaction');
    $body = json_decode($ev[0]['payload'], true);
    same([$link['source_ref'], $paid['payment']['id'], 'razorpay', 'edge', '11800.00', 1180000, $ev[0]['event_id']], [$body['lead_code'], $body['payment_ref'], $body['provider'],
        $body['product_code'], $body['amount'], $body['amount_paise'], $body['event_id']], 'event body: lead_code, raw pay_… id, provider, product, amount');

    $hook = $pdo->query("SELECT * FROM fin_gateway_webhook_events WHERE provider_event_id = 'evt_A1'")->fetch();
    same(['processed', 'payment_link.paid', true, (int) $link['id'], (int) $p['id']], [$hook['outcome'], $hook['event_type'], (bool) $hook['signature_valid'], (int) $hook['link_id'],
        (int) $hook['gateway_payment_id']], 'webhook log');
    foreach (['ravi.student@example.test', '+919876543210', 'student@okbank'] as $pii) {
        check(!str_contains((string) $hook['payload'], $pii) && !str_contains((string) $p['raw_event'], $pii), "stored event scrubbed of {$pii}");
    }

    $replay = rzpHook($payload, RZP_HOOK_SECRET, 'evt_A1');
    same([200, true], [$replay['status'], $replay['json']['duplicate'] ?? null], 'replay of the same delivery: duplicate');
    same(2, (int) $pdo->query("SELECT deliveries FROM fin_gateway_webhook_events WHERE provider_event_id = 'evt_A1'")->fetchColumn(), 'counted, not processed again');
    same(200, rzpHook($payload, RZP_HOOK_SECRET, 'evt_A1-retry')['status'], 'the same payment in another event');
    same([1, 1, 1], [count(paymentsOf((int) $link['id'])), (int) $pdo->query("SELECT count(*) FROM fin_invoices WHERE buyer_kind = 'academy_student'")->fetchColumn(),
        (int) $pdo->query("SELECT count(*) FROM fin_engage_payment_events WHERE link_id = {$link['id']}")->fetchColumn()], 'still one payment, one invoice, one event');

    $g = engage('GET', $L['A'])['json']['data'];
    same(['paid', $paid['payment']['id'], 'verified', $inv['doc_no']], [$g['status'], $g['payments'][0]['payment_ref'], $g['payments'][0]['verification_status'],
        $g['payments'][0]['invoice']['doc_no']], 'Engage sees it paid, with the invoice number');
    $reg = aapi('GET', 'v1/invoices?buyer_kind=academy_student', $T['viewer'])['json']['data'];
    same([1, null, 'academy_student'], [$reg['total'], $reg['rows'][0]['cmp_id'], $reg['rows'][0]['buyer_kind']], 'invoice register: Academy invoices filter');
    $doc = aapi('GET', "v1/invoices/{$inv['id']}", $T['viewer'])['json']['data'];
    same([null, 'academy_student', 'Online payment'], [$doc['cmp_id'], $doc['buyer_kind'], $doc['payment']['method_label']], 'invoice detail for staff');
});

test('cron delivers payment events to Engage AIC-S2S signed (client finance), retries with back-off, dead on refusal, admin re-queues', function () use ($pdo, $T, $engageState, &$L): void {
    file_put_contents($engageState, json_encode(['statuses' => [503]]));
    $out = academyCron();
    check(str_contains($out, 'engage=on attempted=1 delivered=0 retrying=1'), 'Engage down: retried later — ' . $out);
    $pdo->exec("UPDATE fin_engage_payment_events SET next_attempt_at = now() WHERE status = 'pending'");
    $out = academyCron();
    check(str_contains($out, 'delivered=1'), 'delivered — ' . $out);
    $sent = engageEvents();
    $last = end($sent);
    same(['/api/v1/inbound/finance/payment-events', 'finance', FIN_OUT_KEY_ID, 'local'], [$last['uri'], $last['aic']['client'], $last['aic']['key_id'], $last['aic']['environment']],
        'signed as client finance with the outbound key id');
    $canonical = AicS2s::canonical('POST', '/v1/inbound/finance/payment-events', '', $last['aic']['timestamp'], $last['aic']['nonce'], $last['raw']);
    same(AicS2s::signature(FIN_OUT_SECRET, $canonical), $last['aic']['signature'], 'Engage can verify the signature with the shared secret (AicS2sSignature)');
    same([$last['body']['event_id'], linkRow($L['A'])['source_ref']], [$last['idempotency_key'], $last['body']['lead_code']], 'Idempotency-Key = event_id; lead code');
    same(2, count($sent), 'the same event_id both times');
    same($sent[0]['body']['event_id'], $sent[1]['body']['event_id'], 'event_id stable across retries');
    same(['delivered', 2], array_values($pdo->query("SELECT status, attempts FROM fin_engage_payment_events WHERE link_id = (SELECT id FROM fin_payment_links WHERE link_id = '{$L['A']}')")->fetch()),
        'delivered after 2 attempts');

    // Engage refuses an event (e.g. unknown lead): dead at once; a finance admin re-queues it after the fix.
    $pdo->exec("UPDATE fin_engage_payment_events SET status = 'pending', next_attempt_at = now() WHERE link_id = (SELECT id FROM fin_payment_links WHERE link_id = '{$L['A']}')");
    file_put_contents($engageState, json_encode(['statuses' => [422]]));
    check(str_contains(academyCron(), 'dead=1'), '422 → dead');
    $dead = aapi('GET', 'v1/academy/engage-events', $T['viewer'])['json']['data'];
    same([1, false], [$dead['counts']['dead'], $dead['can_requeue']], 'viewer sees the dead event, cannot re-queue');
    check(str_starts_with((string) $dead['recent'][0]['last_error'], 'Engage refused the event'), 'reason shown');
    $id = $dead['recent'][0]['id'];
    same(403, aapi('POST', "v1/academy/engage-events/{$id}/requeue", $T['bob'])['status'], 'approver cannot re-queue');
    same(200, aapi('POST', "v1/academy/engage-events/{$id}/requeue", $T['dave'])['status'], 'finance admin re-queues');
    same(409, aapi('POST', "v1/academy/engage-events/{$id}/requeue", $T['dave'])['status'], 'only dead events');
    check(str_contains(academyCron(), 'delivered=1'), 'delivered after the re-queue');
    same(1, (int) $pdo->query("SELECT count(*) FROM audit_log WHERE action = 'engage_event.requeued'")->fetchColumn(), 'audited');
});

test('Cashfree, inter-state student: IGST, rupee link, webhook signed with the client secret, cf_payment_id as payment_ref', function () use ($pdo, &$L): void {
    $req = feeRequest('ASP-REG-0007', '29', 590000, ['stream' => 'aspire', 'product_code' => 'aspire', 'provider' => 'cashfree',
        'description' => 'Aicountly Aspire — GST workshop, Bengaluru', 'expire_at' => date(DATE_ATOM, time() + 3 * 86400)]);
    $c = engage('POST', '', $req, ['idem' => 'lead-7-aspire']);
    same([201, 'cashfree', 'created'], [$c['status'], $c['json']['data']['provider'] ?? null, $c['json']['data']['status'] ?? null], 'created at Cashfree: ' . $c['body']);
    $linkId = $c['json']['data']['link_id'];
    same(['taxable_paise' => 500000, 'cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 90000], array_intersect_key($c['json']['data']['fee'],
        array_flip(['taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise'])), 'IGST for a Karnataka student');
    $call = gwCalls('POST /pg/links');
    $b = end($call)['body'];
    same([true, '2023-08-01', $linkId, 5900, '9876543210', 'https://finance.test/api/v1/webhooks/cashfree'], [end($call)['auth_ok'], end($call)['api_version'], $b['link_id'],
        $b['link_amount'], $b['customer_details']['customer_phone'], $b['link_meta']['notify_url']], 'Cashfree request: our id, rupees, 10-digit phone, notify URL');

    $paid  = gw('cashfree/pay', ['link_id' => $linkId]);
    $event = ['type' => 'PAYMENT_LINK_EVENT', 'event_time' => date(DATE_ATOM), 'data' => ['link_id' => $linkId, 'link_status' => 'PAID', 'link_amount' => '5900.00',
        'link_amount_paid' => '5900.00', 'customer_details' => ['customer_phone' => '9876543210', 'customer_email' => 'ravi.student@example.test'],
        'order' => ['order_id' => $paid['order']['order_id'], 'order_amount' => '5900.00', 'transaction_id' => 1021206, 'transaction_status' => 'SUCCESS']]];
    same(401, cfHook($event, 'wrong-secret')['status'], 'wrong secret refused');
    $ok = cfHook($event);
    same([200, 'processed'], [$ok['status'], $ok['json']['outcome'] ?? null], 'processed: ' . $ok['body']);
    $p = paymentsOf((int) linkRow($linkId)['id'])[0];
    same(['verified', $paid['order']['order_id'], $paid['cf_payment_id'], 590000, 'upi'], [$p['verification_status'], $p['provider_payment_id'], $p['gateway_payment_ref'],
        (int) $p['amount_paise'], $p['method']], 'keyed by order_id; cf_payment_id read from the order\'s payments');
    $inv = $pdo->query("SELECT * FROM fin_invoices WHERE source_ref = 'payment:{$p['id']}'")->fetch();
    same(['29', 'inter_state', 0, 0, 90000, 590000, 'Cashfree'], [$inv['place_of_supply'], $inv['supply_type'], (int) $inv['cgst_paise'], (int) $inv['sgst_paise'],
        (int) $inv['igst_paise'], (int) $inv['total_paise'], $inv['payment_gateway']], 'IGST invoice');
    $ev = json_decode((string) $pdo->query("SELECT payload FROM fin_engage_payment_events WHERE gateway_payment_id = {$p['id']}")->fetchColumn(), true);
    same(['cashfree', $paid['cf_payment_id'], 'aspire', '5900.00'], [$ev['provider'], $ev['payment_ref'], $ev['product_code'], $ev['amount']], 'Engage gets the bare cf_payment_id');
    check(is_string($ev['payment_ref']) && ctype_digit($ev['payment_ref']), 'as a string of digits, no prefix');
    same(200, cfHook($event)['status'], 'a retried delivery');
    same(1, count(paymentsOf((int) linkRow($linkId)['id'])), 'still one payment');
    $L['C'] = $linkId;
});

test('provider unreachable at webhook time: payment stays pending (no invoice, no event); the cron confirms it later', function () use ($pdo, &$L): void {
    $c = engage('POST', '', feeRequest('EDGE-ADM-0003'), ['idem' => 'lead-3-fee-1']);
    same(201, $c['status'], 'link');
    $link = linkRow($c['json']['data']['link_id']);
    $paid = gw('razorpay/pay', ['plink_id' => $link['provider_link_id']]);
    gw('fail', ['key' => 'GET /v1/payment_links/' . $link['provider_link_id'], 'times' => 1, 'status' => 502]);
    $ok = rzpHook(rzpPaidEvent($paid['link'], $paid['payment']), RZP_HOOK_SECRET, 'evt_C1');
    same(200, $ok['status'], 'the webhook is still acknowledged');
    $p = paymentsOf((int) $link['id'])[0];
    same(['pending', 'not_issued', 'created'], [$p['verification_status'], $p['invoice_state'], linkRow($link['link_id'])['status']], 'not verified, not invoiced, link not paid');
    same(0, (int) $pdo->query("SELECT count(*) FROM fin_engage_payment_events WHERE link_id = {$link['id']}")->fetchColumn(), 'nothing told to Engage');
    $pdo->exec("UPDATE fin_gateway_payments SET updated_at = now() - interval '2 minutes' WHERE id = {$p['id']}");
    check(str_contains(academyCron(), 'verified=1'), 'cron verified it');
    $p = paymentsOf((int) $link['id'])[0];
    same(['verified', 'issued', 'paid'], [$p['verification_status'], $p['invoice_state'], linkRow($link['link_id'])['status']], 'verified, invoiced, paid');
    $L['D'] = $link['link_id'];
});

test('Razorpay link answer without its payments: confirmed through the payment itself and the link\'s order', function () use ($pdo): void {
    $c = engage('POST', '', feeRequest('EDGE-ADM-0004'), ['idem' => 'lead-4-fee-1']);
    $link = linkRow($c['json']['data']['link_id']);
    $paid = gw('razorpay/pay', ['plink_id' => $link['provider_link_id']]);
    gw('razorpay/hide-payments', ['plink_id' => $link['provider_link_id']]);
    same(200, rzpHook(rzpPaidEvent($paid['link'], $paid['payment']), RZP_HOOK_SECRET, 'evt_D1')['status'], 'webhook');
    same('verified', paymentsOf((int) $link['id'])[0]['verification_status'], 'verified via GET /v1/payments/{id} (same order)');
    check(count(gwCalls('GET /v1/payments/' . $paid['payment']['id'])) === 1, 'the payment was read');
});

test('a discounted fee (scholarship): GST on the price after discount; the invoice line carries list, discount and ref (invoice-line discount rules)', function () use ($pdo): void {
    $req = feeRequest('EDGE-ADM-0006');   // ₹11,800 incl. GST after a ₹2,950 scholarship on a ₹14,750 list fee
    $bad = engage('POST', '', ['fee' => ['list_amount_paise' => 1475000, 'discount_paise' => 295000] + $req['fee']] + $req, ['idem' => 'lead-6-fee-x']);
    same([422, 'validation_failed'], [$bad['status'], $bad['json']['error']['code'] ?? null], 'a discount needs its ref');
    check(isset($bad['json']['error']['details']['fee.discount_ref']), 'fee.discount_ref named');
    $off = engage('POST', '', ['fee' => ['list_amount_paise' => 1500000, 'discount_paise' => 295000, 'discount_ref' => 'SCHOLAR-25'] + $req['fee']] + $req, ['idem' => 'lead-6-fee-y']);
    same([422, true], [$off['status'], isset($off['json']['error']['details']['fee.list_amount'])], 'list − discount must be the fee as billed');
    $c = engage('POST', '', ['fee' => ['list_amount_paise' => 1475000, 'discount_paise' => 295000, 'discount_ref' => 'SCHOLAR-25'] + $req['fee']] + $req, ['idem' => 'lead-6-fee-1']);
    same([201, 1180000, ['list_amount_paise' => 1475000, 'discount_paise' => 295000, 'discount_ref' => 'SCHOLAR-25']],
        [$c['status'], $c['json']['data']['amount_paise'] ?? null, $c['json']['data']['fee']['discount'] ?? null], 'link: the student pays the discounted fee: ' . $c['body']);
    $link = linkRow($c['json']['data']['link_id']);
    $paid = gw('razorpay/pay', ['plink_id' => $link['provider_link_id']]);
    same(200, rzpHook(rzpPaidEvent($paid['link'], $paid['payment']), RZP_HOOK_SECRET, 'evt_DISC1')['status'], 'webhook');
    $p = paymentsOf((int) $link['id'])[0];
    same(['verified', 'issued'], [$p['verification_status'], $p['invoice_state']], 'verified and invoiced: ' . $p['invoice_error']);
    $inv  = $pdo->query("SELECT * FROM fin_invoices WHERE source_ref = 'payment:{$p['id']}'")->fetch();
    $line = $pdo->query("SELECT * FROM fin_invoice_lines WHERE invoice_id = {$inv['id']}")->fetch();
    same([1180000, 1475000, 295000, 'SCHOLAR-25', 1000000, 90000, 90000], [(int) $line['amount_paise'], (int) $line['list_amount_paise'], (int) $line['discount_paise'],
        $line['discount_ref'], (int) $line['taxable_paise'], (int) $line['cgst_paise'], (int) $line['sgst_paise']], 'invoice line: list, discount, ref; GST on ₹11,800');
    same(['academy_student', null], [$inv['buyer_kind'], $inv['cmp_id']], 'still a student invoice');
    $html = (string) $pdo->query("SELECT html FROM fin_doc_pdfs WHERE invoice_id = {$inv['id']}")->fetchColumn();
    check(str_contains($html, 'SCHOLAR-25') && str_contains($html, '14,750.00'), 'the printed invoice shows the list price and the discount ref');
});

test('events Finance must not act on: links made in the dashboard, unknown payments, refunds of foreign payments — logged as ignored', function () use ($pdo, &$L): void {
    $broken = ['event' => 'payment_link.paid', 'payload' => ['payment_link' => ['entity' => ['id' => linkRow($L['A'])['provider_link_id'], 'reference_id' => $L['A'],
        'status' => 'paid']], 'payment' => ['entity' => ['id' => 'pay_Broken1', 'status' => 'captured']]]];
    $r = rzpHook($broken, RZP_HOOK_SECRET, 'evt_X0');
    same([200, 'ignored'], [$r['status'], $r['json']['outcome'] ?? null], 'a signed event without a usable amount is acknowledged, not retried forever');
    check(str_starts_with((string) $pdo->query("SELECT detail FROM fin_gateway_webhook_events WHERE provider_event_id = 'evt_X0'")->fetchColumn(), 'Not applied:'), 'reason logged');
    $foreign = ['event' => 'payment_link.paid', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_Dashboard1', 'reference_id' => 'TS-1989', 'status' => 'paid',
        'amount_paid' => 500000]], 'payment' => ['entity' => ['id' => 'pay_Dashboard1', 'amount' => 500000, 'status' => 'captured']]]];
    same([200, 'ignored'], [($r = rzpHook($foreign, RZP_HOOK_SECRET, 'evt_X1'))['status'], $r['json']['outcome'] ?? null], 'a launch-day dashboard link');
    same([200, 'ignored'], [($r = rzpHook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_X', 'amount' => 1, 'status' => 'captured']]]],
        RZP_HOOK_SECRET, 'evt_X2'))['status'], $r['json']['outcome'] ?? null], 'payment.captured without our note');
    same([200, 'ignored'], [($r = rzpHook(['event' => 'refund.processed', 'payload' => ['refund' => ['entity' => ['id' => 'rfnd_X', 'payment_id' => 'pay_Dashboard1',
        'amount' => 100, 'status' => 'processed']]]], RZP_HOOK_SECRET, 'evt_X3'))['status'], $r['json']['outcome'] ?? null], 'refund of a payment Finance does not hold');
    same(0, (int) $pdo->query("SELECT count(*) FROM fin_gateway_payments WHERE provider_payment_id LIKE 'pay_Dashboard%'")->fetchColumn(), 'nothing recorded');
    same(4, (int) $pdo->query("SELECT count(*) FROM fin_gateway_webhook_events WHERE outcome = 'ignored' AND provider_event_id LIKE 'evt_X%'")->fetchColumn(), 'logged');
});

test('staff raise links: maker (edit) → someone else approves (four-eyes, also a DB constraint) → created at the provider; reject with a reason', function () use ($pdo, $T, &$L): void {
    $form = ['academy_ref' => 'EDGE-ADM-0100', 'stream' => 'edge', 'description' => 'Aicountly Edge — full fee', 'amount' => '11800.00',
        'payer' => ['name' => 'Anita Desai', 'mobile' => '+91 99887 76655', 'state_code' => '27', 'email' => 'anita@example.test'],
        'fee' => ['sac' => SAC_TRAINING, 'gst_rate' => '18', 'price_basis' => 'inclusive'], 'provider' => 'razorpay'];
    same(403, aapi('POST', 'v1/academy/payment-links', $T['viewer'], $form)['status'], 'viewer cannot raise a link');
    $pv = aapi('POST', 'v1/academy/payment-links/preview', $T['alice'], $form)['json']['data'];
    same([1180000, 1000000, 90000, 90000, 'intra_state'], [$pv['amount_paise'], $pv['fee']['taxable_paise'], $pv['fee']['cgst_paise'], $pv['fee']['sgst_paise'], $pv['supply_type']],
        'preview: Finance computes the split');
    $posts = count(gwCalls('POST /v1/payment_links'));
    $a = aapi('POST', 'v1/academy/payment-links', $T['alice'], $form);
    same([201, 'pending_approval', 'finance', null], [$a['status'], $a['json']['data']['status'], $a['json']['data']['source_app'], $a['json']['data']['url']], 'raised: awaiting approval');
    same($posts, count(gwCalls('POST /v1/payment_links')), 'nothing at the provider yet');
    $id = $a['json']['data']['id'];
    same(409, aapi('POST', 'v1/academy/payment-links', $T['bob'], $form)['status'], 'the same fee cannot be raised twice');
    $own = aapi('GET', "v1/academy/payment-links/{$id}", $T['alice'])['json']['data'];
    same([false, false], [$own['can']['approve'], $own['can']['reject']], 'the accountant cannot approve');
    same(403, aapi('POST', "v1/academy/payment-links/{$id}/approve", $T['alice'])['status'], 'gate approve');

    $form2 = ['academy_ref' => 'EDGE-ADM-0101', 'payer' => ['name' => 'Kiran Rao'] + $form['payer']] + $form;
    $b = aapi('POST', 'v1/academy/payment-links', $T['bob'], $form2)['json']['data'];
    $self = aapi('POST', "v1/academy/payment-links/{$b['id']}/approve", $T['bob']);
    same(403, $self['status'], 'four-eyes: the maker cannot approve');
    check(str_contains((string) $self['json']['message'], 'Four-eyes'), 'message');
    same('You raised this link: someone else must approve it.', aapi('GET', "v1/academy/payment-links/{$b['id']}", $T['bob'])['json']['data']['can']['blocked_reason'], 'shown on screen');
    refusedSql("UPDATE fin_payment_links SET approved_by = created_by, approved_at = now() WHERE id = {$b['id']}", 'fin_payment_links_four_eyes', 'four-eyes is a database constraint');
    $ok = aapi('POST', "v1/academy/payment-links/{$b['id']}/approve", $T['carol']);
    same([200, 'created', 'carol@aicountly.com'], [$ok['status'], $ok['json']['data']['status'], $ok['json']['data']['approved_by']], 'approved by someone else: live');
    check(str_starts_with((string) $ok['json']['data']['url'], 'https://rzp.test/i/'), 'URL to share with the student');
    same(409, aapi('POST', "v1/academy/payment-links/{$b['id']}/approve", $T['dave'])['status'], 'approved once');

    same(422, aapi('POST', "v1/academy/payment-links/{$id}/reject", $T['bob'], ['reason' => ' '])['status'], 'a reason is required');
    $r = aapi('POST', "v1/academy/payment-links/{$id}/reject", $T['bob'], ['reason' => 'Wrong instalment amount']);
    same([200, 'rejected', 'Wrong instalment amount'], [$r['status'], $r['json']['data']['status'], $r['json']['data']['reject_reason']], 'rejected');
    $list = aapi('GET', 'v1/academy/payment-links?stream=edge', $T['viewer'])['json']['data'];
    check($list['total'] >= 6 && $list['counts']['rejected'] === 1, 'list with counts');
    same(['payment_link.raised', 'payment_link.approved', 'payment_link.created'], array_column($pdo->query("SELECT action FROM audit_log WHERE entity_type = 'payment_link'
        AND entity_id = '{$b['id']}' ORDER BY id")->fetchAll(), 'action'), 'audit trail: raised, approved, created');
    $L['S'] = $b['link_id'];
    $L['S_id'] = $b['id'];
});

test('a staff link without an Engage lead code is invoiced but not reported to Engage', function () use ($pdo, &$L): void {
    $link = linkRow($L['S']);
    $paid = gw('razorpay/pay', ['plink_id' => $link['provider_link_id']]);
    same(200, rzpHook(rzpPaidEvent($paid['link'], $paid['payment']), RZP_HOOK_SECRET, 'evt_S1')['status'], 'webhook');
    $p = paymentsOf((int) $link['id'])[0];
    same(['verified', 'issued'], [$p['verification_status'], $p['invoice_state']], 'verified and invoiced');
    same(0, (int) $pdo->query("SELECT count(*) FROM fin_engage_payment_events WHERE link_id = {$link['id']}")->fetchColumn(), 'no lead code → no Engage event');
});

test('cancel: Engage cancels its open link at the provider; a paid link cannot be cancelled; staff cancel', function () use ($T, &$L): void {
    $c = engage('POST', '', feeRequest('EDGE-ADM-0005'), ['idem' => 'lead-5-fee-1'])['json']['data'];
    $x = engage('POST', $c['link_id'] . '/cancel', ['reason' => 'Student moved to the next batch']);
    same([200, 'cancelled'], [$x['status'], $x['json']['data']['status']], 'cancelled');
    $prov = linkRow($c['link_id'])['provider_link_id'];
    same(1, count(gwCalls('POST /v1/payment_links/' . $prov . '/cancel')), 'at Razorpay too');
    same([200, true], [($r = engage('POST', $c['link_id'] . '/cancel', []))['status'], $r['json']['data']['duplicate'] ?? null], 'again: no-op');
    same([409, 'already_paid'], [($r = engage('POST', $L['A'] . '/cancel', []))['status'], $r['json']['error']['code'] ?? null], 'a paid link');
    same(201, engage('POST', '', feeRequest('EDGE-ADM-0005'), ['idem' => 'lead-5-fee-2'])['status'], 'after cancelling, a new link for the same fee');
    $staffOpen = aapi('POST', 'v1/academy/payment-links', $T['alice'], ['academy_ref' => 'EDGE-ADM-0102', 'stream' => 'edge', 'description' => 'x fee', 'amount' => '500.00',
        'payer' => ['name' => 'A B', 'mobile' => '9876500000', 'state_code' => '27'], 'fee' => ['sac' => SAC_TRAINING, 'gst_rate' => '18', 'price_basis' => 'inclusive']])['json']['data'];
    same(403, aapi('POST', "v1/academy/payment-links/{$staffOpen['id']}/cancel", $T['alice'])['status'], 'accountant cannot cancel');
    same('cancelled', aapi('POST', "v1/academy/payment-links/{$staffOpen['id']}/cancel", $T['bob'], ['reason' => 'duplicate'])['json']['data']['status'], 'approver cancels (locally: never at the provider)');
    same(404, engage('GET', $L['S'])['status'], 'Engage does not see staff-raised links');
});

test('refund made in the dashboard: re-read, recorded, payment.refunded queued after payment.succeeded; staff raise the credit note (four-eyes, no cmp_id)', function () use ($pdo, $T, &$L): void {
    $link = linkRow($L['A']);
    $pay  = paymentsOf((int) $link['id'])[0];
    $ref  = gw('razorpay/refund', ['payment_id' => $pay['provider_payment_id'], 'amount' => 1180000]);
    $hook = ['event' => 'refund.processed', 'payload' => ['refund' => ['entity' => $ref], 'payment' => ['entity' => ['id' => $pay['provider_payment_id'], 'amount' => 1180000,
        'status' => 'refunded']]]];
    $r = rzpHook($hook, RZP_HOOK_SECRET, 'evt_R1');
    same([200, 'processed'], [$r['status'], $r['json']['outcome'] ?? null], 'refund webhook: ' . $r['body']);
    same(['verified', 1180000], array_values($pdo->query("SELECT verification_status, amount_paise FROM fin_gateway_refunds WHERE provider_refund_id = '{$ref['id']}'")->fetch()),
        'refund verified by GET /v1/refunds/{id}');
    same(1180000, (int) paymentsOf((int) $link['id'])[0]['refunded_paise'], 'payment refunded in full');
    $ev = $pdo->query("SELECT event_type, payload FROM fin_engage_payment_events WHERE link_id = {$link['id']} ORDER BY id")->fetchAll();
    same(['payment.succeeded', 'payment.refunded'], array_column($ev, 'event_type'), 'refund event queued after the payment event');
    $rb = json_decode($ev[1]['payload'], true);
    same([$pay['gateway_payment_ref'], '11800.00', true], [$rb['payment_ref'], $rb['refund']['amount'], $rb['refund']['fully_refunded']], 'refund event body');
    same(200, rzpHook($hook, RZP_HOOK_SECRET, 'evt_R1')['status'], 'replayed refund');
    same(1, (int) $pdo->query("SELECT count(*) FROM fin_gateway_refunds")->fetchColumn(), 'recorded once');

    $inv = (int) $pdo->query("SELECT id FROM fin_invoices WHERE source_ref = 'payment:{$pay['id']}'")->fetchColumn();
    $cn  = aapi('POST', "v1/invoices/{$inv}/credit-notes", $T['alice'], ['kind' => 'full', 'reason' => 'Fee refunded (Razorpay ' . $ref['id'] . ')']);
    same(201, $cn['status'], 'credit note requested on the student\'s invoice: ' . $cn['body']);
    $ok = aapi('POST', "v1/credit-notes/{$cn['json']['data']['id']}/approve", $T['bob']);
    same([200, 'issued', null, 'academy_student'], [$ok['status'], $ok['json']['data']['status'], $ok['json']['data']['cmp_id'], $ok['json']['data']['buyer_kind']],
        'issued by someone else; no company');
    same('credited', $pdo->query("SELECT status FROM fin_invoices WHERE id = {$inv}")->fetchColumn(), 'invoice fully credited');
});

test('a second payment on a paid link is recorded and verified but never invoiced or reported (refund it); health flags it', function () use ($pdo, $aapi, &$L): void {
    $link = linkRow($L['D']);
    $paid = gw('razorpay/pay', ['plink_id' => $link['provider_link_id']]);
    same(200, rzpHook(rzpPaidEvent($paid['link'], $paid['payment']), RZP_HOOK_SECRET, 'evt_DUP')['status'], 'webhook');
    $pays = paymentsOf((int) $link['id']);
    same(2, count($pays), 'two payments');
    same(['verified', 'blocked_data'], [$pays[1]['verification_status'], $pays[1]['invoice_state']], 'verified, not invoiced');
    check(str_starts_with((string) $pays[1]['invoice_error'], 'Duplicate payment'), 'explained: ' . $pays[1]['invoice_error']);
    same([1, 1], [(int) $pdo->query("SELECT count(*) FROM fin_invoices WHERE source_ref IN ('payment:{$pays[0]['id']}', 'payment:{$pays[1]['id']}')")->fetchColumn(),
        (int) $pdo->query("SELECT count(*) FROM fin_engage_payment_events WHERE link_id = {$link['id']}")->fetchColumn()], 'still one invoice and one Engage event');
    same(true, http('GET', $aapi . '/api/health')['json']['data']['academy_fees']['checks']['invoice_blocked'], 'health alert');
});

test('records are immutable: link snapshot, provider payment, Academy invoice; nothing is deleted', function () use ($pdo, &$L): void {
    refusedSql("UPDATE fin_payment_links SET amount_paise = 100, fee_taxable_paise = 100, fee_cgst_paise = 0, fee_sgst_paise = 0 WHERE link_id = '{$L['A']}'", 'never change', 'link amount');
    refusedSql("UPDATE fin_payment_links SET status = 'created' WHERE link_id = '{$L['A']}'", 'cannot become', 'a paid link stays paid');
    refusedSql("DELETE FROM fin_payment_links WHERE link_id = '{$L['A']}'", 'never deleted', 'deleting a link');
    refusedSql("UPDATE fin_gateway_payments SET amount_paise = 1 WHERE id = (SELECT min(id) FROM fin_gateway_payments)", 'never changes', 'payment amount');
    refusedSql("UPDATE fin_gateway_payments SET verification_status = 'pending' WHERE verification_status = 'verified'", 'stays verified', 'un-verifying');
    refusedSql("UPDATE fin_gateway_payments SET gateway_payment_ref = 'pay_other' WHERE gateway_payment_ref IS NOT NULL", 'never changes once set', 'changing the payment ref');
    refusedSql("UPDATE fin_invoices SET cmp_id = 5 WHERE buyer_kind = 'academy_student'", 'immutable', 'an issued Academy invoice');
    refusedSql("INSERT INTO fin_invoices (document_id, buyer_kind, cmp_id, series_id, fy, seq_no, doc_no, doc_date, source, source_ref, payment_id, seller_profile_id, seller,
            seller_gstin, seller_state_code, buyer_legal_name, buyer_state_code, buyer_address, place_of_supply, supply_type, taxable_paise, cgst_paise, sgst_paise, igst_paise,
            total_paise, amount_in_words, payment_method, payment_reference, paid_on, issued_by)
        SELECT 'fin_inv_0123456789ABCDEFGHJKMNPQRT', 'company', NULL, series_id, fy, 999998, 'ZZ/999998', doc_date, 'payment', 'payment:999999998', 999999998, seller_profile_id, seller,
            seller_gstin, seller_state_code, buyer_legal_name, buyer_state_code, buyer_address, place_of_supply, supply_type, taxable_paise, cgst_paise, sgst_paise, igst_paise,
            total_paise, amount_in_words, payment_method, payment_reference, paid_on, issued_by FROM fin_invoices LIMIT 1", 'fin_invoices_buyer_kind_cmp', 'a company invoice still needs its cmp_id');
});

test('staff screens: payments, webhook log (scrubbed), Engage events, overview counts — every role reads; retry-invoice needs approve', function () use ($T, $pdo): void {
    $p = aapi('GET', 'v1/academy/payments', $T['viewer']);
    same(200, $p['status'], 'payments list');
    check($p['json']['data']['total'] >= 6, 'payments listed');
    $dup = array_values(array_filter($p['json']['data']['rows'], static fn (array $r) => str_starts_with((string) $r['invoice_error'], 'Duplicate')))[0];
    same(false, $dup['can']['retry_invoice'], 'a duplicate cannot be invoiced from the screen');
    same(403, aapi('POST', "v1/academy/payments/{$dup['id']}/retry-invoice", $T['alice'])['status'], 'retry needs approve');
    $w = aapi('GET', 'v1/academy/webhook-events?provider=razorpay', $T['viewer'])['json']['data'];
    check($w['total'] >= 8, 'webhook log');
    check(!str_contains(json_encode($w), 'ravi.student@example.test') && !str_contains(json_encode($w), 'student@okbank'), 'no personal data in the log');
    same(1, aapi('GET', 'v1/academy/webhook-events?outcome=rejected', $T['viewer'])['json']['data']['total'] >= 1 ? 1 : 0, 'rejected deliveries listed');
    $o = aapi('GET', 'v1/academy/overview', $T['alice'])['json']['data'];
    same([true, false, false], [$o['can']['raise'], $o['can']['approve'], $o['can']['admin']], 'accountant: raise only');
    check($o['counts']['paid'] >= 5 && $o['readiness']['gateway'] && $o['readiness']['engage_inbound'] && $o['readiness']['engage_events'], 'counts and readiness');
    $d = aapi('GET', 'v1/academy/payment-links/' . (int) $pdo->query("SELECT id FROM fin_payment_links WHERE status = 'paid' ORDER BY id LIMIT 1")->fetchColumn(), $T['viewer'])['json']['data'];
    check(count($d['webhooks']) >= 1 && count($d['engage_events']) >= 1 && $d['payments'][0]['invoice'] !== null, 'detail: payments with invoice, webhook log, Engage events');
});

test('health: academy_fees checks (booleans only); payouts and Manage untouched; no secret in any answer', function () use ($aapi, $pdo): void {
    global $seen, $secrets;
    $h = http('GET', $aapi . '/api/health');
    $a = $h['json']['data']['academy_fees'];
    same(['schema' => true, 'gateway_connection' => true, 'webhook_secret' => true, 'live_mode' => false, 'engage_inbound' => true, 'engage_events' => true,
        'verification_pending' => false, 'invoice_blocked' => true, 'engage_events_dead' => false], $a['checks'], 'checks (the duplicate payment is the open alert)');
    same('incomplete', $a['status'], 'incomplete while an alert is open');
    same(0, (int) $pdo->query("SELECT count(*) FROM fin_invoices WHERE buyer_kind = 'academy_student' AND cmp_id IS NOT NULL")->fetchColumn(), 'no Academy invoice carries a company id');
    $m = s2s($GLOBALS['iapi'], 'documents', ['cmp_id' => 101, 'limit' => 100]);
    check(!str_contains($m['body'], 'Ravi Kumar') && !str_contains($m['body'], 'Anita'), 'Manage never lists Academy invoices');
    $seen[] = $h['body'];
    $seen[] = (string) file_get_contents($GLOBALS['cacheDir'] . '/academy-error.log');
    foreach ($secrets as $s) {
        foreach ($seen as $body) {
            if (str_contains((string) $body, $s)) {
                check(false, 'a secret appeared in an answer or log line: ' . substr($s, 0, 6) . '…');
            }
        }
    }
    check(count($seen) > 50, 'checked ' . count($seen) . ' answers for secrets');
});
