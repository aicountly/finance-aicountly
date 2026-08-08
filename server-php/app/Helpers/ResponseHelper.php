<?php
declare(strict_types=1);

/**
 * Standardised JSON response helpers.
 *
 * Every response follows the shape:
 * { "success": bool, "message": string, "data": mixed, "errors": array }
 */

function json_success(mixed $data = null, string $message = 'Success', int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
        'errors'  => [],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function json_error(string $message = 'Error', array $errors = [], int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data'    => null,
        'errors'  => $errors,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function json_unauthorized(string $message = 'Unauthorized'): never
{
    json_error($message, [], 401);
}

function json_forbidden(string $message = 'Forbidden'): never
{
    json_error($message, [], 403);
}

function json_not_found(string $message = 'Not found'): never
{
    json_error($message, [], 404);
}

/**
 * Map an exception to a valid HTTP status code (never pass SQLSTATE strings).
 */
function exception_http_status(\Throwable $e): int
{
    $code = $e->getCode();

    if (is_int($code) && $code >= 400 && $code < 600) {
        return $code;
    }

    if (is_string($code) && ctype_digit($code)) {
        $intCode = (int) $code;
        if ($intCode >= 400 && $intCode < 600) {
            return $intCode;
        }
    }

    return 500;
}

/**
 * User-facing message for uncaught exceptions (hide internals in production).
 */
function exception_public_message(\Throwable $e, string $appEnv): string
{
    if ($appEnv === 'production') {
        return 'Internal server error.';
    }

    return $e->getMessage();
}
