<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

/**
 * Razorpay Payment Links (API v1). What this adapter assumes — verify against https://razorpay.com/docs/api/payments/payment-links/
 * before go-live (docs/ACADEMY_PAYMENTS.md § "Provider details to verify"):
 *
 *   auth        HTTP Basic key_id:key_secret on https://api.razorpay.com
 *   create      POST /v1/payment_links {amount (paise), currency "INR", accept_partial false, reference_id (≤ 40 chars,
 *               unique per account: our fin_pl_… id), description, expire_by (unix s), customer {name, contact, email},
 *               notify {sms, email}, reminder_enable, notes {≤ 15 keys}, callback_url?, callback_method "get"}
 *               → {id "plink_…", short_url, status, amount_paid, payments|null, order_id?}
 *   statuses    created | partially_paid | paid | expired | cancelled
 *   fetch       GET /v1/payment_links/{id}   (payments: [{payment_id, amount, method, status, created_at}] once paid)
 *   find        GET /v1/payment_links?reference_id={ours} → {payment_links: […]}
 *   cancel      POST /v1/payment_links/{id}/cancel
 *   payment     GET /v1/payments/{id} → {id, amount, status "captured", method, created_at, order_id, notes}
 *   refund      GET /v1/refunds/{id} → {id, payment_id, amount, status "processed", created_at}
 *   auth check  GET /v1/payments?count=1 (200 = the key works, 401 = it does not)
 *   webhook     X-Razorpay-Signature = hex(HMAC-SHA256(raw body, webhook secret)); X-Razorpay-Event-Id = event id.
 *               Events used: payment_link.paid, payment_link.partially_paid, payment_link.expired, payment_link.cancelled,
 *               payment.captured (mapped through notes.fin_link_id only), refund.processed.
 */
final class RazorpayAdapter implements GatewayAdapter
{
    public const DEFAULT_BASE = 'https://api.razorpay.com';

    private string $base;

    public function __construct(private string $keyId, private string $keySecret, private GatewayHttp $http, ?string $baseUrl = null)
    {
        $this->base = rtrim($baseUrl ?? (finance_env('FINANCE_RAZORPAY_API_BASE') ?: self::DEFAULT_BASE), '/');
    }

    public function provider(): string
    {
        return 'razorpay';
    }

    public function testConnection(): array
    {
        try {
            $res = $this->call('GET', '/v1/payments?count=1', null);
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($res['status'] === 200) {
            return ['ok' => true, 'message' => 'Razorpay accepted the key (' . (str_starts_with($this->keyId, 'rzp_live_') ? 'live' : 'test') . ' key id).'];
        }

        return ['ok' => false, 'message' => GatewayException::fromResponse('Razorpay', $res, 'the auth check')->getMessage()];
    }

    public function createLink(array $link): array
    {
        $customer = ['name' => $link['payer']['name'], 'contact' => $link['payer']['mobile']];
        if (!empty($link['payer']['email'])) {
            $customer['email'] = $link['payer']['email'];
        }
        $body = [
            'amount'          => $link['amount_paise'],
            'currency'        => 'INR',
            'accept_partial'  => false,
            'reference_id'    => $link['link_id'],
            'description'     => $link['description'],
            'expire_by'       => $link['expire_at'],
            'customer'        => $customer,
            'notify'          => ['sms' => $link['notify_sms'], 'email' => $link['notify_email']],
            'reminder_enable' => false,
            'notes'           => (object) array_slice($link['notes'], 0, 15, true),
        ];
        if (!empty($link['return_url'])) {
            $body['callback_url']    = $link['return_url'];
            $body['callback_method'] = 'get';
        }
        $res = $this->call('POST', '/v1/payment_links', $body);
        if ($res['status'] !== 200 || !is_array($res['json']) || !isset($res['json']['id'])) {
            throw GatewayException::fromResponse('Razorpay', $res, 'creating the payment link');
        }

        return self::link($res['json']);
    }

    public function findLink(string $ourLinkId): ?array
    {
        $res = $this->call('GET', '/v1/payment_links?reference_id=' . rawurlencode($ourLinkId), null);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Razorpay', $res, 'looking up the payment link');
        }
        foreach ((array) ($res['json']['payment_links'] ?? $res['json']['items'] ?? []) as $row) {
            if (is_array($row) && ($row['reference_id'] ?? null) === $ourLinkId) {
                return self::link($row);
            }
        }

        return null;
    }

    public function fetchLink(string $providerLinkId, string $ourLinkId): array
    {
        $res = $this->call('GET', '/v1/payment_links/' . rawurlencode($providerLinkId), null);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Razorpay', $res, 'reading the payment link');
        }
        if (($res['json']['reference_id'] ?? $ourLinkId) !== $ourLinkId) {
            throw new GatewayException("Razorpay: payment link {$providerLinkId} does not belong to {$ourLinkId}.", 200, false);
        }

        return self::link($res['json']);
    }

    public function cancelLink(string $providerLinkId, string $ourLinkId): array
    {
        $res = $this->call('POST', '/v1/payment_links/' . rawurlencode($providerLinkId) . '/cancel', null);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Razorpay', $res, 'cancelling the payment link');
        }

        return self::link($res['json']);
    }

    public function confirmPayment(string $providerLinkId, string $ourLinkId, string $providerPaymentId): ?array
    {
        $link = $this->fetchLink($providerLinkId, $ourLinkId);
        if (is_array($link['payments'])) {
            foreach ($link['payments'] as $p) {
                if ($p['provider_payment_id'] === $providerPaymentId) {
                    return $p['captured'] ? $p : null;
                }
            }

            return null;
        }
        // The link's answer did not list its payments: read the payment itself and tie it to this link through the
        // order the link created, or the note Finance put on the link.
        $res = $this->call('GET', '/v1/payments/' . rawurlencode($providerPaymentId), null);
        if ($res['status'] === 404) {
            return null;
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Razorpay', $res, 'reading the payment');
        }
        $p       = $res['json'];
        $ours    = ($p['notes']['fin_link_id'] ?? null) === $ourLinkId
            || (!empty($link['order_id']) && ($p['order_id'] ?? null) === $link['order_id']);
        if (!$ours || ($p['status'] ?? null) !== 'captured') {
            return null;
        }

        return self::payment($p + ['payment_id' => $p['id'] ?? null]);
    }

    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ?array
    {
        $res = $this->call('GET', '/v1/refunds/' . rawurlencode($providerRefundId), null);
        if ($res['status'] === 404) {
            return null;
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Razorpay', $res, 'reading the refund');
        }
        $r = self::refund($res['json']);

        return $r['provider_payment_id'] === $providerPaymentId ? $r : null;
    }

    // ------------------------------------------------------------------ webhooks (pure)

    public static function verifySignature(array $headers, string $rawBody, string $secret): bool
    {
        $given = strtolower(trim((string) ($headers['x-razorpay-signature'] ?? '')));
        if ($secret === '' || !preg_match('/^[0-9a-f]{64}$/', $given)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $given);
    }

    public static function fingerprint(string $rawBody, array $headers): string
    {
        $id = trim((string) ($headers['x-razorpay-event-id'] ?? ''));

        return preg_match('/^[A-Za-z0-9_-]{6,64}$/', $id) ? 'razorpay:evt:' . $id : 'razorpay:sha256:' . hash('sha256', $rawBody);
    }

    public static function parseWebhook(string $rawBody, array $headers): array
    {
        $j     = json_decode($rawBody, true);
        $event = is_array($j) && is_string($j['event'] ?? null) ? $j['event'] : '';
        $out   = ['kind' => 'ignored', 'event_type' => $event ?: 'unknown', 'event_id' => trim((string) ($headers['x-razorpay-event-id'] ?? '')) ?: null,
            'reference' => null, 'provider_link_id' => null, 'link_status' => null, 'link_amount_paid_paise' => null, 'payment' => null, 'refund' => null, 'note' => null];
        if (!is_array($j)) {
            return ['note' => 'Body is not JSON.'] + $out;
        }
        $plink   = $j['payload']['payment_link']['entity'] ?? null;
        $payment = $j['payload']['payment']['entity'] ?? null;
        $refund  = $j['payload']['refund']['entity'] ?? null;
        if (is_array($plink)) {
            $out['provider_link_id']       = is_string($plink['id'] ?? null) ? $plink['id'] : null;
            $out['reference']              = self::ourId($plink['reference_id'] ?? null) ?? self::ourId($plink['notes']['fin_link_id'] ?? null);
            $out['link_status']            = self::status((string) ($plink['status'] ?? ''));
            $out['link_amount_paid_paise'] = is_int($plink['amount_paid'] ?? null) ? $plink['amount_paid'] : null;
        }
        if (is_array($payment)) {
            $out['reference'] ??= self::ourId($payment['notes']['fin_link_id'] ?? null);
            $out['payment'] = self::payment($payment + ['payment_id' => $payment['id'] ?? null]);
        }

        switch ($event) {
            case 'payment_link.paid':
            case 'payment_link.partially_paid':
                $out['kind'] = $out['payment'] !== null ? 'payment' : 'link';
                break;
            case 'payment_link.expired':
            case 'payment_link.cancelled':
                $out['kind'] = 'link';
                break;
            case 'payment.captured':
                $out['kind'] = $out['reference'] !== null && $out['payment'] !== null ? 'payment' : 'ignored';
                if ($out['kind'] === 'ignored') {
                    $out['note'] = 'payment.captured without notes.fin_link_id: not one of Finance\'s links (payment_link.paid carries Finance\'s payments).';
                }
                break;
            case 'refund.processed':
                if (is_array($refund)) {
                    $out['kind']   = 'refund';
                    $out['refund'] = self::refund($refund);
                }
                break;
            default:
                $out['note'] = "Event {$event} is not used by Finance.";
        }

        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string,mixed>|null $body
     * @return array{status:int, body:?string, error:?string, json:mixed}
     */
    private function call(string $method, string $path, ?array $body): array
    {
        $headers = ['Authorization' => 'Basic ' . base64_encode($this->keyId . ':' . $this->keySecret), 'Accept' => 'application/json'];
        if ($body !== null || $method === 'POST') {
            $headers['Content-Type'] = 'application/json';
        }
        $res = $this->http->request($method, $this->base . $path, $headers, $body === null ? ($method === 'POST' ? '{}' : null)
            : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($res['status'] === 0) {
            throw GatewayException::fromResponse('Razorpay', $res, $method . ' ' . strtok($path, '?'));
        }

        return $res;
    }

    /** @param array<string,mixed> $j */
    private static function link(array $j): array
    {
        $payments = null;
        if (is_array($j['payments'] ?? null)) {
            $payments = [];
            foreach ($j['payments'] as $p) {
                if (is_array($p)) {
                    $payments[] = self::payment($p);
                }
            }
        }

        return [
            'provider_link_id'  => (string) ($j['id'] ?? ''),
            'url'               => (string) ($j['short_url'] ?? ''),
            'status'            => self::status((string) ($j['status'] ?? '')),
            'provider_status'   => (string) ($j['status'] ?? ''),
            'amount_paid_paise' => is_int($j['amount_paid'] ?? null) ? $j['amount_paid'] : 0,
            'payments'          => $payments,
            'order_id'          => is_string($j['order_id'] ?? null) ? $j['order_id'] : null,
            'reference'         => is_string($j['reference_id'] ?? null) ? $j['reference_id'] : null,
        ];
    }

    /** A payment entity, or a payment_links.payments[] row ({payment_id, amount, method, status, created_at}). */
    private static function payment(array $p): array
    {
        $ts = $p['created_at'] ?? null;

        $id = (string) ($p['payment_id'] ?? $p['id'] ?? '');

        return [
            'provider_payment_id' => $id,
            'gateway_payment_ref' => $id === '' ? null : $id,          // pay_… — what the dashboard shows and staff type into Engage
            'provider_txn_ref'    => is_string($p['order_id'] ?? null) ? $p['order_id'] : null,
            'amount_paise'        => is_int($p['amount'] ?? null) ? $p['amount'] : 0,
            'method'              => is_string($p['method'] ?? null) ? $p['method'] : null,
            'paid_at'             => is_int($ts) ? self::iso($ts) : null,
            'captured'            => ($p['status'] ?? null) === 'captured',
        ];
    }

    private static function refund(array $r): array
    {
        $ts = $r['created_at'] ?? null;

        return [
            'provider_refund_id'  => (string) ($r['id'] ?? ''),
            'provider_payment_id' => (string) ($r['payment_id'] ?? ''),
            'amount_paise'        => is_int($r['amount'] ?? null) ? $r['amount'] : 0,
            'processed'           => ($r['status'] ?? null) === 'processed',
            'refunded_at'         => is_int($ts) ? self::iso($ts) : null,
        ];
    }

    public static function status(string $s): ?string
    {
        return match ($s) {
            'created' => 'created', 'partially_paid' => 'partially_paid', 'paid' => 'paid', 'expired' => 'expired', 'cancelled' => 'cancelled',
            default => null,
        };
    }

    private static function ourId(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^fin_pl_[0-9A-HJKMNP-TV-Z]{26}$/', $v) ? $v : null;
    }

    private static function iso(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format(\DateTimeInterface::ATOM);
    }
}
