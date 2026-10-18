<?php
declare(strict_types=1);

/**
 * Minimal HS256 JWT implementation — no external libraries required.
 * Mirrors console-react-app's server-php/app/Helpers/JwtHelper.php so the two
 * codebases stay interchangeable.
 */

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder !== 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
}

/**
 * A blank secret makes every signature forgeable (anyone can compute the HMAC),
 * so Finance neither issues nor accepts tokens without one. Secrets shorter than
 * 32 characters still work but are logged: /health reports them as misconfigured.
 */
function jwt_secret_usable(string $secret): bool
{
    $secret = trim($secret);
    if ($secret === '') {
        return false;
    }
    if (strlen($secret) < 32) {
        error_log('[finance] JWT_SECRET is shorter than 32 characters: replace it with a long random value.');
    }

    return true;
}

/**
 * Create a signed JWT string.
 *
 * @param array<string,mixed> $payload
 */
function jwt_encode(array $payload, string $secret): string
{
    if (!jwt_secret_usable($secret)) {
        throw new \RuntimeException('JWT_SECRET is not set; Finance cannot sign tokens.');
    }

    $header = base64url_encode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT',
    ], JSON_THROW_ON_ERROR));

    $body = base64url_encode(json_encode($payload, JSON_THROW_ON_ERROR));

    $signature = base64url_encode(
        hash_hmac('sha256', "{$header}.{$body}", $secret, true)
    );

    return "{$header}.{$body}.{$signature}";
}

/**
 * Decode and verify a JWT. Returns the payload array or false on failure.
 *
 * @return array<string,mixed>|false
 */
function jwt_decode(string $token, string $secret): array|false
{
    if (!jwt_secret_usable($secret)) {
        return false;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    [$headerB64, $bodyB64, $signatureB64] = $parts;

    $expectedSig = base64url_encode(
        hash_hmac('sha256', "{$headerB64}.{$bodyB64}", $secret, true)
    );

    if (!hash_equals($expectedSig, $signatureB64)) {
        return false;
    }

    $payload = json_decode(base64url_decode($bodyB64), true);
    if (!is_array($payload)) {
        return false;
    }

    return $payload;
}
