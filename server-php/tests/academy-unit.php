<?php
declare(strict_types=1);

/**
 * Aicountly Academy fees — unit tests without I/O (included by tests/run.php): the Razorpay and Cashfree adapters against
 * recorded request / response fixtures (in the shapes docs/ACADEMY_PAYMENTS.md asks the owner to verify), webhook
 * signatures with known-answer vectors computed by openssl, event parsing, scrubbing, the fee / GST checks on a link
 * request, and the Finance → Engage event body.
 */

use App\Security\AicS2s;
use App\Services\AcademyFees\CashfreeAdapter;
use App\Services\AcademyFees\EngagePaymentEventOutbox;
use App\Services\AcademyFees\EventScrubber;
use App\Services\AcademyFees\GatewayException;
use App\Services\AcademyFees\GatewayHttp;
use App\Services\AcademyFees\PaymentLinkInput;
use App\Services\AcademyFees\RazorpayAdapter;
use App\Support\ApiException;

/** A transport that records every call and answers from a queue of [status, body]. */
final class FixtureTransport
{
    /** @var list<array{method:string, url:string, headers:array<string,string>, body:?string}> */
    public array $calls = [];

    /** @param list<array{0:int, 1:mixed}> $answers */
    public function __construct(public array $answers = [])
    {
    }

    public function __invoke(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        [$status, $answer] = array_shift($this->answers) ?? [0, null];

        return ['status' => $status, 'body' => $answer === null ? null : (is_string($answer) ? $answer : json_encode($answer)), 'error' => $status === 0 ? 'connection refused' : null];
    }

    public function last(): array
    {
        return $this->calls[count($this->calls) - 1];
    }
}

function throwsGateway(callable $fn, string $what): GatewayException
{
    try {
        $fn();
    } catch (GatewayException $e) {
        return $e;
    }
    check(false, "{$what}: expected a GatewayException");
    throw new \LogicException('unreachable');
}

const RZP_KEY    = 'rzp_test_AbCdEf123456';
const RZP_SECRET = 'rzp-secret-NEVER-LEAK-0123456789';
const CF_APP     = 'TEST1012345678abcdef';
const CF_SECRET  = 'cfsk_ma_test_NEVER-LEAK-0123456789';
const LINK_ID    = 'fin_pl_01JAB7Q2Z9X4KX3J5C8V0M6N2P';

$linkReq = [
    'link_id' => LINK_ID, 'amount_paise' => 1180000, 'description' => 'Aicountly Edge — fee instalment 1 of 2',
    'payer' => ['name' => 'Ravi Kumar', 'email' => 'ravi@example.test', 'mobile' => '+919876543210'],
    'expire_at' => 1761300000, 'notify_sms' => false, 'notify_email' => true,
    'notes' => ['fin_link_id' => LINK_ID, 'academy_ref' => 'EDGE-ADM-42-F1', 'stream' => 'edge'],
    'return_url' => 'https://aicountly.com/academy/thank-you', 'notify_url' => 'https://finance.aicountly.org/api/v1/webhooks/cashfree',
];

test('Razorpay adapter: create / find / fetch / cancel / confirm / refund / auth check against fixtures; no secret in errors', function () use ($linkReq): void {
    $t = new FixtureTransport([[200, ['id' => 'plink_Ex1', 'short_url' => 'https://rzp.io/i/abc', 'status' => 'created', 'amount' => 1180000, 'amount_paid' => 0,
        'reference_id' => LINK_ID, 'payments' => null]]]);
    $a = new RazorpayAdapter(RZP_KEY, RZP_SECRET, new GatewayHttp($t), 'https://api.rzp.test');
    $link = $a->createLink($linkReq);
    $c = $t->last();
    same(['POST', 'https://api.rzp.test/v1/payment_links'], [$c['method'], $c['url']], 'POST /v1/payment_links');
    same('Basic ' . base64_encode(RZP_KEY . ':' . RZP_SECRET), $c['headers']['Authorization'], 'HTTP Basic key_id:secret');
    $b = json_decode((string) $c['body'], true);
    same([1180000, 'INR', false, LINK_ID, 1761300000], [$b['amount'], $b['currency'], $b['accept_partial'], $b['reference_id'], $b['expire_by']], 'amount in paise, no partial, our id as reference_id, expire_by unix');
    same(['name' => 'Ravi Kumar', 'contact' => '+919876543210', 'email' => 'ravi@example.test'], $b['customer'], 'customer');
    same(['sms' => false, 'email' => true], $b['notify'], 'notify');
    same([LINK_ID, 'https://aicountly.com/academy/thank-you', 'get'], [$b['notes']['fin_link_id'], $b['callback_url'], $b['callback_method']], 'notes + callback');
    same(['plink_Ex1', 'https://rzp.io/i/abc', 'created', 0, null], [$link['provider_link_id'], $link['url'], $link['status'], $link['amount_paid_paise'], $link['payments']], 'parsed link');

    $t->answers = [[200, ['payment_links' => [['id' => 'plink_Other', 'reference_id' => 'fin_pl_OTHER'], ['id' => 'plink_Ex1', 'short_url' => 'u', 'status' => 'created', 'reference_id' => LINK_ID]]]]];
    same('plink_Ex1', $a->findLink(LINK_ID)['provider_link_id'], 'find by reference_id');
    same('https://api.rzp.test/v1/payment_links?reference_id=' . LINK_ID, $t->last()['url'], 'find URL');

    $paid = ['id' => 'plink_Ex1', 'short_url' => 'u', 'status' => 'paid', 'amount' => 1180000, 'amount_paid' => 1180000, 'reference_id' => LINK_ID,
        'payments' => [['amount' => 1180000, 'created_at' => 1761210000, 'method' => 'upi', 'payment_id' => 'pay_P1', 'plink_id' => 'plink_Ex1', 'status' => 'captured']]];
    $t->answers = [[200, $paid]];
    $p = $a->confirmPayment('plink_Ex1', LINK_ID, 'pay_P1');
    same(['pay_P1', 'pay_P1', 1180000, 'upi', true], [$p['provider_payment_id'], $p['gateway_payment_ref'], $p['amount_paise'], $p['method'], $p['captured']], 'confirmed from the link\'s payments');
    same('2025-10-23T14:30:00+05:30', $p['paid_at'], 'paid_at in IST');
    $t->answers = [[200, $paid]];
    same(null, $a->confirmPayment('plink_Ex1', LINK_ID, 'pay_NOTOURS'), 'a payment the link does not list is not confirmed');
    $t->answers = [[200, ['reference_id' => 'fin_pl_SOMEONEELSE'] + $paid]];
    throwsGateway(fn () => $a->fetchLink('plink_Ex1', LINK_ID), 'link of another reference refused');

    // Fallback when the link's answer omits payments: the payment entity, tied by the link's order or our note.
    $t->answers = [[200, ['payments' => null, 'order_id' => 'order_9'] + $paid], [200, ['id' => 'pay_P1', 'amount' => 1180000, 'status' => 'captured', 'method' => 'card',
        'order_id' => 'order_9', 'created_at' => 1761210000, 'notes' => []]]];
    same('pay_P1', $a->confirmPayment('plink_Ex1', LINK_ID, 'pay_P1')['gateway_payment_ref'] ?? null, 'fallback: same order');
    same('https://api.rzp.test/v1/payments/pay_P1', $t->last()['url'], 'GET /v1/payments/{id}');
    $t->answers = [[200, ['payments' => null] + $paid], [200, ['id' => 'pay_P1', 'amount' => 1180000, 'status' => 'authorized', 'order_id' => 'x', 'notes' => ['fin_link_id' => LINK_ID]]]];
    same(null, $a->confirmPayment('plink_Ex1', LINK_ID, 'pay_P1'), 'fallback: authorized is not captured');

    $t->answers = [[200, ['id' => 'plink_Ex1', 'short_url' => 'u', 'status' => 'cancelled', 'reference_id' => LINK_ID]]];
    same('cancelled', $a->cancelLink('plink_Ex1', LINK_ID)['status'], 'cancel');
    same(['POST', 'https://api.rzp.test/v1/payment_links/plink_Ex1/cancel'], [$t->last()['method'], $t->last()['url']], 'POST …/cancel');

    $t->answers = [[200, ['id' => 'rfnd_1', 'payment_id' => 'pay_P1', 'amount' => 50000, 'status' => 'processed', 'created_at' => 1761210000]]];
    same(['rfnd_1', 'pay_P1', 50000, true], array_values(array_intersect_key($a->fetchRefund('pay_P1', 'rfnd_1'), array_flip(['provider_refund_id', 'provider_payment_id', 'amount_paise', 'processed']))), 'refund');
    $t->answers = [[200, ['id' => 'rfnd_1', 'payment_id' => 'pay_OTHER', 'amount' => 50000, 'status' => 'processed']]];
    same(null, $a->fetchRefund('pay_P1', 'rfnd_1'), 'a refund of another payment is not ours');

    $t->answers = [[200, ['entity' => 'collection', 'items' => []]]];
    same(true, $a->testConnection()['ok'], 'auth check ok');
    same('https://api.rzp.test/v1/payments?count=1', $t->last()['url'], 'auth check URL (read-only)');
    $t->answers = [[401, ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed']]]];
    $bad = $a->testConnection();
    same(false, $bad['ok'], 'auth check refused');
    check(str_contains($bad['message'], 'HTTP 401') && str_contains($bad['message'], 'Authentication failed'), 'message names the refusal: ' . $bad['message']);
    $t->answers = [[500, ['error' => ['description' => 'oops']]]];
    $e = throwsGateway(fn () => $a->createLink($linkReq), '500');
    same([500, true], [$e->httpStatus(), $e->retryable()], '5xx is retryable');
    $t->answers = [[0, null]];
    same(true, throwsGateway(fn () => $a->createLink($linkReq), 'network')->retryable(), 'no answer is retryable');
    $t->answers = [[400, ['error' => ['description' => 'reference_id already exists']]]];
    same(false, throwsGateway(fn () => $a->createLink($linkReq), '400')->retryable(), '4xx is not retryable');
    foreach ([$bad['message'], $e->getMessage()] as $m) {
        check(!str_contains($m, RZP_SECRET) && !str_contains($m, base64_encode(RZP_KEY . ':' . RZP_SECRET)), 'no secret in an error message');
    }
});

test('Cashfree adapter: rupee amounts, 10-digit phone, IST expiry, x-client headers, orders → cf_payment_id, 404 = auth ok', function () use ($linkReq): void {
    $t = new FixtureTransport([[200, ['cf_link_id' => 77, 'link_id' => LINK_ID, 'link_url' => 'https://payments.cashfree.com/links/abc', 'link_status' => 'ACTIVE',
        'link_amount' => 11800, 'link_amount_paid' => 0]]]);
    $a = new CashfreeAdapter(CF_APP, CF_SECRET, 'test', new GatewayHttp($t));
    $link = $a->createLink($linkReq);
    $c = $t->last();
    same(['POST', 'https://sandbox.cashfree.com/pg/links'], [$c['method'], $c['url']], 'test mode → sandbox host');
    same([CF_APP, CF_SECRET, '2023-08-01'], [$c['headers']['x-client-id'], $c['headers']['x-client-secret'], $c['headers']['x-api-version']], 'x-client-id / secret / api version');
    $b = json_decode((string) $c['body'], true);
    same([LINK_ID, 11800, 'INR', false], [$b['link_id'], $b['link_amount'], $b['link_currency'], $b['link_partial_payments']], 'our link_id; amount in RUPEES');
    same(['customer_phone' => '9876543210', 'customer_name' => 'Ravi Kumar', 'customer_email' => 'ravi@example.test'], $b['customer_details'], '10-digit phone');
    same('2025-10-24T15:30:00+05:30', $b['link_expiry_time'], 'expiry ISO 8601 in IST');
    same(['send_sms' => false, 'send_email' => true], $b['link_notify'], 'notify');
    same(['notify_url' => 'https://finance.aicountly.org/api/v1/webhooks/cashfree', 'return_url' => 'https://aicountly.com/academy/thank-you'], $b['link_meta'], 'notify / return URLs');
    same([LINK_ID, 'https://payments.cashfree.com/links/abc', 'created', 'ACTIVE'], [$link['provider_link_id'], $link['url'], $link['status'], $link['provider_status']], 'ACTIVE → created');
    $odd = new FixtureTransport([[200, ['link_id' => LINK_ID]]]);
    (new CashfreeAdapter(CF_APP, CF_SECRET, 'live', new GatewayHttp($odd)))->createLink(['amount_paise' => 1234567] + $linkReq);
    same(['https://api.cashfree.com/pg/links', 12345.67], [$odd->last()['url'], json_decode((string) $odd->last()['body'], true)['link_amount']], 'live host; paise → rupees exactly');

    $t->answers = [
        [200, [['order_id' => 'CFPay_1', 'cf_order_id' => '900', 'order_amount' => 11800, 'order_status' => 'PAID', 'created_at' => '2026-10-23T14:30:00+05:30']]],
        [200, [['cf_payment_id' => 5100022, 'payment_status' => 'FAILED', 'payment_amount' => 11800], ['cf_payment_id' => 5100023, 'payment_status' => 'SUCCESS',
            'payment_amount' => 11800.00, 'payment_time' => '2026-10-23T14:31:00+05:30', 'payment_group' => 'upi']]],
    ];
    $p = $a->confirmPayment(LINK_ID, LINK_ID, 'CFPay_1');
    same(['CFPay_1', '5100023', 1180000, 'upi', '2026-10-23T14:31:00+05:30'], [$p['provider_payment_id'], $p['gateway_payment_ref'], $p['amount_paise'], $p['method'], $p['paid_at']],
        'order of the link → its SUCCESS payment: bare cf_payment_id as a string');
    same(['https://sandbox.cashfree.com/pg/links/' . LINK_ID . '/orders', 'https://sandbox.cashfree.com/pg/orders/CFPay_1/payments'],
        array_map(static fn (array $c) => $c['url'], array_slice($t->calls, -2)), 'orders of the link, then payments of the order');
    $t->answers = [[200, [['order_id' => 'CFPay_1', 'order_amount' => 11800, 'order_status' => 'ACTIVE']]]];
    same(null, $a->confirmPayment(LINK_ID, LINK_ID, 'CFPay_1'), 'an unpaid order is not confirmed');
    $t->answers = [[200, []]];
    same(null, $a->confirmPayment(LINK_ID, LINK_ID, 'CFPay_X'), 'an order of another link is not confirmed');

    $t->answers = [[200, ['link_id' => LINK_ID, 'link_url' => 'u', 'link_status' => 'PAID', 'link_amount_paid' => '11800.00']],
        [200, [['order_id' => 'CFPay_1', 'order_amount' => '11800.00', 'order_status' => 'PAID']]]];
    $l = $a->fetchLink(LINK_ID, LINK_ID);
    same(['paid', 1180000, 'CFPay_1', true], [$l['status'], $l['amount_paid_paise'], $l['payments'][0]['provider_payment_id'], $l['payments'][0]['captured']], 'fetch: PAID, string amounts');

    $t->answers = [[404, ['message' => 'link does not exist', 'code' => 'link_not_found']]];
    same(true, $a->testConnection()['ok'], '404 on an unknown link = the credentials work');
    $t->answers = [[401, ['message' => 'authentication Failed', 'type' => 'authentication_error']]];
    $bad = $a->testConnection();
    same(false, $bad['ok'], '401 = they do not');
    check(!str_contains($bad['message'], CF_SECRET), 'no secret in the message');
    $t->answers = [[404, ['message' => 'link does not exist']]];
    same(null, $a->findLink(LINK_ID), 'find: unknown → null');
    $t->answers = [[200, ['link_id' => LINK_ID, 'link_url' => 'u', 'link_status' => 'CANCELLED']]];
    same('cancelled', $a->cancelLink(LINK_ID, LINK_ID)['status'], 'cancel');
    $t->answers = [[200, ['cf_refund_id' => '77', 'refund_id' => 'refund_1', 'order_id' => 'CFPay_1', 'refund_amount' => 500, 'refund_status' => 'SUCCESS',
        'processed_at' => '2026-10-24T10:00:00+05:30']]];
    $r = $a->fetchRefund('CFPay_1', 'refund_1');
    same(['refund_1', 50000, true], [$r['provider_refund_id'], $r['amount_paise'], $r['processed']], 'refund');
    same('https://sandbox.cashfree.com/pg/orders/CFPay_1/refunds/refund_1', $t->last()['url'], 'refund URL');
    same([1180050, 1180000, 1180000, null], [CashfreeAdapter::paise(11800.5), CashfreeAdapter::paise('11800'), CashfreeAdapter::paise(11800), CashfreeAdapter::paise('1,180')], 'rupees → paise');
});

test('webhook signatures: known-answer vectors (openssl), constant-time verify, wrong secret / body / header refused', function (): void {
    $rb = '{"event":"payment_link.paid","payload":{}}';
    $rs = 'b8a70af329f8a7ecad69c628e9d81d6a93fd49d41c10b390c5197eefc968154b'; // printf … | openssl dgst -sha256 -hmac rzp-webhook-example-secret
    same(true, RazorpayAdapter::verifySignature(['x-razorpay-signature' => $rs], $rb, 'rzp-webhook-example-secret'), 'Razorpay: hex HMAC-SHA256(body, webhook secret)');
    same(true, RazorpayAdapter::verifySignature(['x-razorpay-signature' => strtoupper($rs)], $rb, 'rzp-webhook-example-secret'), 'hex case does not matter');
    same(false, RazorpayAdapter::verifySignature(['x-razorpay-signature' => $rs], $rb, 'other-secret'), 'wrong secret');
    same(false, RazorpayAdapter::verifySignature(['x-razorpay-signature' => $rs], $rb . ' ', 'rzp-webhook-example-secret'), 'body changed by one byte');
    same(false, RazorpayAdapter::verifySignature([], $rb, 'rzp-webhook-example-secret'), 'no header');
    same(false, RazorpayAdapter::verifySignature(['x-razorpay-signature' => $rs], $rb, ''), 'no secret never verifies');

    $cb = '{"type":"PAYMENT_LINK_EVENT","data":{}}';
    $cs = 'N4Hl4NSooZITfF80aM5+R8sfLFUm/G93VJ+AhZBV+7c='; // printf '1761200000000…' | openssl dgst -sha256 -hmac cf-example-client-secret -binary | base64
    $h  = ['x-webhook-signature' => $cs, 'x-webhook-timestamp' => '1761200000000'];
    same(true, CashfreeAdapter::verifySignature($h, $cb, 'cf-example-client-secret'), 'Cashfree: base64(HMAC-SHA256(timestamp . body, secret))');
    same(false, CashfreeAdapter::verifySignature(['x-webhook-timestamp' => '1761200000001'] + $h, $cb, 'cf-example-client-secret'), 'timestamp is signed');
    same(false, CashfreeAdapter::verifySignature($h, $cb, 'wrong'), 'wrong secret');
    same(false, CashfreeAdapter::verifySignature(['x-webhook-signature' => $cs], $cb, 'cf-example-client-secret'), 'timestamp header required');

    same('razorpay:evt:evt_ABC123', RazorpayAdapter::fingerprint($rb, ['x-razorpay-event-id' => 'evt_ABC123']), 'Razorpay fingerprint = event id');
    same('razorpay:sha256:' . hash('sha256', $rb), RazorpayAdapter::fingerprint($rb, []), 'else the body hash');
    same('cashfree:sha256:' . hash('sha256', $cb), CashfreeAdapter::fingerprint($cb, $h), 'Cashfree fingerprint = body hash');
});

test('webhook parsing: payment / link / refund events normalised; foreign and unknown events ignored', function (): void {
    $paid = json_encode(['entity' => 'event', 'event' => 'payment_link.paid', 'payload' => [
        'payment_link' => ['entity' => ['id' => 'plink_Ex1', 'reference_id' => LINK_ID, 'status' => 'paid', 'amount_paid' => 1180000]],
        'payment' => ['entity' => ['id' => 'pay_P1', 'amount' => 1180000, 'status' => 'captured', 'method' => 'upi', 'created_at' => 1761210000, 'order_id' => 'order_9']],
    ]]);
    $e = RazorpayAdapter::parseWebhook($paid, ['x-razorpay-event-id' => 'evt_1']);
    same(['payment', 'payment_link.paid', 'evt_1', LINK_ID, 'plink_Ex1', 'paid', 1180000], [$e['kind'], $e['event_type'], $e['event_id'], $e['reference'], $e['provider_link_id'],
        $e['link_status'], $e['link_amount_paid_paise']], 'payment_link.paid');
    same(['pay_P1', 'pay_P1', 1180000, true], [$e['payment']['provider_payment_id'], $e['payment']['gateway_payment_ref'], $e['payment']['amount_paise'], $e['payment']['captured']], 'its payment');
    same('link', RazorpayAdapter::parseWebhook(json_encode(['event' => 'payment_link.expired', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_Ex1',
        'reference_id' => LINK_ID, 'status' => 'expired']]]]), [])['kind'], 'payment_link.expired → link');
    $cap = RazorpayAdapter::parseWebhook(json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_X', 'amount' => 100, 'status' => 'captured']]]]), []);
    same('ignored', $cap['kind'], 'payment.captured without our note is ignored');
    same('payment', RazorpayAdapter::parseWebhook(json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_X', 'amount' => 100,
        'status' => 'captured', 'notes' => ['fin_link_id' => LINK_ID]]]]]), [])['kind'], '…with notes.fin_link_id it is a payment');
    $ref = RazorpayAdapter::parseWebhook(json_encode(['event' => 'refund.processed', 'payload' => ['refund' => ['entity' => ['id' => 'rfnd_1', 'payment_id' => 'pay_P1',
        'amount' => 50000, 'status' => 'processed', 'created_at' => 1761210000]]]]), []);
    same(['refund', 'rfnd_1', 'pay_P1', 50000], [$ref['kind'], $ref['refund']['provider_refund_id'], $ref['refund']['provider_payment_id'], $ref['refund']['amount_paise']], 'refund.processed');
    same('ignored', RazorpayAdapter::parseWebhook('{"event":"order.paid"}', [])['kind'], 'unknown event');
    same('ignored', RazorpayAdapter::parseWebhook('not json', [])['kind'], 'not JSON');

    $cf = CashfreeAdapter::parseWebhook(json_encode(['type' => 'PAYMENT_LINK_EVENT', 'event_time' => '2026-10-23T14:31:00+05:30', 'data' => [
        'link_id' => LINK_ID, 'link_status' => 'PAID', 'link_amount' => '11800.00', 'link_amount_paid' => '11800.00',
        'order' => ['order_id' => 'CFPay_1', 'order_amount' => '11800.00', 'transaction_id' => 1021206, 'transaction_status' => 'SUCCESS'],
        'customer_details' => ['customer_phone' => '9876543210', 'customer_email' => 'ravi@example.test']]]), []);
    same(['payment', LINK_ID, 'paid', 1180000, 'CFPay_1', null, '1021206', 1180000], [$cf['kind'], $cf['reference'], $cf['link_status'], $cf['link_amount_paid_paise'],
        $cf['payment']['provider_payment_id'], $cf['payment']['gateway_payment_ref'], $cf['payment']['provider_txn_ref'], $cf['payment']['amount_paise']],
        'PAYMENT_LINK_EVENT: keyed by order_id; cf_payment_id is read at verification');
    $ps = CashfreeAdapter::parseWebhook(json_encode(['type' => 'PAYMENT_SUCCESS_WEBHOOK', 'data' => ['order' => ['order_id' => 'CFPay_1', 'order_amount' => 11800,
        'order_tags' => ['link_id' => LINK_ID]], 'payment' => ['cf_payment_id' => 5100023, 'payment_status' => 'SUCCESS', 'payment_amount' => 11800, 'payment_group' => 'upi']]]), []);
    same(['payment', 'CFPay_1', '5100023'], [$ps['kind'], $ps['payment']['provider_payment_id'], $ps['payment']['gateway_payment_ref']], 'PAYMENT_SUCCESS_WEBHOOK with order_tags.link_id');
    same('ignored', CashfreeAdapter::parseWebhook(json_encode(['type' => 'PAYMENT_SUCCESS_WEBHOOK', 'data' => ['order' => ['order_id' => 'x'], 'payment' => ['payment_status' => 'SUCCESS']]]), [])['kind'],
        'a PG payment not from our link is ignored');
    same('refund', CashfreeAdapter::parseWebhook(json_encode(['type' => 'REFUND_STATUS_WEBHOOK', 'data' => ['refund' => ['refund_id' => 'refund_1', 'order_id' => 'CFPay_1',
        'refund_amount' => 500, 'refund_status' => 'SUCCESS']]]), [])['kind'], 'REFUND_STATUS_WEBHOOK SUCCESS');
    same('ignored', CashfreeAdapter::parseWebhook(json_encode(['type' => 'REFUND_STATUS_WEBHOOK', 'data' => ['refund' => ['refund_id' => 'r', 'order_id' => 'o',
        'refund_amount' => 1, 'refund_status' => 'PENDING']]]), [])['kind'], 'a pending refund is ignored');
    same(['created', 'partially_paid', 'cancelled', 'cancelled', null], [CashfreeAdapter::status('ACTIVE'), CashfreeAdapter::status('PARTIALLY_PAID'),
        CashfreeAdapter::status('CANCELLED'), CashfreeAdapter::status('TERMINATED'), CashfreeAdapter::status('WEIRD')], 'Cashfree statuses');
});

test('scrubbing: no email, phone, VPA, card or bank data survives in a stored event', function (): void {
    $s = EventScrubber::scrub(['payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'amount' => 100, 'method' => 'upi', 'email' => 'a@b.c', 'contact' => '+91987',
        'vpa' => 'x@okbank', 'card' => ['last4' => '1111', 'network' => 'Visa'], 'bank' => 'HDFC', 'notes' => ['fin_link_id' => LINK_ID]]]],
        'data' => ['customer_details' => ['customer_phone' => '9876543210'], 'payment' => ['payment_method' => ['upi' => ['upi_id' => 'x@okbank']]]]]);
    $json = json_encode($s);
    foreach (['a@b.c', '+91987', 'x@okbank', '1111', 'HDFC', '9876543210'] as $secret) {
        check(!str_contains($json, $secret), "scrubbed: {$secret}");
    }
    same(['pay_1', 100, 'upi', LINK_ID], [$s['payload']['payment']['entity']['id'], $s['payload']['payment']['entity']['amount'], $s['payload']['payment']['entity']['method'],
        $s['payload']['payment']['entity']['notes']['fin_link_id']], 'ids, amounts, method and our note kept');
});

test('link request: Finance recomputes the GST split (intra / inter-state, SGST floor), refuses a mismatch with its figures; payer and expiry rules', function (): void {
    $now  = 1761200000;
    $base = [
        'source_ref' => 'LD-2026-000123', 'academy_ref' => 'EDGE-ADM-0042-F1', 'stream' => 'edge', 'description' => 'Aicountly Edge — fee instalment 1 of 2',
        'payer' => ['name' => 'Ravi Kumar', 'email' => 'Ravi@Example.test', 'mobile' => '+91 98765-43210', 'state_code' => '27'],
        'amount_paise' => 1180001,
        'fee' => ['taxable_paise' => 1000001, 'cgst_paise' => 90000, 'sgst_paise' => 90000, 'igst_paise' => 0, 'sac' => '999293', 'gst_rate' => '18', 'price_basis' => 'inclusive'],
    ];
    $v = PaymentLinkInput::validate($base, '27', true, $now);
    same([1180001, 1000001, 90000, 90000, 0, 'intra_state', '27'], [$v['amount_paise'], $v['fee']['taxable_paise'], $v['fee']['cgst_paise'], $v['fee']['sgst_paise'],
        $v['fee']['igst_paise'], $v['supply_type'], $v['place_of_supply']], 'intra-state split accepted');
    same(['+919876543210', 'ravi@example.test', 'edge', $now + 15 * 86400], [$v['payer']['mobile'], $v['payer']['email'], $v['product_code'], $v['expire_at']], 'normalised mobile, email, product, default expiry');

    $odd = $base;
    $odd['amount_paise'] = 1180003;  // tax 180000.46 → 180000; taxable 1000003
    $odd['fee'] = ['taxable_paise' => 1000003] + $odd['fee'];
    same([90000, 90000], [PaymentLinkInput::validate($odd, '27', true, $now)['fee']['cgst_paise'], PaymentLinkInput::validate($odd, '27', true, $now)['fee']['sgst_paise']], 'even tax split');
    $odd2 = $base;
    $odd2['amount_paise'] = 11801;  // tax round(11801×18/118) = 1800 (1800.15) → CGST 900 SGST 900
    $odd2['fee'] = ['taxable_paise' => 10001, 'cgst_paise' => 900, 'sgst_paise' => 900] + $odd2['fee'];
    same(10001, PaymentLinkInput::validate($odd2, '27', true, $now)['fee']['taxable_paise'], 'small amount');
    $odd3 = $base;
    $odd3['amount_paise'] = 11813;  // tax round(11813×18/118) = 1802 (1802.03) → SGST floor 901, CGST 901
    $odd3['fee'] = ['taxable_paise' => 10011, 'cgst_paise' => 901, 'sgst_paise' => 901] + $odd3['fee'];
    same(10011, PaymentLinkInput::validate($odd3, '27', true, $now)['fee']['taxable_paise'], 'rounding half up per line');
    $odd4 = $base;
    $odd4['amount_paise'] = 11819;  // tax = round(11819×18/118) = 1803 (1802.9) → SGST floor(1803/2)=901, CGST 902
    $odd4['fee'] = ['taxable_paise' => 10016, 'cgst_paise' => 902, 'sgst_paise' => 901] + $odd4['fee'];
    same([902, 901], array_values(array_intersect_key(PaymentLinkInput::validate($odd4, '27', true, $now)['fee'], array_flip(['cgst_paise', 'sgst_paise']))), 'CGST carries the odd paisa');

    $inter = $base;
    $inter['payer']['state_code'] = '29';
    try {
        PaymentLinkInput::validate($inter, '27', true, $now);
        check(false, 'inter-state with a CGST/SGST split must be refused');
    } catch (ApiException $e) {
        same('fee_breakdown_mismatch', $e->errorCode(), 'code');
        same(['taxable_paise' => 1000001, 'cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 180000, 'amount_paise' => 1180001, 'place_of_supply' => '29',
            'seller_state_code' => '27', 'supply_type' => 'inter_state'], $e->errors()['expected'], 'Finance\'s figures in the answer');
    }
    $inter['fee'] = ['cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 180000] + $inter['fee'];
    same(['inter_state', 180000], [PaymentLinkInput::validate($inter, '27', true, $now)['supply_type'], PaymentLinkInput::validate($inter, '27', true, $now)['fee']['igst_paise']], 'IGST accepted');

    $ex = $base;
    $ex['fee'] = ['taxable_paise' => 1000000, 'cgst_paise' => 90000, 'sgst_paise' => 90000, 'price_basis' => 'exclusive'] + $ex['fee'];
    $ex['amount_paise'] = 1180000;
    same(1180000, PaymentLinkInput::validate($ex, '27', true, $now)['amount_paise'], 'exclusive: taxable + tax = amount');
    $ex['amount_paise'] = 1180001;
    same('fee_breakdown_mismatch', (static function () use ($ex, $now): ?string {
        try {
            PaymentLinkInput::validate($ex, '27', true, $now);
        } catch (ApiException $e) {
            return $e->errorCode();
        }

        return null;
    })(), 'exclusive: a total that is not taxable + tax is refused');

    $codes = static function (array $in, bool $strict = true) use ($now): array {
        try {
            PaymentLinkInput::validate($in, '27', $strict, $now);
        } catch (ApiException $e) {
            return array_keys($e->errors());
        }

        return [];
    };
    same(['source_ref', 'academy_ref', 'stream', 'product_code', 'description', 'payer.name', 'payer.mobile', 'payer.state_code', 'fee.sac', 'fee.gst_rate', 'fee.price_basis', 'amount_paise',
        'fee.taxable_paise', 'fee.cgst_paise', 'fee.sgst_paise', 'fee.igst_paise'], $codes([]), 'every problem at once');
    same(['payer.mobile'], $codes(['payer' => ['mobile' => '12345'] + $base['payer']] + $base), 'not an Indian mobile');
    same(['amount_paise'], $codes(['amount_paise' => '1180001'] + $base), 'amount_paise must be an integer (paise)');
    same(['expire_at'], $codes(['expire_at' => date(DATE_ATOM, $now + 60)] + $base), 'expiry under 15 minutes');
    same(['expire_at'], $codes(['expire_at' => date(DATE_ATOM, $now + 200 * 86400)] + $base), 'expiry beyond 180 days');
    same(['payer.address'], $codes(['amount_paise' => 5900000, 'fee' => ['taxable_paise' => 5000000, 'cgst_paise' => 450000, 'sgst_paise' => 450000] + $base['fee']] + $base),
        'from ₹50,000 taxable the address and PIN are required (Rule 46(e))');
    same([], $codes(['amount_paise' => 5900000, 'fee' => ['taxable_paise' => 5000000, 'cgst_paise' => 450000, 'sgst_paise' => 450000] + $base['fee'],
        'payer' => ['address' => '12 MG Road, Pune', 'pincode' => '411001'] + $base['payer']] + $base), '…and with them it passes');

    $staff = PaymentLinkInput::validate(['amount' => '11800.00', 'fee' => ['sac' => '999293', 'gst_rate' => '18', 'price_basis' => 'inclusive']]
        + array_diff_key($base, ['amount_paise' => 1, 'fee' => 1, 'source_ref' => 1]), '27', false, $now);
    same([1180000, 1000000, 90000, 90000, null, 'finance'], [$staff['amount_paise'], $staff['fee']['taxable_paise'], $staff['fee']['cgst_paise'], $staff['fee']['sgst_paise'],
        $staff['source_ref'], $staff['fee_breakdown']['computed_by']], 'staff form: Finance computes the split; the lead code is optional');
    same(['+919876543210', '+919876543210', '+919876543210', null, null], [PaymentLinkInput::mobile('9876543210'), PaymentLinkInput::mobile('919876543210'),
        PaymentLinkInput::mobile('09876543210'), PaymentLinkInput::mobile('5876543210'), PaymentLinkInput::mobile('+44 7700 900123')], 'mobile numbers');
});

test('Finance → Engage event: payment_ref is the raw gateway id, lead_code, provider, amount "11800.00", stable event_id; receipt check', function (): void {
    $link = ['id' => 7, 'link_id' => LINK_ID, 'source_ref' => 'LD-2026-000123', 'academy_ref' => 'EDGE-ADM-0042-F1', 'stream' => 'edge', 'product_code' => 'edge',
        'seller_code' => 'SLR-000001', 'fee_taxable_paise' => 1000000, 'fee_cgst_paise' => 90000, 'fee_sgst_paise' => 90000, 'fee_igst_paise' => 0, 'fee_sac' => '999293',
        'fee_gst_rate' => '18.00', 'fee_price_basis' => 'inclusive'];
    $pay = ['id' => 9, 'payment_ref' => 'fin_pay_01JAB7Q2Z9X4KX3J5C8V0M6N2Q', 'gateway_payment_ref' => 'pay_P1', 'provider' => 'razorpay', 'mode' => 'live',
        'amount_paise' => 1180000, 'method' => 'upi', 'paid_at' => '2026-10-23 14:30:00+05:30', 'refunded_paise' => 0];
    $e = EngagePaymentEventOutbox::payload('fin_evt_01JAB7Q2Z9X4KX3J5C8V0M6N2R', 'payment.succeeded', $link, $pay, null);
    same(['fin_evt_01JAB7Q2Z9X4KX3J5C8V0M6N2R', 'payment.succeeded', 'LD-2026-000123', 'pay_P1', 'razorpay', 'edge', '11800.00', 1180000, 'INR', '2026-10-23T14:30:00+05:30'],
        [$e['event_id'], $e['event_type'], $e['lead_code'], $e['payment_ref'], $e['provider'], $e['product_code'], $e['amount'], $e['amount_paise'], $e['currency'], $e['paid_at']],
        'the fields Engage confirms the lead payment with');
    check(!str_contains(json_encode($e), 'plink_') && !isset($e['payer']), 'no provider link id as payment_ref; no payer data');
    $big = EngagePaymentEventOutbox::payload('fin_evt_01JAB7Q2Z9X4KX3J5C8V0M6N2R', 'payment.succeeded', $link, ['amount_paise' => 123456789] + $pay, null);
    same('1234567.89', $big['amount'], 'two decimals, no grouping commas');
    $cf = EngagePaymentEventOutbox::payload('fin_evt_01JAB7Q2Z9X4KX3J5C8V0M6N2R', 'payment.succeeded', $link, ['provider' => 'cashfree', 'gateway_payment_ref' => '5100023'] + $pay, null);
    same(['cashfree', '5100023'], [$cf['provider'], $cf['payment_ref']], 'Cashfree: the bare cf_payment_id');
    $r = EngagePaymentEventOutbox::payload('fin_evt_01JAB7Q2Z9X4KX3J5C8V0M6N2S', 'payment.refunded', $link, ['refunded_paise' => 1180000] + $pay,
        ['provider_refund_id' => 'rfnd_1', 'amount_paise' => 1180000, 'refunded_at' => '2026-10-24 10:00:00+05:30']);
    same(['pay_P1', '11800.00', true], [$r['payment_ref'], $r['refund']['amount'], $r['refund']['fully_refunded']], 'refund event names the original payment');

    same(true, EngagePaymentEventOutbox::isEngageReceipt('{"success":true,"data":{"event_id":"fin_evt_X"}}', 'fin_evt_X'), 'AIC envelope receipt');
    same(true, EngagePaymentEventOutbox::isEngageReceipt('{"ok":true,"data":{"event_id":"fin_evt_X"}}', 'fin_evt_X'), 'Engage ok-envelope receipt');
    same(false, EngagePaymentEventOutbox::isEngageReceipt('<!doctype html>', 'fin_evt_X'), 'SPA fallback is not a receipt');
    same(false, EngagePaymentEventOutbox::isEngageReceipt('{"success":true,"data":{"event_id":"fin_evt_Y"}}', 'fin_evt_X'), 'another event\'s receipt');

    // The outbound signature is plain AIC-S2S v1: Engage's AicS2sSignature computes the same canonical string.
    $h = AicS2s::sign('finance-to-engage-s2s-secret-0123456789abcdef', 'finance', 'finance-issue-2026-10', 'production', 'POST', EngagePaymentEventOutbox::PATH, '',
        '{"event_id":"x"}', 1761200000, '3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37');
    same('v1=' . hash_hmac('sha256', "POST\n/v1/inbound/finance/payment-events\n\n1761200000\n3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37\n" . hash('sha256', '{"event_id":"x"}'),
        'finance-to-engage-s2s-secret-0123456789abcdef'), $h['X-AIC-Signature'], 'canonical: METHOD, path after /api, empty query, ts, nonce, sha256(body)');
});
