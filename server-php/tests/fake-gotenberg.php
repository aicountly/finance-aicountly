<?php
declare(strict_types=1);

/**
 * Stand-in for a Gotenberg PDF service, run under `php -S` by tests/invoicing-db.php.
 *
 * POST /forms/chromium/convert/html (multipart, file index.html) → 200 application/pdf whose bytes name the sha256 of
 * the HTML it was given, so a test can prove the stored PDF was rendered from the stored HTML. FAKE_GOTENBERG_STATE
 * (JSON) {"statuses": [503, …]} makes the next requests fail with those statuses. Requests go to FAKE_GOTENBERG_LOG.
 */

if (PHP_SAPI !== 'cli-server' || (string) getenv('FAKE_GOTENBERG_STATE') === '') {
    http_response_code(404);
    exit;
}

$stateFile = (string) getenv('FAKE_GOTENBERG_STATE');
$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$state    = json_decode((string) stream_get_contents($fp), true) ?: [];
$statuses = $state['statuses'] ?? [];
$next     = $statuses === [] ? 200 : (int) array_shift($statuses);
$state['statuses'] = $statuses;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($state));
flock($fp, LOCK_UN);
fclose($fp);

$html = isset($_FILES['files']['tmp_name']) ? (string) file_get_contents(is_array($_FILES['files']['tmp_name']) ? $_FILES['files']['tmp_name'][0] : $_FILES['files']['tmp_name']) : '';
file_put_contents((string) getenv('FAKE_GOTENBERG_LOG'), json_encode([
    'uri'      => $_SERVER['REQUEST_URI'],
    'filename' => $_SERVER['HTTP_GOTENBERG_OUTPUT_FILENAME'] ?? '',
    'html_sha' => hash('sha256', $html),
    'fields'   => array_keys($_POST),
    'answered' => $next,
]) . "\n", FILE_APPEND);

if ($_SERVER['REQUEST_URI'] !== '/forms/chromium/convert/html' || $html === '') {
    http_response_code(400);
    echo 'bad request';
    exit;
}
if ($next !== 200) {
    http_response_code($next);
    echo 'unavailable';
    exit;
}
header('Content-Type: application/pdf');
echo "%PDF-1.7\n% fake gotenberg\n% html-sha256: " . hash('sha256', $html) . "\n%%EOF\n";
