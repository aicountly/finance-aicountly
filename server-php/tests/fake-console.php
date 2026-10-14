<?php
declare(strict_types=1);

/**
 * Stand-in for console.aicountly.org/api, run under `php -S` by tests/run.php.
 *
 * The test writes the answers it wants into FAKE_CONSOLE_STATE (JSON:
 * {"exchange": {"status":200,"body":{...}}, "introspect": {...}, "user_access": {...}})
 * and reads every request this server received from FAKE_CONSOLE_LOG.
 */

if (PHP_SAPI !== 'cli-server' || (string) getenv('FAKE_CONSOLE_STATE') === '') {
    http_response_code(404);
    exit;
}

$stateFile = (string) getenv('FAKE_CONSOLE_STATE');
$logFile   = (string) getenv('FAKE_CONSOLE_LOG');
$state     = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$key  = match ($path) {
    '/api/auth/sso/exchange'                     => 'exchange',
    '/api/auth/introspect'                       => 'introspect',
    '/api/portal/controller-apps/user-access'    => 'user_access',
    default                                      => null,
};

file_put_contents($logFile, json_encode([
    'key'           => $key,
    'method'        => $_SERVER['REQUEST_METHOD'],
    'uri'           => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'body'          => file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);

$answer = $key !== null ? ($state[$key] ?? null) : null;
if ($answer === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'not stubbed: ' . $path]);
    return true;
}

http_response_code((int) ($answer['status'] ?? 200));
header('Content-Type: application/json');
echo is_string($answer['body']) ? $answer['body'] : json_encode($answer['body']);
return true;
