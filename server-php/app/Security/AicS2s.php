<?php
declare(strict_types=1);

namespace App\Security;

/**
 * AIC-S2S v1 — the signed server-to-server scheme shared by Finance, Engage and Manage (commerce contract §0.1).
 * Pure: no I/O. Secrets, scopes, nonce replay and rate limits are supplied by the caller (Filters/S2sFilter.php).
 *
 *   X-AIC-Client       manage | engage                   (selects the secret)
 *   X-AIC-Key-Id       e.g. manage-read-2026-10           (rotation: two ids may be live at once)
 *   X-AIC-Timestamp    unix seconds                      (refused if |now − ts| > 300)
 *   X-AIC-Nonce        uuid v4                           (refused if seen in the last 600 s for this key id)
 *   X-AIC-Environment  production | sandbox | local      (must match the receiver)
 *   X-AIC-Signature    v1=<hex HMAC-SHA256(secret, canonical)>
 *   X-AIC-Actor        my.aicountly uuid | service       (audit only, never authorisation)
 *   X-AIC-Request-Id   echoed back as request_id
 *
 *   canonical = METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" hex(sha256(raw body or ""))
 *     PATH            the path after /api, e.g. /v1/inbound/manage/documents
 *     CANONICAL_QUERY pairs sorted by key (then value), each part RFC 3986 encoded, k=v joined by &
 *
 * Secrets (≥ 32 bytes) live in the receiver's env, one per caller and key id:
 *   FINANCE_INBOUND_<CLIENT>_SECRET_<KEYID>   KEYID upper-cased, every character other than A-Z / 0-9 → "_"
 *   FINANCE_INBOUND_<CLIENT>_SCOPE_<KEYID>    read (default) | issue — a read key may call only read (GET) routes,
 *                                             an issue key only issue routes
 */
final class AicS2s
{
    public const VERSION       = 'v1';
    public const MAX_SKEW      = 300;
    public const NONCE_TTL     = 600;
    public const MIN_SECRET    = 32;
    public const CLIENTS       = ['manage', 'engage'];
    public const ENVIRONMENTS  = ['production', 'sandbox', 'local'];
    public const SCOPES        = ['read', 'issue'];

    public static function canonicalQuery(string $rawQuery): string
    {
        $pairs = [];
        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $k  = rawurldecode(str_replace('+', ' ', $eq === false ? $part : substr($part, 0, $eq)));
            $v  = $eq === false ? '' : rawurldecode(str_replace('+', ' ', substr($part, $eq + 1)));
            $pairs[] = [rawurlencode($k), rawurlencode($v)];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
    }

    public static function canonical(string $method, string $path, string $rawQuery, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . self::canonicalQuery($rawQuery) . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public static function signature(string $secret, string $canonical): string
    {
        return self::VERSION . '=' . hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Headers for an outgoing signed request (Finance as a caller, and the tests).
     *
     * @return array<string,string>
     */
    public static function sign(string $secret, string $client, string $keyId, string $environment, string $method, string $path, string $rawQuery = '',
        string $body = '', ?int $timestamp = null, ?string $nonce = null, string $actor = 'service', ?string $requestId = null): array
    {
        $ts    = (string) ($timestamp ?? time());
        $nonce ??= self::uuid4();

        return [
            'X-AIC-Client'      => $client,
            'X-AIC-Key-Id'      => $keyId,
            'X-AIC-Timestamp'   => $ts,
            'X-AIC-Nonce'       => $nonce,
            'X-AIC-Environment' => $environment,
            'X-AIC-Signature'   => self::signature($secret, self::canonical($method, $path, $rawQuery, $ts, $nonce, $body)),
            'X-AIC-Actor'       => $actor,
            'X-AIC-Request-Id'  => $requestId ?? self::uuid4(),
        ];
    }

    public static function envSuffix(string $keyId): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '_', strtoupper($keyId));
    }

    public static function secretEnvName(string $client, string $keyId): string
    {
        return 'FINANCE_INBOUND_' . strtoupper($client) . '_SECRET_' . self::envSuffix($keyId);
    }

    public static function scopeEnvName(string $client, string $keyId): string
    {
        return 'FINANCE_INBOUND_' . strtoupper($client) . '_SCOPE_' . self::envSuffix($keyId);
    }

    /**
     * Verify one request. Checks run in a fixed order so a caller learns nothing from an unsigned probe beyond
     * "missing / invalid": signing headers → environment → known key → timestamp → signature (constant time) → scope.
     * Nonce replay and rate limits come after this (they need storage).
     *
     * @param array<string,string> $headers lower-case header name => value
     * @param array{scope:string, clients:list<string>} $route
     * @param callable(string $client, string $keyId):?string $secretFor ≥ 32-byte secret, or null when unknown
     * @param callable(string $client, string $keyId):string $scopeFor  read | issue
     * @return array{ok:bool, status:int, code:string, message:string, client?:string, key_id?:string, scope?:string, actor?:string, nonce?:string}
     */
    public static function verify(array $headers, string $method, string $path, string $rawQuery, string $body, array $route, callable $secretFor,
        callable $scopeFor, string $environment, int $now): array
    {
        $h = static fn (string $name): string => trim((string) ($headers[$name] ?? ''));

        foreach (['x-aic-client', 'x-aic-key-id', 'x-aic-timestamp', 'x-aic-nonce', 'x-aic-signature'] as $required) {
            if ($h($required) === '') {
                return self::fail(401, 'signature_missing', 'The request is not signed (AIC-S2S v1 headers missing).');
            }
        }
        $env = $h('x-aic-environment');
        if ($env === '') {
            return self::fail(400, 'environment_required', 'X-AIC-Environment is required.');
        }
        if (!hash_equals($environment, $env)) {
            return self::fail(403, 'environment_mismatch', 'This receiver is not the "' . (in_array($env, self::ENVIRONMENTS, true) ? $env : 'requested') . '" environment.');
        }
        $client = strtolower($h('x-aic-client'));
        $keyId  = $h('x-aic-key-id');
        if (!in_array($client, $route['clients'], true) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId)) {
            return self::fail(401, 'unknown_key', 'Unknown client or key id.');
        }
        $secret = $secretFor($client, $keyId);
        if ($secret === null) {
            return self::fail(401, 'unknown_key', 'Unknown client or key id.');
        }
        $ts = $h('x-aic-timestamp');
        if (!ctype_digit($ts) || abs($now - (int) $ts) > self::MAX_SKEW) {
            return self::fail(401, 'timestamp_stale', 'X-AIC-Timestamp is more than 300 seconds from the receiver\'s clock.');
        }
        $nonce = $h('x-aic-nonce');
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $nonce)) {
            return self::fail(401, 'signature_invalid', 'X-AIC-Nonce must be a uuid.');
        }
        $expected = self::signature($secret, self::canonical($method, $path, $rawQuery, $ts, $nonce, $body));
        if (!hash_equals($expected, strtolower($h('x-aic-signature')))) {
            return self::fail(401, 'signature_invalid', 'The signature does not match.');
        }
        $scope = $scopeFor($client, $keyId);
        if ($scope !== $route['scope']) {
            return self::fail(403, 'route_not_allowed_for_key', "Key {$keyId} ({$scope}) may not call this {$route['scope']} route.");
        }

        return [
            'ok' => true, 'status' => 200, 'code' => 'ok', 'message' => 'ok', 'client' => $client, 'key_id' => $keyId, 'scope' => $scope,
            'actor' => mb_substr($h('x-aic-actor') ?: 'service', 0, 80), 'nonce' => $nonce,
        ];
    }

    public static function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** @return array{ok:false, status:int, code:string, message:string} */
    private static function fail(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
