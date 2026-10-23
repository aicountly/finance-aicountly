<?php
declare(strict_types=1);

/**
 * Stand-in for the Razorpay and Cashfree APIs, run under `php -S` by tests/academy-db.php. It implements only what
 * App\Services\AcademyFees\RazorpayAdapter / CashfreeAdapter call, in the shapes they assume (see their doc blocks) —
 * it proves Finance's side of the flow, not the providers' real behaviour (docs/ACADEMY_PAYMENTS.md § "Provider details
 * to verify").
 *
 * FAKE_GATEWAY_STATE (JSON) holds the credentials, links, payments, orders, refunds and injected failures. Test-only
 * control endpoints (POST /__control/…) let the test act as the student / the merchant dashboard:
 *   /__control/razorpay/pay {plink_id, method?}          a captured payment on the link (link → paid)
 *   /__control/razorpay/refund {payment_id, amount}      a processed refund
 *   /__control/razorpay/hide-payments {plink_id}         the link's answer omits `payments` (adapter fallback path)
 *   /__control/cashfree/pay {link_id}                    a PAID order + SUCCESS payment (cf_payment_id) on the link
 *   /__control/cashfree/refund {order_id, amount}
 *   /__control/fail {key: "POST /v1/payment_links", times, status}   the next `times` matching calls answer `status`
 * Every API request is appended to FAKE_GATEWAY_LOG (method, uri, auth ok?, body).
 */

if (PHP_SAPI !== 'cli-server' || (string) getenv('FAKE_GATEWAY_STATE') === '') {
    http_response_code(404);
    exit;
}

$stateFile = (string) getenv('FAKE_GATEWAY_STATE');
$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$state = json_decode((string) stream_get_contents($fp), true) ?: [];
$state += ['keys' => [], 'rzp' => ['links' => [], 'payments' => [], 'refunds' => []], 'cf' => ['links' => [], 'orders' => [], 'payments' => [], 'refunds' => []], 'fail' => [], 'seq' => 1000];

$method = $_SERVER['REQUEST_METHOD'];
$uri    = (string) $_SERVER['REQUEST_URI'];
$path   = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$raw    = (string) file_get_contents('php://input');
$in     = json_decode($raw, true);
$in     = is_array($in) ? $in : [];

$answer = static function (int $status, mixed $body) use (&$state, $fp): never {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};
$next = static function (string $prefix) use (&$state): string {
    $state['seq']++;

    return $prefix . $state['seq'];
};
$now = time();

// ---------------------------------------------------------------- control

if (str_starts_with($path, '/__control/')) {
    $c = substr($path, strlen('/__control/'));
    switch ($c) {
        case 'keys':
            $state['keys'] = $in;
            $answer(200, ['ok' => true]);
        case 'fail':
            $state['fail'][(string) $in['key']] = ['times' => (int) ($in['times'] ?? 1), 'status' => (int) ($in['status'] ?? 500)];
            $answer(200, ['ok' => true]);
        case 'razorpay/pay':
            $l = &$state['rzp']['links'][(string) $in['plink_id']];
            $pid = $next('pay_FAKE');
            $state['rzp']['payments'][$pid] = ['id' => $pid, 'entity' => 'payment', 'amount' => $l['amount'], 'currency' => 'INR', 'status' => 'captured',
                'method' => $in['method'] ?? 'upi', 'order_id' => $l['order_id'], 'created_at' => $now, 'email' => 'student@example.test', 'contact' => '+919876543210',
                'vpa' => 'student@okbank', 'notes' => $l['notes']];
            $l['payments'] ??= [];
            $l['payments'][] = ['amount' => $l['amount'], 'created_at' => $now, 'method' => $in['method'] ?? 'upi', 'payment_id' => $pid, 'plink_id' => $l['id'], 'status' => 'captured', 'updated_at' => $now];
            $l['amount_paid'] += $l['amount'];
            $l['status'] = 'paid';
            $answer(200, ['payment' => $state['rzp']['payments'][$pid], 'link' => $l]);
        case 'razorpay/hide-payments':
            $state['rzp']['links'][(string) $in['plink_id']]['hide_payments'] = true;
            $answer(200, ['ok' => true]);
        case 'razorpay/refund':
            $rid = $next('rfnd_FAKE');
            $state['rzp']['refunds'][$rid] = ['id' => $rid, 'entity' => 'refund', 'amount' => (int) $in['amount'], 'payment_id' => (string) $in['payment_id'],
                'status' => 'processed', 'created_at' => $now];
            $answer(200, $state['rzp']['refunds'][$rid]);
        case 'cashfree/pay':
            $l = &$state['cf']['links'][(string) $in['link_id']];
            $oid = $next('CFPay_FAKE_');
            $cfp = (string) (5_000_000 + $state['seq']);
            $state['cf']['orders'][$oid] = ['order_id' => $oid, 'cf_order_id' => (string) (9_000_000 + $state['seq']), 'order_amount' => $l['link_amount'],
                'order_status' => 'PAID', 'created_at' => date(DATE_ATOM, $now), 'link_id' => $l['link_id']];
            $state['cf']['payments'][$oid] = [['cf_payment_id' => (int) $cfp, 'payment_status' => 'SUCCESS', 'payment_amount' => $l['link_amount'],
                'payment_time' => date(DATE_ATOM, $now), 'payment_group' => 'upi', 'payment_method' => ['upi' => ['upi_id' => 'student@okbank']]]];
            $l['link_amount_paid'] += $l['link_amount'];
            $l['link_status'] = 'PAID';
            $answer(200, ['order' => $state['cf']['orders'][$oid], 'cf_payment_id' => $cfp, 'link' => $l]);
        case 'cashfree/refund':
            $rid = $next('refund_FAKE_');
            $state['cf']['refunds'][$rid] = ['cf_refund_id' => $next(''), 'refund_id' => $rid, 'order_id' => (string) $in['order_id'],
                'refund_amount' => $in['amount'], 'refund_status' => 'SUCCESS', 'processed_at' => date(DATE_ATOM, $now)];
            $answer(200, $state['cf']['refunds'][$rid]);
    }
    $answer(404, ['error' => 'unknown control']);
}

// ---------------------------------------------------------------- shared

$isCf = str_starts_with($path, '/pg/');
$auth = $isCf
    ? (($_SERVER['HTTP_X_CLIENT_ID'] ?? '') . ':' . ($_SERVER['HTTP_X_CLIENT_SECRET'] ?? '')) === ($state['keys']['cashfree'] ?? '-')
        && ($_SERVER['HTTP_X_API_VERSION'] ?? '') !== ''
    : ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Basic ' . base64_encode((string) ($state['keys']['razorpay'] ?? '-'));
file_put_contents((string) getenv('FAKE_GATEWAY_LOG'), json_encode([
    'method' => $method, 'uri' => $uri, 'auth_ok' => $auth, 'api_version' => $_SERVER['HTTP_X_API_VERSION'] ?? null, 'body' => $in === [] ? null : $in,
]) . "\n", FILE_APPEND | LOCK_EX);

foreach ($state['fail'] as $key => $f) {
    if ($f['times'] > 0 && str_starts_with($method . ' ' . $path, $key)) {
        $state['fail'][$key]['times']--;
        $answer($f['status'], $isCf ? ['message' => 'injected failure', 'code' => 'internal'] : ['error' => ['code' => 'SERVER_ERROR', 'description' => 'injected failure']]);
    }
}
if (!$auth) {
    $answer(401, $isCf ? ['message' => 'authentication Failed', 'code' => 'request_failed', 'type' => 'authentication_error']
        : ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed']]);
}

// ---------------------------------------------------------------- Razorpay

$rzpLink = static fn (array $l): array => isset($l['hide_payments']) ? array_diff_key($l, ['payments' => 1, 'hide_payments' => 1]) + ['payments' => null] : $l;

if (!$isCf) {
    if ($method === 'GET' && $path === '/v1/payments') {
        $answer(200, ['entity' => 'collection', 'count' => 0, 'items' => []]);
    }
    if ($method === 'POST' && $path === '/v1/payment_links') {
        foreach ($state['rzp']['links'] as $l) {
            if ($l['reference_id'] === ($in['reference_id'] ?? null)) {
                $answer(400, ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'reference_id already exists']]);
            }
        }
        if (!is_int($in['amount'] ?? null) || ($in['currency'] ?? '') !== 'INR' || !isset($in['customer']['contact'])) {
            $answer(400, ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'invalid request']]);
        }
        $id = $next('plink_FAKE');
        $state['rzp']['links'][$id] = ['id' => $id, 'short_url' => 'https://rzp.test/i/' . $id, 'status' => 'created', 'amount' => $in['amount'], 'amount_paid' => 0,
            'reference_id' => $in['reference_id'], 'description' => $in['description'], 'expire_by' => $in['expire_by'], 'notes' => $in['notes'] ?? [],
            'customer' => $in['customer'], 'order_id' => $next('order_FAKE'), 'payments' => null];
        $answer(200, $state['rzp']['links'][$id]);
    }
    if ($method === 'GET' && $path === '/v1/payment_links') {
        $rows = array_values(array_filter($state['rzp']['links'], static fn (array $l) => $l['reference_id'] === ($query['reference_id'] ?? '')));
        $answer(200, ['payment_links' => array_map($rzpLink, $rows)]);
    }
    if (preg_match('#^/v1/payment_links/([^/]+)(/cancel)?$#', $path, $m)) {
        $l = $state['rzp']['links'][$m[1]] ?? null;
        if ($l === null) {
            $answer(404, ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The id provided does not exist']]);
        }
        if (($m[2] ?? '') === '/cancel' && $method === 'POST') {
            if ($l['status'] === 'paid') {
                $answer(400, ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Payment link cannot be cancelled as it is already paid']]);
            }
            $state['rzp']['links'][$m[1]]['status'] = 'cancelled';
            $l = $state['rzp']['links'][$m[1]];
        }
        $answer(200, $rzpLink($l));
    }
    if ($method === 'GET' && preg_match('#^/v1/payments/([^/]+)$#', $path, $m)) {
        $p = $state['rzp']['payments'][$m[1]] ?? null;
        $answer($p ? 200 : 404, $p ?? ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The id provided does not exist']]);
    }
    if ($method === 'GET' && preg_match('#^/v1/refunds/([^/]+)$#', $path, $m)) {
        $r = $state['rzp']['refunds'][$m[1]] ?? null;
        $answer($r ? 200 : 404, $r ?? ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The id provided does not exist']]);
    }
    $answer(404, ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'route not in the fake']]);
}

// ---------------------------------------------------------------- Cashfree

if ($method === 'POST' && $path === '/pg/links') {
    if (isset($state['cf']['links'][$in['link_id'] ?? ''])) {
        $answer(409, ['message' => 'link_id already exists', 'code' => 'link_post_failed', 'type' => 'invalid_request_error']);
    }
    if (!preg_match('/^\d{10}$/', (string) ($in['customer_details']['customer_phone'] ?? '')) || !is_numeric($in['link_amount'] ?? null)) {
        $answer(400, ['message' => 'invalid request', 'code' => 'request_invalid', 'type' => 'invalid_request_error']);
    }
    $state['cf']['links'][$in['link_id']] = ['cf_link_id' => (int) substr($next(''), 0), 'link_id' => $in['link_id'], 'link_url' => 'https://payments.cashfree.test/links/' . $in['link_id'],
        'link_status' => 'ACTIVE', 'link_amount' => $in['link_amount'], 'link_amount_paid' => 0, 'link_currency' => 'INR', 'link_purpose' => $in['link_purpose'],
        'link_expiry_time' => $in['link_expiry_time'], 'link_notes' => $in['link_notes'] ?? [], 'link_meta' => $in['link_meta'] ?? [], 'customer_details' => $in['customer_details']];
    $answer(200, $state['cf']['links'][$in['link_id']]);
}
if (preg_match('#^/pg/links/([^/]+)(/cancel|/orders)?$#', $path, $m)) {
    $l = $state['cf']['links'][$m[1]] ?? null;
    if ($l === null) {
        $answer(404, ['message' => 'link does not exist', 'code' => 'link_not_found', 'type' => 'invalid_request_error']);
    }
    if (($m[2] ?? '') === '/cancel' && $method === 'POST') {
        if ($l['link_status'] === 'PAID') {
            $answer(400, ['message' => 'link already paid', 'code' => 'link_cancel_failed', 'type' => 'invalid_request_error']);
        }
        $state['cf']['links'][$m[1]]['link_status'] = 'CANCELLED';
        $answer(200, $state['cf']['links'][$m[1]]);
    }
    if (($m[2] ?? '') === '/orders') {
        $answer(200, array_values(array_filter($state['cf']['orders'], static fn (array $o) => $o['link_id'] === $m[1])));
    }
    $answer(200, $l);
}
if ($method === 'GET' && preg_match('#^/pg/orders/([^/]+)/payments$#', $path, $m)) {
    $answer(200, $state['cf']['payments'][$m[1]] ?? []);
}
if ($method === 'GET' && preg_match('#^/pg/orders/([^/]+)/refunds/([^/]+)$#', $path, $m)) {
    $r = $state['cf']['refunds'][$m[2]] ?? null;
    $answer($r && $r['order_id'] === $m[1] ? 200 : 404, $r ?? ['message' => 'refund not found', 'code' => 'refund_not_found']);
}
$answer(404, ['message' => 'route not in the fake', 'code' => 'not_found']);
