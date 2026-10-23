<?php
declare(strict_types=1);

/**
 * Invoicing rules without I/O — included by tests/run.php (uses its check/same/test helpers and throwsApi()).
 * GSTIN, the tax engine, amount in words, numbering format, AIC-S2S v1 signing and verification, cursors, the
 * printable template and the route table's S2S rules.
 */

require_once __DIR__ . '/../app/Filters/S2sFilter.php';

use App\Security\AicS2s;
use App\Services\Invoicing\CreditNoteService;
use App\Services\Invoicing\DocumentHtml;
use App\Services\Invoicing\DocumentNumbering;
use App\Services\Invoicing\DocumentQueries;
use App\Services\Invoicing\GstCalculator;
use App\Services\Invoicing\InvoiceInput;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\SellerProfileService;
use App\Support\AmountInWords;
use App\Support\Gstin;
use App\Support\Ulid;

echo "Unit — invoicing\n";

/** A syntactically valid GSTIN with the right check character for state + PAN (+ entity number). */
function testGstin(string $state, string $pan, string $entity = '1'): string
{
    $first14 = $state . $pan . $entity . 'Z';

    return $first14 . Gstin::checkCharacter($first14);
}

test('GSTIN: format, state code and mod-36 check character', function (): void {
    foreach (['27AAPFU0939F1ZV', '29AAGCB7383J1Z4', '33AAACH7409R1Z8'] as $g) {
        same(true, Gstin::isValid($g), "published GSTIN {$g} validates");
    }
    same(false, Gstin::isValid('27AAPFU0939F1ZX'), 'wrong check character');
    check(str_contains((string) Gstin::problem('27AAPFU0939F1ZX'), 'check character'), 'problem names the check character');
    same(false, Gstin::isValid('99AAPFU0939F1ZV'), 'unknown state code 99');
    same(false, Gstin::isValid('27AAPFU0939F1Z'), '14 characters');
    same(false, Gstin::isValid('27AAPFU0939F0ZV'), 'entity number 0');
    same(false, Gstin::isValid('27aapfu0939f1zv'), 'lower case is not normalised by isValid');
    same('27AAPFU0939F1ZV', Gstin::normalise(' 27aapfu 0939f1zv '), 'normalise upper-cases and strips spaces');
    same(['27', 'AAPFU0939F'], [Gstin::stateOf('27AAPFU0939F1ZV'), Gstin::panOf('27AAPFU0939F1ZV')], 'state and PAN inside');
    same('29-Karnataka', Gstin::stateLabel('29'), 'GSTR-1 place-of-supply label');
    $g = testGstin('07', 'AAACZ9999Q');
    same(true, Gstin::isValid($g), "generated test GSTIN {$g}");
});

test('tax engine: inclusive / exclusive, CGST takes the odd paisa, IGST inter-state', function (): void {
    same(['taxable' => 1000000, 'tax' => 180000, 'cgst' => 90000, 'sgst' => 90000, 'igst' => 0, 'total' => 1180000],
        GstCalculator::line(1180000, 1800, 'inclusive', true), '₹11,800 incl. 18% intra: 10,000 + 900 + 900');
    same(['taxable' => 1000000, 'tax' => 180000, 'cgst' => 0, 'sgst' => 0, 'igst' => 180000, 'total' => 1180000],
        GstCalculator::line(1180000, 1800, 'inclusive', false), 'inter-state: IGST');
    same(['taxable' => 1000000, 'tax' => 180000, 'cgst' => 90000, 'sgst' => 90000, 'igst' => 0, 'total' => 1180000],
        GstCalculator::line(1000000, 1800, 'exclusive', true), 'exclusive: GST added on top');

    // Odd paise: ₹1.01 incl. 18% → tax round(101 × 18/118) = round(15.41) = 15 → SGST 7 (floor), CGST 8.
    same(['taxable' => 86, 'tax' => 15, 'cgst' => 8, 'sgst' => 7, 'igst' => 0, 'total' => 101], GstCalculator::line(101, 1800, 'inclusive', true), 'odd paisa to CGST');
    // ₹0.01 lines.
    same(['taxable' => 1, 'tax' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total' => 1], GstCalculator::line(1, 1800, 'inclusive', true), '₹0.01 inclusive: no tax');
    same(['taxable' => 1, 'tax' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total' => 1], GstCalculator::line(1, 1800, 'exclusive', false), '₹0.01 exclusive: 0.18 paise rounds to 0');
    same(['taxable' => 3, 'tax' => 1, 'cgst' => 1, 'sgst' => 0, 'igst' => 0, 'total' => 4], GstCalculator::line(3, 1800, 'exclusive', true), '₹0.03 exclusive: 0.54 → 1 paisa, all CGST');
    // Half-up at an exact half: 14 paise incl. 12 % → 14 × 12/112 = 1.5 → 2; exclusive 25 paise × 18 % = 4.5 → 5.
    same(2, GstCalculator::line(14, 1200, 'inclusive', false)['tax'], 'inclusive tie rounds half up');
    same(5, GstCalculator::line(25, 1800, 'exclusive', false)['tax'], 'exclusive tie rounds half up');
    // The rate is the line's own: 5 %, 0 %, 0.25 %.
    same(50000, GstCalculator::line(1050000, 500, 'inclusive', false)['tax'], '5% inclusive');
    same(['taxable' => 500000, 'tax' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'total' => 500000], GstCalculator::line(500000, 0, 'exclusive', true), '0%');
    same(25, GstCalculator::line(10000, 25, 'exclusive', false)['tax'], '0.25% of ₹100');
    // No overflow at the largest allowed amount.
    $big = GstCalculator::line(1_000_000_000_000, 1800, 'inclusive', true);
    same(1_000_000_000_000, $big['taxable'] + $big['cgst'] + $big['sgst'], '₹1,000 crore: parts add up');

    // Multi-line: each line rounded on its own, totals are sums (three ₹1.01 lines → 45 paise, not round(303 × 18/118) = 46).
    $lines = [GstCalculator::line(101, 1800, 'inclusive', true), GstCalculator::line(101, 1800, 'inclusive', true), GstCalculator::line(101, 1800, 'inclusive', true)];
    same(['taxable' => 258, 'cgst' => 24, 'sgst' => 21, 'igst' => 0, 'total' => 303], GstCalculator::totals($lines), 'line-wise rounding, summed');

    $bad = false;
    try {
        GstCalculator::line(100, 1800, 'gross', true);
    } catch (\InvalidArgumentException) {
        $bad = true;
    }
    check($bad, 'unknown price basis refused');
});

test('place of supply: buyer GSTIN state, else billing state', function (): void {
    same('29', GstCalculator::placeOfSupply('29AAGCB7383J1Z4', '27'), 'GSTIN wins');
    same('07', GstCalculator::placeOfSupply(null, '07'), 'unregistered: billing state');
    same('intra_state', GstCalculator::supplyType('27', '27'), 'same state');
    same('inter_state', GstCalculator::supplyType('27', '29'), 'different state');
});

test('invoice input: buyer + lines validated together, every problem reported', function (): void {
    $errors = [];
    $b = InvoiceInput::buyer(['legal_name' => ' Acme  Pvt Ltd ', 'gstin' => '29aagcb7383j1z4', 'state_code' => '29', 'address' => "12 MG Road\nBengaluru",
        'pincode' => '560001', 'email' => 'Billing@Acme.test'], $errors);
    same([], $errors, 'valid buyer');
    same(['Acme Pvt Ltd', '29AAGCB7383J1Z4', 'billing@acme.test'], [$b['legal_name'], $b['gstin'], $b['email']], 'normalised');
    same("12 MG Road\nBengaluru", $b['address'], 'address keeps its lines');

    $errors = [];
    InvoiceInput::buyer(['legal_name' => '', 'gstin' => '29AAGCB7383J1Z4', 'state_code' => '27', 'address' => '', 'pincode' => '0123', 'country' => 'US', 'email' => 'x'], $errors);
    same(['buyer.legal_name', 'buyer.state_code', 'buyer.address', 'buyer.pincode', 'buyer.country', 'buyer.email'], array_keys($errors), 'each buyer problem');
    check(str_contains($errors['buyer.state_code'], '29-Karnataka'), 'state must follow the GSTIN');

    $errors = [];
    $l = InvoiceInput::lines([['item_code' => 'books.business', 'item_kind' => 'subscription', 'description' => 'Smart Books', 'sac' => '997331',
        'gst_rate' => 18, 'price_basis' => 'Inclusive', 'period_start' => '2026-10-18', 'period_end' => '2027-10-17', 'amount' => '11800']], $errors);
    same([], $errors, 'valid line');
    same(['18.00', 1800, 'inclusive', 1180000, '1'], [$l[0]['gst_rate'], $l[0]['rate_bp'], $l[0]['price_basis'], $l[0]['amount_paise'], $l[0]['quantity']], 'normalised line');

    $errors = [];
    InvoiceInput::lines([['item_code' => '', 'item_kind' => 'x', 'description' => 'y', 'sac' => '123456', 'gst_rate' => '100', 'price_basis' => 'gross',
        'period_start' => '2026-10-18', 'period_end' => '2026-10-01', 'quantity' => '0', 'amount' => '-5']], $errors);
    same(['lines[0].item_code', 'lines[0].sac', 'lines[0].gst_rate', 'lines[0].price_basis', 'lines[0].period_end', 'lines[0].quantity', 'lines[0].amount'],
        array_keys($errors), 'each line problem');
    $errors = [];
    InvoiceInput::lines([], $errors);
    same(['lines'], array_keys($errors), 'no lines');
    $e = throwsApi(422, static fn () => InvoiceInput::throwIf(['x' => 'y']), 'throwIf');
    same('validation_failed', $e->errorCode(), 'code validation_failed');
});

test('amount in words: Indian system (lakh / crore), rupees and paise', function (): void {
    same('Rupees Eleven Thousand Eight Hundred Only', AmountInWords::inr(1180000), 'contract example');
    same('Rupees One Lakh Twelve Thousand Six Hundred Forty and Twenty Paise Only', AmountInWords::inr(11264020), 'same text as Smart Books');
    same('Rupees One Crore Only', AmountInWords::inr(1_000_000_000), 'one crore');
    same('Rupees Ninety Nine Lakh Ninety Nine Thousand Nine Hundred Ninety Nine and Ninety Nine Paise Only', AmountInWords::inr(999_999_999), 'just under a crore');
    same('Rupees One Thousand Two Hundred Thirty Four Crore Fifty Six Lakh Seventy Eight Thousand Nine Hundred One Only', AmountInWords::inr(1_234_567_890_100), 'crores of crores');
    same('Rupees Zero Only', AmountInWords::inr(0), 'zero');
});

test('numbering: format, width, 16-character limit, series validation', function (): void {
    same('AIC/26-27/000123', DocumentNumbering::format('AIC/26-27/', 123, 6), 'prefix + zero padded');
    same(16, strlen((string) DocumentNumbering::format('AIC/26-27/', 999999, 6)), 'exactly 16');
    same(null, DocumentNumbering::format('AIC/26-27/', 1000000, 6), 'no 7th digit');
    same(null, DocumentNumbering::format('AIC/26-27/X', 1, 6), '17 characters');
    same(['prefix' => 'CN/26-27/', 'width' => 6, 'next_no' => 41], DocumentNumbering::validateSeries(['prefix' => 'cn/26-27/', 'width' => '6', 'next_no' => '41']), 'normalised series');
    $e = throwsApi(422, static fn () => DocumentNumbering::validateSeries(['prefix' => 'AIC2026', 'width' => 6]), 'prefix ending in a digit');
    check(isset($e->errors()['prefix']), 'prefix error');
    $e = throwsApi(422, static fn () => DocumentNumbering::validateSeries(['prefix' => 'AIC-CN/26-27/', 'width' => 6]), '19 characters');
    check(str_contains((string) $e->errors()['width'], '16'), 'width names the limit');
    throwsApi(422, static fn () => DocumentNumbering::validateSeries(['prefix' => 'A B/', 'width' => 4]), 'space refused');
    throwsApi(422, static fn () => DocumentNumbering::validateSeries(['prefix' => 'INV/', 'width' => 2, 'next_no' => 100]), 'next number wider than the width');
});

test('seller profile validation: GSTIN, PAN inside it, state from it', function (): void {
    $gstin = testGstin('27', 'AAJCA4321K');
    $v = SellerProfileService::validate(['legal_name' => 'Aicountly Interactive Services Private Limited', 'gstin' => strtolower($gstin), 'address_line1' => '1 Test Street',
        'city' => 'Mumbai', 'pincode' => '400001', 'signatory_name' => 'A Director', 'effective_from' => '2026-10-01']);
    same([$gstin, 'AAJCA4321K', '27'], [$v['gstin'], $v['pan'], $v['state_code']], 'PAN and state derived from the GSTIN');
    $e = throwsApi(422, static fn () => SellerProfileService::validate(['legal_name' => '', 'gstin' => $gstin, 'pan' => 'ZZZZZ9999Z', 'state_code' => '29',
        'address_line1' => '', 'city' => '', 'pincode' => '1', 'signatory_name' => '', 'email' => 'nope']), 'invalid profile');
    same(['legal_name', 'address_line1', 'city', 'signatory_name', 'pan', 'state_code', 'pincode', 'email'], array_keys($e->errors()), 'every problem');
});

test('credit note helpers: deadline (s.34) and partial split within the remaining heads', function (): void {
    same('2027-11-30', CreditNoteService::deadline('2026-27'), '30 Nov after the FY');
    $rem = ['taxable' => 1000000, 'cgst' => 90000, 'sgst' => 90000, 'igst' => 0, 'total' => 1180000];
    same(['taxable' => 50000, 'cgst' => 4500, 'sgst' => 4500, 'igst' => 0, 'total' => 59000], CreditNoteService::splitGross(59000, 1800, true, $rem), '₹590 incl. 18%');
    // What is left after odd-paisa credits: CGST has 1 paisa less than SGST — the split moves it to SGST.
    $odd = ['taxable' => 86, 'cgst' => 7, 'sgst' => 8, 'igst' => 0, 'total' => 101];
    $s = CreditNoteService::splitGross(100, 1800, true, $odd);
    check($s['cgst'] <= 7 && $s['sgst'] <= 8 && $s['taxable'] <= 86 && $s['taxable'] + $s['cgst'] + $s['sgst'] === 100, 'clamped: ' . json_encode($s));
});

test('AIC-S2S v1: canonical form and signature (docs/INVOICING.md worked example)', function (): void {
    same('a=0&a=1&b=2&c=x%20y&empty=', AicS2s::canonicalQuery('c=x+y&b=2&a=1&a=0&empty'), 'sorted by key then value, RFC 3986, "+" is a space');
    same('cursor=eyJk%3D&fy=2026-27', AicS2s::canonicalQuery('fy=2026-27&cursor=eyJk%3D'), 'percent-encoding normalised');
    same('', AicS2s::canonicalQuery(''), 'no query');
    $canonical = AicS2s::canonical('get', '/v1/inbound/manage/documents', 'limit=25&cmp_id=42', '1760781600', '3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37', '');
    same("GET\n/v1/inbound/manage/documents\ncmp_id=42&limit=25\n1760781600\n3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37\n"
        . 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $canonical, 'canonical string');
    $secret = 'manage-to-finance-example-secret-0123456789';
    same('v1=' . hash_hmac('sha256', $canonical, $secret), AicS2s::signature($secret, $canonical), 'v1=hex HMAC-SHA256');
    same('v1=2c93e407965eea0f40532c26a36b978912a090d845d7a662983489139110684b', AicS2s::signature($secret, $canonical),
        'the worked example in docs/INVOICING.md (also reproducible with openssl dgst -sha256 -hmac)');
    same('FINANCE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10', AicS2s::secretEnvName('manage', 'manage-read-2026-10'), 'secret env name');
    same('FINANCE_INBOUND_MANAGE_SCOPE_MANAGE_READ_2026_10', AicS2s::scopeEnvName('manage', 'manage-read-2026-10'), 'scope env name');
});

test('AIC-S2S v1: verify — every refusal code, in order', function (): void {
    $secret = str_repeat('k', 40);
    $now    = 1_760_781_600;
    $route  = ['scope' => 'read', 'clients' => ['manage']];
    $secretFor = static fn (string $c, string $k): ?string => $c === 'manage' && in_array($k, ['manage-read', 'manage-issue'], true) ? $secret : null;
    $scopeFor  = static fn (string $c, string $k): string => $k === 'manage-issue' ? 'issue' : 'read';
    $req = static function (array $override = [], string $method = 'GET', string $path = '/v1/inbound/manage/documents', string $query = 'cmp_id=42', string $body = '',
        string $env = 'production', ?int $ts = null, string $key = 'manage-read') use ($secret, $now): array {
        $h = AicS2s::sign($secret, 'manage', $key, $env, $method, $path, $query, $body, $ts ?? $now, 'b6d3c7a2-1f0e-4c59-8a3d-2e9f6b1c0d47', 'uuid-of-owner');
        $h = array_change_key_case($h, CASE_LOWER);
        foreach ($override as $k => $v) {
            if ($v === null) {
                unset($h[$k]);
            } else {
                $h[$k] = $v;
            }
        }

        return $h;
    };
    $v = static fn (array $h, string $query = 'cmp_id=42', string $body = '', string $method = 'GET', array $r = []) =>
        AicS2s::verify($h, $method, '/v1/inbound/manage/documents', $query, $body, $r + $route, $secretFor, $scopeFor, 'production', $now);

    $ok = $v($req());
    same([true, 'manage', 'manage-read', 'read', 'uuid-of-owner'], [$ok['ok'], $ok['client'], $ok['key_id'], $ok['scope'], $ok['actor']], 'valid request');
    same(true, $v($req([]), 'cmp_id=42')['ok'], 'same query in another order is the same canonical form');
    same(true, $v($req([], 'GET', '/v1/inbound/manage/documents', 'limit=5&cmp_id=42'), 'cmp_id=42&limit=5')['ok'], 'query order does not matter');
    same([400, 'environment_required'], array_values(array_intersect_key($v($req(['x-aic-environment' => null])), ['status' => 1, 'code' => 1])), 'no environment');
    same('signature_missing', $v($req(['x-aic-signature' => null]))['code'], 'no signature');
    same('signature_missing', $v($req(['x-aic-nonce' => null]))['code'], 'no nonce');
    same([403, 'environment_mismatch'], array_values(array_intersect_key($v($req([], 'GET', '/v1/inbound/manage/documents', 'cmp_id=42', '', 'sandbox')), ['status' => 1, 'code' => 1])), 'sandbox key at production');
    same([401, 'unknown_key'], array_values(array_intersect_key($v($req(['x-aic-key-id' => 'manage-old'])), ['status' => 1, 'code' => 1])), 'unknown key id');
    same('unknown_key', $v($req(['x-aic-client' => 'engage']))['code'], 'client not allowed on this route');
    same('timestamp_stale', $v($req([], 'GET', '/v1/inbound/manage/documents', 'cmp_id=42', '', 'production', $now - 301))['code'], '301 s old');
    same('timestamp_stale', $v($req([], 'GET', '/v1/inbound/manage/documents', 'cmp_id=42', '', 'production', $now + 301))['code'], '301 s ahead');
    same(true, $v($req([], 'GET', '/v1/inbound/manage/documents', 'cmp_id=42', '', 'production', $now - 300))['ok'], 'exactly 300 s is accepted');
    same('signature_invalid', $v($req(['x-aic-signature' => 'v1=' . str_repeat('0', 64)]))['code'], 'wrong signature');
    same('signature_invalid', $v($req(), 'cmp_id=43')['code'], 'query tampered (another company)');
    same('signature_invalid', $v($req(), 'cmp_id=42', '{"x":1}')['code'], 'body added');
    same('signature_invalid', $v($req(), 'cmp_id=42', '', 'POST')['code'], 'method changed');
    same('signature_invalid', $v($req(['x-aic-timestamp' => (string) ($now - 1)]))['code'], 'timestamp changed after signing');
    $issue = $req([], 'GET', '/v1/inbound/manage/documents', 'cmp_id=42', '', 'production', null, 'manage-issue');
    same([403, 'route_not_allowed_for_key'], array_values(array_intersect_key($v($issue), ['status' => 1, 'code' => 1])), 'issue key on a read route');
    same(true, $v($issue, 'cmp_id=42', '', 'GET', ['scope' => 'issue'])['ok'], 'issue key on an issue route');
    same('route_not_allowed_for_key', $v($req(), 'cmp_id=42', '', 'GET', ['scope' => 'issue'])['code'], 'read key on an issue route');
});

test('cursor: base64url JSON {d, id}; anything else is 400 bad_request', function (): void {
    $c = DocumentQueries::encodeCursor('2026-10-18', 123);
    same(['d' => '2026-10-18', 'id' => 123], DocumentQueries::decodeCursor($c), 'round trip');
    same('{"d":"2026-10-18","id":123}', base64_decode(strtr($c, '-_', '+/')), 'contract shape');
    check(!str_contains($c, '=') && !str_contains($c, '+') && !str_contains($c, '/'), 'url-safe, unpadded');
    foreach (['', 'not-base64!', rtrim(base64_encode('{"d":"2026-02-30","id":1}'), '='), rtrim(base64_encode('{"d":"2026-10-18","id":"1"}'), '=')] as $bad) {
        $e = throwsApi(400, static fn () => DocumentQueries::decodeCursor($bad), 'bad cursor ' . $bad);
        same('bad_request', $e->errorCode(), 'code');
    }
});

test('printable document: required blocks, Indian formatting, everything escaped', function (): void {
    $doc = [
        'doc_type' => 'tax_invoice', 'doc_no' => 'AIC/26-27/000007', 'doc_date' => '2026-10-18', 'supply_type' => 'intra_state', 'reverse_charge' => false,
        'place_of_supply' => ['code' => '27', 'name' => 'Maharashtra'],
        'seller' => ['legal_name' => 'Aicountly <b>Interactive</b>', 'trade_name' => null, 'gstin' => '27AAPFU0939F1ZV', 'pan' => 'AAPFU0939F', 'address_line1' => '1 Street',
            'address_line2' => null, 'city' => 'Mumbai', 'pincode' => '400001', 'state_code' => '27', 'state_name' => 'Maharashtra', 'email' => 'billing@aicountly.test',
            'phone' => null, 'signatory_name' => 'R Gupta', 'signatory_designation' => 'Director'],
        'buyer' => ['legal_name' => '<script>alert(1)</script> Ltd', 'gstin' => null, 'state_code' => '27', 'state_name' => 'Maharashtra', 'address' => "Line 1\nLine 2",
            'pincode' => '411001', 'country' => 'IN', 'email' => null],
        'lines' => [['line_no' => 1, 'item_code' => 'books.business', 'description' => 'Smart Books — Business', 'sac' => '997331', 'quantity' => '1',
            'period_start' => '2026-10-18', 'period_end' => '2027-10-17', 'taxable_paise' => 1000000, 'cgst_rate' => '9.00', 'cgst_paise' => 90000,
            'sgst_rate' => '9.00', 'sgst_paise' => 90000, 'igst_rate' => '18.00', 'igst_paise' => 0, 'total_paise' => 1180000]],
        'totals' => ['taxable_paise' => 1000000, 'cgst_paise' => 90000, 'sgst_paise' => 90000, 'igst_paise' => 0, 'total_paise' => 1234567890],
        'amount_in_words' => 'Rupees Eleven Thousand Eight Hundred Only',
        'payment' => ['method' => 'neft', 'method_label' => 'NEFT', 'gateway' => null, 'reference' => 'HDFCN52026101800001', 'paid_on' => '2026-10-17'],
        'original_invoice' => null,
    ];
    $html = DocumentHtml::render($doc);
    foreach (['Tax Invoice', 'AIC/26-27/000007', '18/10/2026', '27 - Maharashtra', 'SAC', '997331', 'CGST', 'SGST', '@ 9%', '₹10,000.00',
              '₹1,23,45,678.90', 'Rupees Eleven Thousand Eight Hundred Only', 'PAID', 'HDFCN52026101800001', '17/10/2026', 'Authorised signatory: R Gupta, Director',
              'This is a computer-generated invoice.', 'GSTIN: <strong>27AAPFU0939F1ZV</strong>', 'Unregistered (no GSTIN)', 'Period: 18/10/2026 to 17/10/2027'] as $needle) {
        check(str_contains($html, $needle), "contains {$needle}");
    }
    check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<b>Interactive'), 'escaped');
    check(!preg_match('#(src|href)\s*=\s*["\']?https?:#i', $html), 'no external resources');
    $cn = DocumentHtml::render(['doc_type' => 'credit_note', 'supply_type' => 'inter_state', 'payment' => null, 'reason' => 'Refund of unused period',
        'original_invoice' => ['doc_no' => 'AIC/26-27/000007', 'doc_date' => '2026-10-18'], 'lines' => [$doc['lines'][0] + []]] + $doc);
    foreach (['Credit Note', 'Against invoice', 'AIC/26-27/000007 dated 18/10/2026', 'IGST', 'Reason:', 'This is a computer-generated credit note.'] as $needle) {
        check(str_contains($cn, $needle), "credit note contains {$needle}");
    }
    check(!str_contains($cn, 'PAID'), 'no Paid block on a credit note');
    same('AIC-26-27-000123.pdf', InvoiceService::pdfFilename('AIC/26-27/000123'), 'download file name (contract §1.4)');
    same(['₹0.01', '₹12,34,567.89', '-₹5.00'], [DocumentHtml::inr(1), DocumentHtml::inr(123456789), DocumentHtml::inr(-500)], 'Indian grouping');
    check((bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', Ulid::generate()), 'ULID alphabet');
});

test('discounted lines: list − discount is billed and taxed; validation; every problem reported', function (): void {
    $base = ['item_code' => 'books-pro', 'item_kind' => 'subscription', 'description' => 'Books Pro', 'sac' => '997331', 'gst_rate' => '18'];
    // Inclusive ₹11,800 with ₹2,360 off (20 %): billed ₹9,440 → taxable ₹8,000 + GST ₹1,440 (GST on the price after discount).
    $errors = [];
    $l = InvoiceInput::lines([$base + ['price_basis' => 'inclusive', 'list_amount' => '11800.00', 'discount' => '2360.00', 'discount_ref' => 'DIWALI26']], $errors);
    same([], $errors, 'valid discounted line');
    same([944000, 1180000, 236000, 'DIWALI26'], [$l[0]['amount_paise'], $l[0]['list_amount_paise'], $l[0]['discount_paise'], $l[0]['discount_ref']], 'amount = list − discount');
    $c = InvoiceInput::compute($l, '27', '27');
    same([800000, 72000, 72000, 0, 944000], [$c['lines'][0]['taxable'], $c['lines'][0]['cgst'], $c['lines'][0]['sgst'], $c['lines'][0]['igst'], $c['lines'][0]['total']], 'inclusive: GST on the net');
    // Exclusive ₹10,000 − ₹2,000 → taxable ₹8,000, IGST ₹1,440, total ₹9,440; the amount may also be given (must match).
    $errors = [];
    $l = InvoiceInput::lines([$base + ['price_basis' => 'exclusive', 'list_amount_paise' => 1000000, 'discount_paise' => 200000, 'discount_ref' => 'QTE-000012', 'amount_paise' => 800000]], $errors);
    same([], $errors, 'valid exclusive discounted line with amount');
    $c = InvoiceInput::compute($l, '27', '29');
    same([800000, 144000, 944000], [$c['lines'][0]['taxable'], $c['lines'][0]['igst'], $c['lines'][0]['total']], 'exclusive: GST on the net');
    // Zero discount needs no ref; the net may go down to ₹1 but not below.
    $errors = [];
    $l = InvoiceInput::lines([$base + ['price_basis' => 'exclusive', 'list_amount' => '500.00', 'discount' => '499.00', 'discount_ref' => 'X1'],
        $base + ['price_basis' => 'exclusive', 'list_amount' => '500.00', 'discount' => '0']], $errors);
    same([], $errors, 'net ₹1 allowed; zero discount allowed without a ref');
    same([100, 50000, null], [$l[0]['amount_paise'], $l[1]['amount_paise'], $l[1]['discount_ref']], 'amounts');
    // An ordinary line is unchanged (no discount keys set).
    $errors = [];
    $l = InvoiceInput::lines([$base + ['price_basis' => 'inclusive', 'amount' => '11800', 'list_amount' => null, 'discount_paise' => null, 'discount_ref' => '']], $errors);
    same([[], 1180000, null, null, null], [$errors, $l[0]['amount_paise'], $l[0]['list_amount_paise'], $l[0]['discount_paise'], $l[0]['discount_ref']], 'ordinary line');

    $errors = [];
    InvoiceInput::lines([
        $base + ['price_basis' => 'inclusive', 'list_amount' => '500.00', 'discount' => '499.50', 'discount_ref' => 'A'],
        $base + ['price_basis' => 'inclusive', 'list_amount' => '500.00', 'discount' => '50.00'],
        $base + ['price_basis' => 'inclusive', 'list_amount' => '500.00', 'discount' => '50.00', 'discount_ref' => 'A', 'amount' => '500.00'],
        $base + ['price_basis' => 'inclusive', 'discount' => '50.00', 'discount_ref' => 'A'],
        $base + ['price_basis' => 'inclusive', 'list_amount' => '500.00', 'discount' => '-1', 'discount_ref' => 'A'],
        $base + ['price_basis' => 'inclusive', 'list_amount' => '500.00', 'discount' => '5', 'discount_ref' => 'bad ref!'],
        $base + ['price_basis' => 'inclusive', 'amount' => '100', 'discount_ref' => 'ONLY-REF'],
    ], $errors);
    same(['lines[0].discount', 'lines[1].discount_ref', 'lines[2].amount', 'lines[3].list_amount', 'lines[4].discount', 'lines[5].discount_ref',
        'lines[6].list_amount', 'lines[6].discount'], array_keys($errors), 'each discount problem');
    check(str_contains($errors['lines[0].discount'], '499.00'), 'says the maximum discount');
});

test('printable document: discount columns only when a discount exists; old documents render byte-for-byte as before', function (): void {
    $doc = [
        'doc_type' => 'tax_invoice', 'doc_no' => 'AIC/26-27/000007', 'doc_date' => '2026-10-18', 'supply_type' => 'intra_state', 'reverse_charge' => false,
        'place_of_supply' => ['code' => '27', 'name' => 'Maharashtra'],
        'seller' => ['legal_name' => 'Aicountly Interactive', 'trade_name' => null, 'gstin' => '27AAPFU0939F1ZV', 'pan' => 'AAPFU0939F', 'address_line1' => '1 Street',
            'address_line2' => null, 'city' => 'Mumbai', 'pincode' => '400001', 'state_code' => '27', 'state_name' => 'Maharashtra', 'email' => 'billing@aicountly.test',
            'phone' => null, 'signatory_name' => 'R Gupta', 'signatory_designation' => 'Director'],
        'buyer' => ['legal_name' => 'Acme Ltd', 'gstin' => null, 'state_code' => '27', 'state_name' => 'Maharashtra', 'address' => "Line 1\nLine 2",
            'pincode' => '411001', 'country' => 'IN', 'email' => null],
        'lines' => [['line_no' => 1, 'item_code' => 'books.business', 'description' => 'Smart Books — Business', 'sac' => '997331', 'quantity' => '1',
            'period_start' => '2026-10-18', 'period_end' => '2027-10-17', 'taxable_paise' => 1000000, 'cgst_rate' => '9.00', 'cgst_paise' => 90000,
            'sgst_rate' => '9.00', 'sgst_paise' => 90000, 'igst_rate' => '18.00', 'igst_paise' => 0, 'total_paise' => 1180000]],
        'totals' => ['taxable_paise' => 1000000, 'cgst_paise' => 90000, 'sgst_paise' => 90000, 'igst_paise' => 0, 'total_paise' => 1180000],
        'amount_in_words' => 'Rupees Eleven Thousand Eight Hundred Only',
        'payment' => ['method' => 'neft', 'method_label' => 'NEFT', 'gateway' => null, 'reference' => 'HDFCN52026101800001', 'paid_on' => '2026-10-17'],
        'original_invoice' => null,
    ];
    // sha256 of the HTML the pre-discount DocumentHtml produced for these documents (pinned before the change).
    same('5c01766a05cd88d6d45770673165efb38f9bb3e49fffeacead750fa085c204ec', hash('sha256', DocumentHtml::render($doc)), 'invoice without discount: identical HTML');
    $cn = ['doc_type' => 'credit_note', 'supply_type' => 'inter_state', 'payment' => null, 'reason' => 'Refund', 'kind' => 'full',
        'original_invoice' => ['doc_no' => 'AIC/26-27/000007', 'doc_date' => '2026-10-18']] + $doc;
    same('ccff2104f427611978585e104900a5271c5ee48b9c5d632e0bb65e1833b8571e', hash('sha256', DocumentHtml::render($cn)), 'credit note without discount: identical HTML');
    $withNulls = $doc;
    $withNulls['lines'][0] += ['list_amount_paise' => null, 'discount_paise' => null, 'discount_ref' => null, 'amount_paise' => 1180000, 'price_basis' => 'inclusive'];
    same(hash('sha256', DocumentHtml::render($doc)), hash('sha256', DocumentHtml::render($withNulls)), 'null discount fields change nothing');

    $disc = $doc;
    $disc['lines'][0] = ['taxable_paise' => 800000, 'cgst_paise' => 72000, 'sgst_paise' => 72000, 'total_paise' => 944000, 'amount_paise' => 944000,
        'price_basis' => 'inclusive', 'list_amount_paise' => 1180000, 'discount_paise' => 236000, 'discount_ref' => 'DIWALI<26>'] + $disc['lines'][0];
    $disc['lines'][1] = ['line_no' => 2, 'description' => 'Add-on', 'amount_paise' => 11800, 'taxable_paise' => 10000, 'cgst_paise' => 900, 'sgst_paise' => 900,
        'total_paise' => 11800, 'price_basis' => 'inclusive', 'list_amount_paise' => null, 'discount_paise' => null, 'discount_ref' => null] + $doc['lines'][0];
    $html = DocumentHtml::render($disc);
    foreach (['<th class="r">List price</th><th class="r">Discount</th><th class="r">Taxable value</th>', '₹11,800.00<div class="sub">incl. GST</div>',
              '&minus;₹2,360.00<div class="sub">DIWALI&lt;26&gt;</div>', '₹8,000.00', '₹720.00', '₹9,440.00', '<td class="r">&mdash;</td>',
              'the taxable value and GST are on the price after discount'] as $needle) {
        check(str_contains($html, $needle), "discounted invoice contains {$needle}");
    }
    $cnHtml = DocumentHtml::render(['doc_type' => 'credit_note', 'payment' => null, 'reason' => 'Refund', 'original_invoice' => ['doc_no' => 'AIC/26-27/000007', 'doc_date' => '2026-10-18']] + $disc);
    check(!str_contains($cnHtml, 'List price'), 'credit note keeps its columns');
    check(str_contains($cnHtml, 'Original invoice line: list ₹11,800.00 less discount ₹2,360.00 (DIWALI&lt;26&gt;); credited on the discounted value'), 'credit note names the discount');
});

test('routes: S2S routes are Manage GET reads or Engage payment-link issues; webhooks are the only other routes without a staff session', function (): void {
    $routes   = require __DIR__ . '/../app/Config/Routes.php';
    $public   = [];
    $webhooks = [];
    foreach ($routes as $method => $table) {
        foreach ($table as $path => $cfg) {
            if (!empty($cfg['s2s'])) {
                same(false, !empty($cfg['auth']), "{$method} {$path}: S2S is never a user route");
                if (str_starts_with($path, 'v1/inbound/engage/payment-links')) {
                    same(['issue', ['engage']], [$cfg['s2s']['scope'], $cfg['s2s']['clients']], "{$method} {$path}: Engage issue key only");
                } else {
                    same(['GET', 'read', ['manage']], [$method, $cfg['s2s']['scope'], $cfg['s2s']['clients']], "{$method} {$path}: Manage read key, GET only");
                    check(str_starts_with($path, 'v1/inbound/manage/'), "{$method} {$path}: under v1/inbound/manage/ (no CORS there)");
                }
                check(\S2sFilter::isS2sPath('/api/' . $path), "{$method} {$path}: recognised as an S2S path");
                continue;
            }
            if (!empty($cfg['webhook'])) {
                same(false, !empty($cfg['auth']), "{$method} {$path}: a webhook is never a user route");
                check(\S2sFilter::isS2sPath('/api/' . $path), "{$method} {$path}: no CORS for webhooks");
                $webhooks[] = "{$method} {$path}";
                continue;
            }
            if (empty($cfg['auth']) && empty($cfg['inbound'])) {
                $public[] = "{$method} {$path}";
            }
        }
    }
    sort($public);
    same(['GET health', 'GET v1/auth/sso-callback', 'POST v1/auth/console-session'], $public, 'the only unauthenticated routes (Console staff sign-in + health)');
    same(['POST v1/webhooks/razorpay', 'POST v1/webhooks/cashfree'], $webhooks, 'the only webhooks');
    same(5, $routes['GET']['v1/inbound/manage/documents/{document_id}/pdf']['s2s']['cost'], 'a PDF counts 5');
    foreach (['GET v1/invoices' => 'view', 'GET v1/invoices/export' => 'edit', 'GET v1/invoices/{id}/pdf' => 'view', 'POST v1/offline-payments' => 'edit',
              'POST v1/offline-payments/{id}/verify' => 'approve', 'POST v1/invoices/{id}/credit-notes' => 'edit', 'POST v1/credit-notes/{id}/approve' => 'approve',
              'PUT v1/invoicing/seller-profile' => 'settings', 'POST v1/invoicing/series' => 'settings', 'PUT v1/invoicing/series/{id}' => 'settings'] as $route => $gate) {
        [$m, $p] = explode(' ', $route);
        same($gate, $routes[$m][$p]['gate'] ?? null, "{$route} gate");
    }
    $keys = array_keys($routes['GET']);
    check(array_search('v1/invoices/export', $keys, true) < array_search('v1/invoices/{id}', $keys, true), 'export matched before {id}');
    same(false, \S2sFilter::isS2sPath('/api/v1/inbound/engage/payout-batches'), 'Engage payout inbound unchanged');
});
