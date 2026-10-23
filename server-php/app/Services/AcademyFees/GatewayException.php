<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

/**
 * A provider call that did not succeed. The message is safe to store and show (never a secret, never the request
 * headers): the provider's own error text, truncated. retryable = network trouble, 429 or 5xx (try again later);
 * not retryable = the provider refused the request (4xx: bad credentials, invalid data).
 */
final class GatewayException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 0, private bool $retryable = true)
    {
        parent::__construct(mb_substr($message, 0, 300));
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /** From an HTTP answer that was not 2xx. */
    public static function fromResponse(string $provider, array $res, string $what): self
    {
        $status = (int) ($res['status'] ?? 0);
        if ($status === 0) {
            return new self("{$provider}: {$what} failed — no answer (" . ($res['error'] ?? 'network error') . ').', 0, true);
        }
        $json   = is_string($res['body'] ?? null) ? json_decode((string) $res['body'], true) : null;
        $detail = '';
        if (is_array($json)) {
            // Razorpay {"error":{"code","description"}} · Cashfree {"message","code","type"}
            $detail = (string) ($json['error']['description'] ?? $json['message'] ?? $json['error']['code'] ?? $json['code'] ?? '');
        }
        $retry = $status === 429 || $status >= 500;
        $hint  = $status === 401 || $status === 403 ? ' — check the key id and secret in Settings → Payment gateways' : '';

        return new self("{$provider}: {$what} refused (HTTP {$status})" . ($detail !== '' ? ': ' . $detail : '') . $hint . '.', $status, $retry);
    }
}
