<?php
declare(strict_types=1);

/**
 * Stand-in for engage.aicountly.org/api, run under `php -S` by tests/payouts-db.php.
 *
 * FAKE_ENGAGE_STATE (JSON): {"statuses": [500, 200, "spa", ...]} — each request pops the next status
 * (default 200 when the list is empty). A 2xx answers like Engage does ({ok:true, data:{item_ref, status,
 * result}}); "spa" answers 200 text/html like engage's web app fallback (a base URL without /api).
 * Every request is appended to FAKE_ENGAGE_LOG.
 */

if (PHP_SAPI !== 'cli-server' || (string) getenv('FAKE_ENGAGE_STATE') === '') {
    http_response_code(404);
    exit;
}

$stateFile = (string) getenv('FAKE_ENGAGE_STATE');
$logFile   = (string) getenv('FAKE_ENGAGE_LOG');

$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$state = json_decode((string) stream_get_contents($fp), true) ?: [];
$statuses = $state['statuses'] ?? [];
$next = $statuses === [] ? 200 : array_shift($statuses);
$spa = $next === 'spa';
$status = $spa ? 200 : (int) $next;
$state['statuses'] = $statuses;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($state));
flock($fp, LOCK_UN);
fclose($fp);

$body = json_decode((string) file_get_contents('php://input'), true);
file_put_contents($logFile, json_encode([
    'method'          => $_SERVER['REQUEST_METHOD'],
    'uri'             => $_SERVER['REQUEST_URI'],
    'finance_key'     => $_SERVER['HTTP_X_FINANCE_KEY'] ?? '',
    'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
    'content_type'    => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
    'body'            => $body,
    'answered'        => $spa ? 'spa' : $status,
]) . "\n", FILE_APPEND | LOCK_EX);

http_response_code($status);
if ($spa) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body><div id="root"></div></body></html>';

    return true;
}
header('Content-Type: application/json');
echo json_encode($status < 300
    ? ['ok' => true, 'data' => ['item_ref' => $body['item_ref'] ?? null, 'status' => $body['status'] ?? null, 'result' => 'updated']]
    : ['ok' => false, 'error' => 'fake Engage answered ' . $status]);
return true;
