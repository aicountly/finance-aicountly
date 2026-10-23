<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

/**
 * The one place provider HTTP calls leave Finance (curl). Injectable so the adapters are tested against fixtures:
 *
 *   callable(string $method, string $url, array<string,string> $headers, ?string $body, int $timeout):
 *       array{status:int, body:?string, error:?string}
 *
 * Nothing here logs: headers carry the provider credentials.
 */
final class GatewayHttp
{
    /** @var callable */
    private $transport;

    public function __construct(?callable $transport = null, private int $timeout = 15)
    {
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /** A copy with a shorter timeout (the webhook answers the provider within a few seconds). */
    public function withTimeout(int $seconds): self
    {
        $copy = clone $this;
        $copy->timeout = max(1, $seconds);

        return $copy;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:?string, error:?string, json:mixed}
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $res = ($this->transport)($method, $url, $headers, $body, $this->timeout);
        $res += ['status' => 0, 'body' => null, 'error' => null];
        $res['json'] = is_string($res['body']) && $res['body'] !== '' ? json_decode($res['body'], true) : null;

        return $res;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:?string, error:?string}
     */
    public static function curl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = $answer === false ? curl_error($ch) : null;
        curl_close($ch);

        return ['status' => $answer === false ? 0 : $status, 'body' => is_string($answer) ? $answer : null, 'error' => $error];
    }
}
