<?php
declare(strict_types=1);

/**
 * The AIC-S2S response envelope (commerce contract §0.2) — used only by the signed server-to-server routes
 * (v1/inbound/manage/…). Staff routes keep ResponseHelper's {success, message, data, errors}.
 *
 *   success  {"success":true,"data":…,"page":{"next_cursor":…,"limit":25},"request_id":"…"}   (page only on lists)
 *   error    {"success":false,"error":{"code","message","details","retryable"},"request_id":"…"}
 */

function s2s_request_id(): string
{
    if (class_exists('S2sFilter', false) && S2sFilter::$requestId !== '') {
        return S2sFilter::$requestId;
    }
    $given = isset($_SERVER['HTTP_X_AIC_REQUEST_ID']) && is_string($_SERVER['HTTP_X_AIC_REQUEST_ID']) ? trim($_SERVER['HTTP_X_AIC_REQUEST_ID']) : '';

    return preg_match('/^[A-Za-z0-9-]{8,64}$/', $given) ? $given : \App\Security\AicS2s::uuid4();
}

/** @param array{next_cursor:?string, limit:int}|null $page */
function s2s_success(mixed $data, ?array $page = null, int $status = 200): never
{
    $out = ['success' => true, 'data' => $data];
    if ($page !== null) {
        $out['page'] = $page;
    }
    $out['request_id'] = s2s_request_id();
    s2s_send($status, $out);
}

/**
 * @param array<string,mixed> $details
 * @param array<string,string> $headers
 */
function s2s_error(int $status, string $code, string $message, array $details = [], ?bool $retryable = null, array $headers = []): never
{
    foreach ($headers as $k => $v) {
        header("{$k}: {$v}");
    }
    s2s_send($status, [
        'success'    => false,
        'error'      => [
            'code'      => $code,
            'message'   => $message,
            'details'   => (object) $details,
            'retryable' => $retryable ?? in_array($status, [429, 503], true),
        ],
        'request_id' => s2s_request_id(),
    ]);
}

/** @param array<string,mixed> $payload */
function s2s_send(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-AIC-Request-Id: ' . s2s_request_id());
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
