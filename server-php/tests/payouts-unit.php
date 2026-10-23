<?php
declare(strict_types=1);

/**
 * Payout rules without I/O — included by tests/run.php (uses its check/same/test helpers).
 */

require_once __DIR__ . '/../app/Filters/ServiceKeyFilter.php';

use App\Services\CompanyBankAccountService;
use App\Services\DataVault;
use App\Services\FinanceAccessPolicy;
use App\Services\Payouts\BankFileFormatter;
use App\Services\Payouts\BankResponseParser;
use App\Services\Payouts\InboundBatchValidator;
use App\Services\Payouts\PayoutService;
use App\Services\Payouts\PayoutStateMachine;
use App\Services\Payouts\TaxCalculator;
use App\Services\SettingsService;
use App\Support\ApiException;
use App\Support\FiscalYear;
use App\Support\Money;

/** Expect $fn to throw ApiException with $status; returns it. */
function throwsApi(int $status, callable $fn, string $what): ApiException
{
    try {
        $fn();
    } catch (ApiException $e) {
        same($status, $e->status(), $what . ' (status)');

        return $e;
    }
    check(false, $what . ' — expected ApiException ' . $status);
    throw new \LogicException('unreachable');
}

echo "Unit — payouts\n";

test('money: paise parsing, formatting, percentages (no floats)', function (): void {
    same(123450, Money::parse('1234.50'), '"1234.50"');
    same(123450, Money::parse('1234.5'), '"1234.5"');
    same(100, Money::parse(1), 'int');
    same(null, Money::parse('12.345'), 'three decimals refused');
    same(null, Money::parse('-5'), 'negative refused by default');
    same(-500, Money::parse('-5', true), 'negative allowed when asked');
    same(null, Money::parse('1e3'), 'exponent refused');
    same('1234.50', Money::format(123450), 'format');
    same('-0.05', Money::format(-5), 'format negative');
    same(200, Money::parseRate('2'), 'rate 2 → 200 bp');
    same(null, Money::parseRate('31', 3000), 'rate over max');
    same(50000, Money::percentOf(2500000, 200), '2% of 25000.00 = 500.00');
    same(1, Money::percentOf(50, 200), 'half-up rounding: 2% of 0.50 = 0.01');
    same(1000000, Money::exclusiveOf(1180000, 1800), '11800 incl. 18% → 10000 taxable');
});

test('financial year and 26Q quarters (IST)', function (): void {
    same('2026-27', FiscalYear::forDate('2026-04-01'), '1 Apr starts the FY');
    same('2025-26', FiscalYear::forDate('2026-03-31'), '31 Mar ends the FY');
    same(['2026-10-01', '2026-12-31'], FiscalYear::quarterRange('2026-27', 'Q3'), 'Q3');
    same(['2027-01-01', '2027-03-31'], FiscalYear::quarterRange('2026-27', 'q4'), 'Q4 is next calendar year');
    same('Q1', FiscalYear::quarterFor('2026-06-30'), 'Q1');
    same('Q4', FiscalYear::quarterFor('2027-02-01'), 'Q4');
    same(true, FiscalYear::isValid('2026-27'), 'valid fy');
    same(false, FiscalYear::isValid('2026-28'), 'invalid fy');
    same(false, FiscalYear::isDate('2026-02-30'), 'impossible date');
});

$tax = ['tds_rate_bp' => 200, 'tds_threshold' => 2000000, 'gst_mode' => 'exclusive', 'gst_rate_bp' => 1800];

test('TDS 194H: below threshold, exactly at it, crossing mid-FY, already crossed', function () use ($tax): void {
    $r = TaxCalculator::compute(1500000, false, $tax, 0, 0);
    same(0, $r['tds'], 'first ₹15,000 of the FY: no TDS');
    same(1500000, $r['net'], 'net = gross');
    check(str_contains($r['tds_note'], 'Below the FY threshold'), 'note explains');

    same(0, TaxCalculator::compute(500000, false, $tax, 1500000, 0)['tds'], 'cumulative exactly ₹20,000: not above → no TDS');

    // Crossing: ₹15,000 earlier this FY without TDS, now ₹10,000 → TDS on the whole ₹25,000.
    $x = TaxCalculator::compute(1000000, false, $tax, 1500000, 0);
    same(50000, $x['tds'], 'TDS 2% of cumulative 25,000 = 500.00');
    same(950000, $x['net'], 'net 10,000 − 500');
    same(2500000, $x['fy_cumulative'], 'cumulative');
    check(str_contains($x['tds_note'], 'Crosses the FY threshold'), 'note says it crossed');

    $after = TaxCalculator::compute(100000, false, $tax, 2500000, 50000);
    same(2000, $after['tds'], 'after crossing: 2% of this payment only (1000 → 20.00)');

    // A first payment alone above the threshold.
    same(50000, TaxCalculator::compute(2500000, false, $tax, 0, 0)['tds'], 'single 25,000 payment → 500.00');

    // Rate 0 (e.g. lower-deduction certificate handled outside): no TDS.
    same(0, TaxCalculator::compute(2500000, false, ['tds_rate_bp' => 0] + $tax, 0, 0)['tds'], 'rate 0');
});

test('GST modes: only with a GSTIN; TDS never on the GST component', function () use ($tax): void {
    $ex = TaxCalculator::compute(3000000, true, $tax, 0, 0);
    same('exclusive', $ex['gst_mode'], 'mode');
    same(3000000, $ex['taxable'], 'taxable = gross');
    same(540000, $ex['gst'], 'GST 18% on top = 5,400');
    same(60000, $ex['tds'], 'TDS 2% of 30,000 (not of 35,400)');
    same(3000000 + 540000 - 60000, $ex['net'], 'net = taxable + GST − TDS');

    $in = TaxCalculator::compute(3540000, true, ['gst_mode' => 'inclusive'] + $tax, 0, 0);
    same(3000000, $in['taxable'], 'inclusive: 35,400 → taxable 30,000');
    same(540000, $in['gst'], 'inclusive GST 5,400');
    same(60000, $in['tds'], 'TDS on taxable');
    same(3540000 - 60000, $in['net'], 'net = gross − TDS');

    $none = TaxCalculator::compute(3000000, true, ['gst_mode' => 'none'] + $tax, 0, 0);
    same(0, $none['gst'], 'mode none: no GST even with GSTIN');

    $noGstin = TaxCalculator::compute(3000000, false, $tax, 0, 0);
    same('none', $noGstin['gst_mode'], 'no GSTIN → none');
    same(0, $noGstin['gst'], 'no GSTIN → no GST');

    same(3000000 - 60000 - 25000, TaxCalculator::compute(3000000, false, $tax, 0, 0, -25000)['net'], 'adjustment changes net only');
});

test('state machine: allowed and refused moves', function (): void {
    same(true, PayoutStateMachine::canBatch('draft', 'in_review'), 'draft → in_review');
    same(false, PayoutStateMachine::canBatch('draft', 'approved'), 'draft cannot skip review');
    same(true, PayoutStateMachine::canBatch('in_review', 'draft'), 'return for changes');
    same(false, PayoutStateMachine::canBatch('file_generated', 'cancelled'), 'no cancel once the file exists');
    same(false, PayoutStateMachine::canBatch('cancelled', 'draft'), 'cancelled is final');
    same(false, PayoutStateMachine::canItem('draft', 'paid'), 'draft item cannot be paid');
    same(false, PayoutStateMachine::canItem('on_hold', 'paid'), 'held item cannot be paid');
    same(true, PayoutStateMachine::canItem('approved', 'paid'), 'approved → paid');
    same(true, PayoutStateMachine::canItem('paid', 'reversed'), 'paid → reversed');
    same(false, PayoutStateMachine::canItem('failed', 'paid'), 'failed is final');
    throwsApi(409, static fn () => PayoutStateMachine::assertItem('draft', 'paid'), 'assertItem refuses');

    same('file_generated', PayoutStateMachine::settledStatus(['approved', 'approved']), 'nothing paid yet');
    same('partially_paid', PayoutStateMachine::settledStatus(['paid', 'approved']), 'some paid');
    same('partially_paid', PayoutStateMachine::settledStatus(['paid', 'failed']), 'paid + failed');
    same('paid', PayoutStateMachine::settledStatus(['paid', 'paid']), 'all paid');
});

test('four-eyes: approver differs from preparer and last editor', function (): void {
    check(PayoutStateMachine::fourEyesViolation('a@x.com', 'A@X.com', null) !== null, 'preparer (case-insensitive) refused');
    check(PayoutStateMachine::fourEyesViolation('b@x.com', 'a@x.com', 'b@x.com') !== null, 'last editor refused');
    same(null, PayoutStateMachine::fourEyesViolation('c@x.com', 'a@x.com', 'b@x.com'), 'third person allowed');
});

test('role catalog and route gates', function (): void {
    same(['finance_admin', 'payout_approver', 'accountant', 'finance_viewer'], FinanceAccessPolicy::CATALOG, 'catalog mirrors Console');
    same(['accountant', 'payout_approver'], FinanceAccessPolicy::normalizeRoles(['ACCOUNTANT', 'payout_approver', 'sales_manager'], false), 'new roles accepted');
    $matrix = [
        'finance_viewer'  => ['view'],
        'accountant'      => ['view', 'edit'],
        'payout_approver' => ['view', 'edit', 'approve'],
        'finance_admin'   => ['view', 'edit', 'approve', 'settings'],
    ];
    foreach ($matrix as $role => $gates) {
        same($gates, FinanceAccessPolicy::gatesFor([$role]), "gates for {$role}");
    }
    same(false, FinanceAccessPolicy::allows(['finance_admin'], 'nonexistent'), 'unknown gate closed');
    same(false, FinanceAccessPolicy::allows([], 'view'), 'no role, no view');
});

test('every authenticated route has a valid gate; inbound routes use a service key', function (): void {
    $routes = require __DIR__ . '/../app/Config/Routes.php';
    foreach ($routes as $method => $table) {
        foreach ($table as $path => $cfg) {
            if (!empty($cfg['inbound'])) {
                same(false, !empty($cfg['auth']), "{$method} {$path}: inbound is not a user route");
                check(isset(ServiceKeyFilter::SERVICES[$cfg['inbound']]), "{$method} {$path}: known inbound service");
                continue;
            }
            if (empty($cfg['auth']) || $path === 'v1/auth/me') {
                continue;
            }
            check(isset($cfg['gate']) && isset(FinanceAccessPolicy::GATES[$cfg['gate']]), "{$method} {$path} has a gate");
            check(is_file(__DIR__ . '/../app/Controllers/' . str_replace('\\', '/', $cfg['controller']) . '.php'), "{$method} {$path} controller exists");
        }
    }
    same('approve', $routes['POST']['v1/payout-batches/{id}/approve']['gate'], 'approve gate');
    same('approve', $routes['POST']['v1/payout-items/{id}/payment']['gate'], 'record payment gate');
    same('edit', $routes['POST']['v1/payout-items/{id}/hold']['gate'], 'hold gate');
    same('settings', $routes['PUT']['v1/settings']['gate'], 'settings gate');
    same('approve', $routes['GET']['v1/payout-batches/{id}/bank-files/{fileId}']['gate'], 'bank file download (full account numbers)');
    same('edit', $routes['GET']['v1/tds-register/export']['gate'], 'TDS export (full PAN)');
    same('view', $routes['GET']['v1/bank-accounts']['gate'], 'every role lists company bank accounts (masked)');
    foreach (['POST v1/bank-accounts', 'PUT v1/bank-accounts/{id}', 'POST v1/bank-accounts/{id}/default',
              'POST v1/bank-accounts/{id}/deactivate', 'POST v1/bank-accounts/{id}/activate'] as $route) {
        [$m, $p] = explode(' ', $route);
        same('settings', $routes[$m][$p]['gate'] ?? null, "{$route}: finance_admin only");
    }
});

$keyHex = str_repeat('ab', 32);

test('data vault: libsodium encryption, blind index, masking', function () use ($keyHex): void {
    $vault = new DataVault((string) hex2bin($keyHex));
    $a = $vault->encrypt('ABCDE1234F');
    $b = $vault->encrypt('ABCDE1234F');
    check(str_starts_with($a, 'v1:'), 'versioned');
    check($a !== $b, 'random nonce: same plaintext, different ciphertext');
    check(!str_contains($a, 'ABCDE1234F') && !str_contains(base64_decode(substr($a, 3)), 'ABCDE1234F'), 'no plaintext inside');
    same('ABCDE1234F', $vault->decrypt($a), 'round trip');
    $other = new DataVault(random_bytes(32));
    $failed = false;
    try {
        $other->decrypt($a);
    } catch (\RuntimeException) {
        $failed = true;
    }
    check($failed, 'another key cannot decrypt');
    same($vault->blindIndex('abcde1234f'), $vault->blindIndex('ABCDE1234F'), 'blind index normalises case');
    check($vault->blindIndex('ABCDE1234F') !== $other->blindIndex('ABCDE1234F'), 'blind index is keyed');
    same('XXXXXX234F', DataVault::mask(DataVault::last4('ABCDE1234F')), 'mask PAN');
    same(32, strlen((string) DataVault::decodeKey(base64_encode(random_bytes(32)))), 'base64 key accepted');
    same(null, DataVault::decodeKey('short'), 'short key refused');
    $old = $_ENV['FINANCE_DATA_KEY'] ?? null;
    unset($_ENV['FINANCE_DATA_KEY']);
    putenv('FINANCE_DATA_KEY');
    throwsApi(503, static fn () => DataVault::fromEnv(), 'unset key → 503 (fail closed)');
    if ($old !== null) {
        $_ENV['FINANCE_DATA_KEY'] = $old;
    }
});

function samplePayload(array $override = [], array $itemOverride = []): array
{
    return $override + [
        'batch_ref'    => 'PAYRUN-2026-W42',
        'period_start' => '2026-10-05',
        'period_end'   => '2026-10-11',
        'currency'     => 'INR',
        'items'        => [$itemOverride + [
            'item_ref'     => 'PAYRUN-2026-W42-SLR-000123',
            'seller_code'  => 'SLR-000123',
            'payee'        => [
                'legal_name'  => 'Asha Traders',
                'pan'         => 'ABCPA1234F',
                'gstin'       => '27ABCPA1234F1Z5',
                'entity_type' => 'proprietorship',
                'bank'        => ['account_holder' => 'Asha Traders', 'account_number' => '001234567890', 'ifsc' => 'HDFC0001234'],
            ],
            'gross_amount' => '15000.00',
            'entries'      => [101, 102],
        ]],
    ];
}

test('inbound validation: contract shape accepted, every problem reported at once', function (): void {
    $ok = InboundBatchValidator::validate(samplePayload());
    same(1500000, $ok['items'][0]['gross'], 'gross in paise');
    same('ABCPA1234F', $ok['items'][0]['payee']['pan'], 'pan');

    $e = throwsApi(422, static fn () => InboundBatchValidator::validate(samplePayload(['currency' => 'USD'], [
        'gross_amount' => '-1',
        'payee' => ['legal_name' => 'X', 'pan' => 'BAD', 'gstin' => '29AAAAA0000A1Z5', 'entity_type' => 'trust',
            'bank' => ['account_holder' => 'X', 'account_number' => '12', 'ifsc' => 'HDFC1234567']],
    ])), 'invalid batch');
    $errors = $e->errors();
    foreach (['currency', 'items[0].gross_amount', 'items[0].payee.pan', 'items[0].payee.entity_type',
              'items[0].payee.bank.account_number', 'items[0].payee.bank.ifsc'] as $k) {
        check(isset($errors[$k]), "error for {$k}");
    }

    $mismatch = samplePayload([], ['payee' => ['gstin' => '27ZZZZZ9999Z1Z5'] + samplePayload()['items'][0]['payee']]);
    check(isset(throwsApi(422, static fn () => InboundBatchValidator::validate($mismatch), 'GSTIN of another PAN')->errors()['items[0].payee.gstin']), 'gstin/pan mismatch');

    $dup = samplePayload();
    $dup['items'][] = $dup['items'][0];
    check(isset(throwsApi(422, static fn () => InboundBatchValidator::validate($dup), 'duplicate item_ref')->errors()['items[1].item_ref']), 'duplicate flagged');
    throwsApi(422, static fn () => InboundBatchValidator::validate(samplePayload(['items' => []])), 'empty items');
});

test('bank files: generic NEFT/IMPS CSV and the bank-style variant', function (): void {
    $rows = [
        ['item_ref' => 'IT-1', 'holder' => '=HYPERLINK("x") Asha', 'account' => '001234567890', 'ifsc' => 'HDFC0001234', 'net' => 1470000],
        ['item_ref' => 'IT-2', 'holder' => 'Ravi, Kumar', 'account' => '998877665544', 'ifsc' => 'ICIC0000001', 'net' => 50],
    ];
    $ctx = ['format' => 'generic_neft_csv', 'payment_mode' => 'NEFT', 'debit_account' => '123456789012', 'value_date' => '2026-10-19',
        'narration_prefix' => 'Aicountly Commission', 'batch_ref' => 'PAYRUN-2026-W42'];
    $f = BankFileFormatter::build($rows, $ctx);
    $lines = explode("\r\n", trim($f['content']));
    same('Payment Mode,Beneficiary Name,Beneficiary Account Number,IFSC,Amount,Debit Account Number,Value Date,Narration,Customer Reference', $lines[0], 'header');
    same(3, count($lines), 'header + 2 rows');
    check(str_contains($lines[1], ',001234567890,HDFC0001234,14700.00,123456789012,19/10/2026,'), 'row fields: ' . $lines[1]);
    check(!str_contains($f['content'], '=HYPERLINK'), 'formula characters stripped from names');
    check(str_contains($lines[2], '"Ravi, Kumar"'), 'comma quoted');
    check(str_contains($lines[1], ',19/10/2026,Aicountly Commission IT-1,IT-1'), 'narration keeps the prefix\'s mixed case: ' . $lines[1]);
    same(1470050, $f['total'], 'total');
    same(hash('sha256', $f['content']), $f['sha256'], 'checksum of the exact bytes');
    same("'-5", BankFileFormatter::csv(['-5']), 'leading minus neutralised');

    $b = BankFileFormatter::build($rows, ['format' => 'icici_hdfc_style_csv'] + $ctx);
    check(str_starts_with($b['content'], 'PYMT_PROD_TYPE_CODE,PYMT_MODE,DEBIT_ACC_NO,BNF_NAME,BENE_ACC_NO'), 'bank-style header');
    check(str_contains($b['content'], 'PAB_VENDOR,NEFT,123456789012,'), 'bank-style row');
    check(str_contains($b['content'], ',Aicountly Commission PAYRUN-20,Aicountly Commission IT-1,'), 'DEBIT_NARR / CREDIT_NARR: case kept, 30 characters');
    same('Aicountly Commission', BankFileFormatter::text('Aicountly Commission', 30), 'text() never changes case');
    same(true, BankFileFormatter::FORMATS['icici_hdfc_style_csv']['confirm_format'], 'marked: confirm format with your bank');
    check(str_contains(BankFileFormatter::FORMATS['icici_hdfc_style_csv']['label'], 'confirm format with your bank'), 'label says so');
});

test('bank response parser: loose headers, paid needs a UTR, failures and pending', function (): void {
    $csv = "Customer Reference,UTR No,Status,Payment Date,Remarks\n"
         . "IT-1,HDFCN52026101912345,Success,19/10/2026,\n"
         . "IT-2,,Success,19/10/2026,\n"
         . "IT-3,,Rejected,19/10/2026,Account closed\n"
         . "IT-4,,Pending,,\n"
         . "IT-5,bad utr!,Success,,\n";
    $p = BankResponseParser::parse($csv);
    same('customerreference', $p['columns']['reference'], 'reference column found');
    same(['paid', 'skip', 'failed', 'skip', 'skip'], array_map(static fn ($r) => $r['outcome'], $p['rows']), 'outcomes');
    same('HDFCN52026101912345', $p['rows'][0]['utr'], 'utr');
    same('2026-10-19', $p['rows'][0]['paid_at'], 'date d/m/Y');
    check(str_contains((string) $p['rows'][1]['reason'], 'without a UTR'), 'success without UTR is not paid');
    same('Account closed', $p['rows'][2]['reason'], 'failure reason');

    $noStatus = BankResponseParser::parse("REF_NO,UTR\nIT-9,ABC123456\n");
    same('paid', $noStatus['rows'][0]['outcome'], 'no status column: UTR present = paid');
    $threw = false;
    try {
        BankResponseParser::parse("Name,Amount\nx,1\n");
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    check($threw, 'file without a reference column refused');
});

test('UTR and paid_at validation', function (): void {
    throwsApi(422, static fn () => PayoutService::normaliseUtr(''), 'empty UTR');
    throwsApi(422, static fn () => PayoutService::normaliseUtr(null), 'missing UTR');
    throwsApi(422, static fn () => PayoutService::normaliseUtr('12 34'), 'UTR with space');
    same('HDFCN5202610191', PayoutService::normaliseUtr(' hdfcn5202610191 '), 'normalised');
    throwsApi(422, static fn () => PayoutService::paidAt('2999-01-01'), 'future date refused');
    throwsApi(422, static fn () => PayoutService::paidAt('yesterday'), 'free text refused');
    check(str_starts_with(PayoutService::paidAt('2026-10-01'), '2026-10-01T12:00:00'), 'date → noon IST');
});

test('settings validation', function (): void {
    same(['tds_rate_percent' => '2.00', 'gst_mode' => 'inclusive', 'four_eyes_enabled' => 'false', 'payment_mode' => 'IMPS'],
        SettingsService::validate(['tds_rate_percent' => '2', 'gst_mode' => 'inclusive', 'four_eyes_enabled' => false, 'payment_mode' => 'imps']), 'normalised');
    $e = throwsApi(422, static fn () => SettingsService::validate(['tds_rate_percent' => '45', 'gst_mode' => 'sometimes', 'bogus' => 1, 'debit_account_number' => 'abc']), 'invalid settings');
    same(['tds_rate_percent', 'gst_mode', 'bogus', 'debit_account_number'], array_keys($e->errors()), 'each invalid key reported');
    check(str_contains((string) $e->errors()['debit_account_number'], 'Bank accounts'), 'the old debit account key points to Bank accounts');
    $e = throwsApi(422, static fn () => SettingsService::validate(['debit_account_number' => '123456789012']), 'debit account can no longer be set');
    check(isset($e->errors()['debit_account_number']), 'even a valid number is refused');
    same(false, array_key_exists('debit_account_number', SettingsService::DEFAULTS), 'not a setting any more');
});

test('company bank account validation: IFSC, 6–18 digits, confirmation, required fields', function (): void {
    $ok = [
        'label' => ' HDFC  current ', 'bank_name' => 'HDFC Bank', 'account_holder' => 'Aicountly Technologies Pvt Ltd',
        'account_number' => '1234 5678 9012', 'account_number_confirm' => '123456789012', 'ifsc' => 'hdfc0001234',
        'branch' => '', 'account_type' => 'current', 'bank_file_format' => '',
    ];
    $v = CompanyBankAccountService::validate($ok, null);
    same(['HDFC current', '123456789012', 'HDFC0001234', null, null, false], [$v['label'], $v['account_number'], $v['ifsc'], $v['branch'], $v['bank_file_format'], $v['is_default']], 'normalised');
    same('current', CompanyBankAccountService::validate(array_diff_key($ok, ['account_type' => 1]), null)['account_type'], 'type defaults to current');
    same('icici_hdfc_style_csv', CompanyBankAccountService::validate(['bank_file_format' => 'icici_hdfc_style_csv'] + $ok, null)['bank_file_format'], 'format override');
    same(true, CompanyBankAccountService::validate(['is_default' => true] + $ok, null)['is_default'], 'make default on create');

    $e = throwsApi(422, static fn () => CompanyBankAccountService::validate([
        'label' => '', 'bank_name' => '', 'account_holder' => ' ', 'account_number' => '12345', 'account_number_confirm' => '12345',
        'ifsc' => 'HDFC1234567', 'account_type' => 'loan', 'bank_file_format' => 'swift',
    ], null), 'everything wrong');
    same(['label', 'bank_name', 'account_holder', 'ifsc', 'account_type', 'bank_file_format', 'account_number'], array_keys($e->errors()), 'each problem reported');
    check(str_contains((string) $e->errors()['account_number'], '6 to 18 digits'), 'digits message');
    throwsApi(422, static fn () => CompanyBankAccountService::validate(['account_number' => '1234567890123456789', 'account_number_confirm' => '1234567890123456789'] + $ok, null), '19 digits');
    throwsApi(422, static fn () => CompanyBankAccountService::validate(['account_number' => '12345678901A'] + $ok, null), 'letters');
    $e = throwsApi(422, static fn () => CompanyBankAccountService::validate(['account_number_confirm' => '123456789013'] + $ok, null), 'confirmation differs');
    same(['account_number_confirm'], array_keys($e->errors()), 'confirmation error');
    throwsApi(422, static fn () => CompanyBankAccountService::validate(array_diff_key($ok, ['account_number' => 1]), null), 'number required on create');
    foreach (['SBIN0001234' => true, 'SBIN1001234' => false, 'SBI00001234' => false, 'SBIN000123' => false, 'ICIC0ABC12D' => true] as $ifsc => $valid) {
        same($valid, (bool) preg_match(CompanyBankAccountService::IFSC_PATTERN, $ifsc), "IFSC {$ifsc}");
    }

    // Edit: merged over the stored row; the number is optional; the row adopted from the old setting must be completed.
    $stored = ['label' => 'Primary account', 'bank_name' => null, 'account_holder' => '', 'ifsc' => null, 'branch' => null,
        'account_type' => 'current', 'bank_file_format' => null];
    $e = throwsApi(422, static fn () => CompanyBankAccountService::validate(['label' => 'Main'], $stored), 'adopted row must be completed on edit');
    same(['bank_name', 'account_holder', 'ifsc'], array_keys($e->errors()), 'missing details named');
    $v = CompanyBankAccountService::validate(['bank_name' => 'HDFC Bank', 'account_holder' => 'Aicountly', 'ifsc' => 'HDFC0001234'], $stored);
    same(['Primary account', null, null], [$v['label'], $v['account_number'], $v['bank_file_format']], 'number kept, other fields merged');
    same(false, CompanyBankAccountService::validate(['is_default' => true, 'bank_name' => 'B', 'account_holder' => 'A', 'ifsc' => 'HDFC0001234'], $stored)['is_default'], 'default is not changed by an edit');
    throwsApi(422, static fn () => CompanyBankAccountService::validate(['account_number' => '99887766', 'bank_name' => 'B', 'account_holder' => 'A', 'ifsc' => 'HDFC0001234'], $stored), 'new number needs the confirmation');

    $pub = CompanyBankAccountService::publicRow(['id' => 3, 'label' => 'L', 'bank_name' => null, 'account_holder' => '', 'account_number_last4' => '9012',
        'account_number_hash' => 'h', 'ifsc' => null, 'branch' => null, 'account_type' => 'od', 'bank_file_format' => 'icici_hdfc_style_csv',
        'is_default' => true, 'active' => true, 'source' => 'legacy_setting', 'created_by' => 'x', 'created_at' => 'now', 'updated_by' => null, 'updated_at' => 'now']);
    same(['XXXXXX9012', true, 'Overdraft (OD)'], [$pub['account_number_masked'], $pub['incomplete'], $pub['account_type_label']], 'public row masked, flagged incomplete');
    same(false, array_key_exists('account_number_hash', $pub) || array_key_exists('account_number_enc', $pub), 'no hash or ciphertext in the API shape');
});
