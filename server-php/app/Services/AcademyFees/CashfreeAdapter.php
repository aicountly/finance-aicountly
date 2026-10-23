<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Support\Money;

/**
 * Cashfree Payments PG — Payment Links. What this adapter assumes — verify against
 * https://www.cashfree.com/docs/api-reference/payments/latest/payment-links before go-live
 * (docs/ACADEMY_PAYMENTS.md § "Provider details to verify"):
 *
 *   hosts       https://sandbox.cashfree.com (test) · https://api.cashfree.com (live), paths under /pg
 *   auth        headers x-client-id (app id), x-client-secret, x-api-version (FINANCE_CASHFREE_API_VERSION, default 2023-08-01)
 *   create      POST /pg/links {link_id (ours, ≤ 50: letters, digits, _ -), link_amount (RUPEES, decimal), link_currency "INR",
 *               link_purpose (≤ 500), customer_details {customer_phone (10 digits), customer_email, customer_name},
 *               link_partial_payments false, link_expiry_time (ISO 8601 with offset), link_notify {send_sms, send_email},
 *               link_auto_reminders false, link_notes {…}, link_meta {notify_url, return_url}}
 *               → {cf_link_id, link_id, link_url, link_status, link_amount_paid}
 *   statuses    ACTIVE | PARTIALLY_PAID | PAID | EXPIRED | CANCELLED (also TERMINATED / TERMINATION_REQUESTED in older docs)
 *   fetch       GET /pg/links/{link_id} · GET /pg/links/{link_id}/orders → [{order_id, cf_order_id, order_amount, order_status "PAID", created_at}]
 *   payments    GET /pg/orders/{order_id}/payments → [{cf_payment_id, payment_status "SUCCESS", payment_amount, payment_time, payment_group}]
 *   cancel      POST /pg/links/{link_id}/cancel
 *   refund      GET /pg/orders/{order_id}/refunds/{refund_id} → {cf_refund_id, refund_id, order_id, refund_amount, refund_status "SUCCESS", processed_at}
 *   auth check  GET /pg/links/{an id that does not exist}: 404 = the credentials work, 401 / 403 = they do not
 *   webhook     x-webhook-signature = base64(HMAC-SHA256(x-webhook-timestamp . raw body, secret)); the secret is the
 *               client secret unless a separate webhook secret is saved. Types used: PAYMENT_LINK_EVENT (primary),
 *               PAYMENT_SUCCESS_WEBHOOK (mapped through order_tags.link_id), REFUND_STATUS_WEBHOOK.
 *
 * Finance keys a Cashfree payment by its order_id (one order per link payment): both webhook types carry it. The
 * cf_payment_id staff see in the dashboard (gateway_payment_ref, sent to Engage as payment_ref) is read from the order's
 * payments at verification.
 */
final class CashfreeAdapter implements GatewayAdapter
{
    public const SANDBOX_BASE        = 'https://sandbox.cashfree.com';
    public const LIVE_BASE           = 'https://api.cashfree.com';
    public const DEFAULT_API_VERSION = '2023-08-01';

    private string $base;
    private string $version;

    public function __construct(private string $appId, private string $secret, private string $mode, private GatewayHttp $http, ?string $baseUrl = null)
    {
        $this->base    = rtrim($baseUrl ?? (finance_env('FINANCE_CASHFREE_API_BASE') ?: ($mode === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE)), '/');
        $this->version = finance_env('FINANCE_CASHFREE_API_VERSION') ?: self::DEFAULT_API_VERSION;
    }

    public function provider(): string
    {
        return 'cashfree';
    }

    public function testConnection(): array
    {
        try {
            $res = $this->call('GET', '/pg/links/fin_auth_check_' . bin2hex(random_bytes(4)), null);
        } catch (GatewayException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($res['status'] === 404 || $res['status'] === 200) {
            return ['ok' => true, 'message' => 'Cashfree accepted the app id and secret (' . $this->mode . ' environment).'];
        }

        return ['ok' => false, 'message' => GatewayException::fromResponse('Cashfree', $res, 'the auth check')->getMessage()];
    }

    public function createLink(array $link): array
    {
        $customer = ['customer_phone' => self::phone10($link['payer']['mobile']), 'customer_name' => $link['payer']['name']];
        if (!empty($link['payer']['email'])) {
            $customer['customer_email'] = $link['payer']['email'];
        }
        $meta = [];
        if (!empty($link['notify_url'])) {
            $meta['notify_url'] = $link['notify_url'];
        }
        if (!empty($link['return_url'])) {
            $meta['return_url'] = $link['return_url'];
        }
        $body = [
            'link_id'               => $link['link_id'],
            'link_amount'           => round($link['amount_paise'] / 100, 2),
            'link_currency'         => 'INR',
            'link_purpose'          => mb_substr($link['description'], 0, 500),
            'customer_details'      => $customer,
            'link_partial_payments' => false,
            'link_expiry_time'      => (new \DateTimeImmutable('@' . $link['expire_at']))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format(\DateTimeInterface::ATOM),
            'link_notify'           => ['send_sms' => $link['notify_sms'], 'send_email' => $link['notify_email']],
            'link_auto_reminders'   => false,
            'link_notes'            => (object) $link['notes'],
        ];
        if ($meta !== []) {
            $body['link_meta'] = $meta;
        }
        $res = $this->call('POST', '/pg/links', $body);
        if ($res['status'] !== 200 || !is_array($res['json']) || ($res['json']['link_id'] ?? null) !== $link['link_id']) {
            throw GatewayException::fromResponse('Cashfree', $res, 'creating the payment link');
        }

        return self::link($res['json'], null);
    }

    public function findLink(string $ourLinkId): ?array
    {
        $res = $this->call('GET', '/pg/links/' . rawurlencode($ourLinkId), null);
        if ($res['status'] === 404) {
            return null;
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Cashfree', $res, 'looking up the payment link');
        }

        return self::link($res['json'], null);
    }

    public function fetchLink(string $providerLinkId, string $ourLinkId): array
    {
        $res = $this->call('GET', '/pg/links/' . rawurlencode($providerLinkId), null);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Cashfree', $res, 'reading the payment link');
        }
        if (($res['json']['link_id'] ?? null) !== $ourLinkId) {
            throw new GatewayException("Cashfree: payment link {$providerLinkId} does not belong to {$ourLinkId}.", 200, false);
        }

        return self::link($res['json'], $this->orders($providerLinkId));
    }

    public function cancelLink(string $providerLinkId, string $ourLinkId): array
    {
        $res = $this->call('POST', '/pg/links/' . rawurlencode($providerLinkId) . '/cancel', null);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Cashfree', $res, 'cancelling the payment link');
        }

        return self::link($res['json'], null);
    }

    /**
     * The order must be a PAID order of this link (GET /pg/links/{id}/orders); its successful payment
     * (GET /pg/orders/{order_id}/payments) gives the cf_payment_id staff see in the dashboard, the method and the time.
     */
    public function confirmPayment(string $providerLinkId, string $ourLinkId, string $providerPaymentId): ?array
    {
        $order = null;
        foreach ($this->orders($providerLinkId) as $p) {
            if ($p['provider_payment_id'] === $providerPaymentId) {
                $order = $p;
                break;
            }
        }
        if ($order === null || !$order['captured']) {
            return null;
        }
        $res = $this->call('GET', '/pg/orders/' . rawurlencode($providerPaymentId) . '/payments', null);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Cashfree', $res, 'reading the order\'s payments');
        }
        foreach ($res['json'] as $pay) {
            if (is_array($pay) && ($pay['payment_status'] ?? null) === 'SUCCESS' && isset($pay['cf_payment_id'])) {
                return [
                    'provider_payment_id' => $providerPaymentId,
                    'gateway_payment_ref' => (string) $pay['cf_payment_id'],
                    'provider_txn_ref'    => (string) $pay['cf_payment_id'],
                    'amount_paise'        => self::paise($pay['payment_amount'] ?? null) ?? $order['amount_paise'],
                    'method'              => is_string($pay['payment_group'] ?? null) ? $pay['payment_group'] : null,
                    'paid_at'             => self::isoOrNull($pay['payment_time'] ?? null) ?? $order['paid_at'],
                    'captured'            => true,
                ];
            }
        }

        return null;
    }

    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ?array
    {
        $res = $this->call('GET', '/pg/orders/' . rawurlencode($providerPaymentId) . '/refunds/' . rawurlencode($providerRefundId), null);
        if ($res['status'] === 404) {
            return null;
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Cashfree', $res, 'reading the refund');
        }
        $r = self::refund($res['json']);

        return $r['provider_payment_id'] === $providerPaymentId ? $r : null;
    }

    // ------------------------------------------------------------------ webhooks (pure)

    public static function verifySignature(array $headers, string $rawBody, string $secret): bool
    {
        $given = trim((string) ($headers['x-webhook-signature'] ?? ''));
        $ts    = trim((string) ($headers['x-webhook-timestamp'] ?? ''));
        if ($secret === '' || $given === '' || !preg_match('/^\d{9,16}$/', $ts)) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $ts . $rawBody, $secret, true)), $given);
    }

    public static function fingerprint(string $rawBody, array $headers): string
    {
        return 'cashfree:sha256:' . hash('sha256', $rawBody);
    }

    public static function parseWebhook(string $rawBody, array $headers): array
    {
        $j    = json_decode($rawBody, true);
        $type = is_array($j) && is_string($j['type'] ?? null) ? $j['type'] : '';
        $out  = ['kind' => 'ignored', 'event_type' => $type ?: 'unknown', 'event_id' => null, 'reference' => null, 'provider_link_id' => null,
            'link_status' => null, 'link_amount_paid_paise' => null, 'payment' => null, 'refund' => null, 'note' => null];
        if (!is_array($j)) {
            return ['note' => 'Body is not JSON.'] + $out;
        }
        $d = is_array($j['data'] ?? null) ? $j['data'] : [];
        switch ($type) {
            case 'PAYMENT_LINK_EVENT':
                $out['reference']              = self::ourId($d['link_id'] ?? null);
                $out['provider_link_id']       = is_string($d['link_id'] ?? null) ? $d['link_id'] : null;
                $out['link_status']            = self::status((string) ($d['link_status'] ?? ''));
                $out['link_amount_paid_paise'] = self::paise($d['link_amount_paid'] ?? null);
                $order = is_array($d['order'] ?? null) ? $d['order'] : null;
                if ($order !== null && is_string($order['order_id'] ?? null) && ($order['transaction_status'] ?? null) === 'SUCCESS') {
                    $out['kind']    = 'payment';
                    $out['payment'] = [
                        'provider_payment_id' => $order['order_id'],
                        'gateway_payment_ref' => null,      // read from GET /pg/orders/{order_id}/payments at verification
                        'provider_txn_ref'    => isset($order['transaction_id']) ? (string) $order['transaction_id'] : null,
                        'amount_paise'        => self::paise($order['order_amount'] ?? null) ?? 0,
                        'method'              => null,
                        'paid_at'             => self::isoOrNull($j['event_time'] ?? null),
                        'captured'            => true,
                    ];
                } else {
                    $out['kind'] = 'link';
                }
                break;
            case 'PAYMENT_SUCCESS_WEBHOOK':
                $order = is_array($d['order'] ?? null) ? $d['order'] : [];
                $pay   = is_array($d['payment'] ?? null) ? $d['payment'] : [];
                $out['reference']        = self::ourId($order['order_tags']['link_id'] ?? null);
                $out['provider_link_id'] = $out['reference'];
                if ($out['reference'] !== null && is_string($order['order_id'] ?? null) && ($pay['payment_status'] ?? null) === 'SUCCESS') {
                    $out['kind']    = 'payment';
                    $out['payment'] = [
                        'provider_payment_id' => $order['order_id'],
                        'gateway_payment_ref' => isset($pay['cf_payment_id']) ? (string) $pay['cf_payment_id'] : null,
                        'provider_txn_ref'    => isset($pay['cf_payment_id']) ? (string) $pay['cf_payment_id'] : null,
                        'amount_paise'        => self::paise($pay['payment_amount'] ?? $order['order_amount'] ?? null) ?? 0,
                        'method'              => is_string($pay['payment_group'] ?? null) ? $pay['payment_group'] : null,
                        'paid_at'             => self::isoOrNull($pay['payment_time'] ?? $j['event_time'] ?? null),
                        'captured'            => true,
                    ];
                } else {
                    $out['note'] = 'PAYMENT_SUCCESS_WEBHOOK without order_tags.link_id of a Finance link (PAYMENT_LINK_EVENT carries Finance\'s payments).';
                }
                break;
            case 'REFUND_STATUS_WEBHOOK':
                if (is_array($d['refund'] ?? null)) {
                    $r = self::refund($d['refund']);
                    if ($r['processed']) {
                        $out['kind']   = 'refund';
                        $out['refund'] = $r;
                    } else {
                        $out['note'] = 'Refund not successful yet (' . ($d['refund']['refund_status'] ?? '?') . ').';
                    }
                }
                break;
            default:
                $out['note'] = "Event {$type} is not used by Finance.";
        }

        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<array<string,mixed>> the link's orders as payments */
    private function orders(string $providerLinkId): array
    {
        $res = $this->call('GET', '/pg/links/' . rawurlencode($providerLinkId) . '/orders', null);
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw GatewayException::fromResponse('Cashfree', $res, 'reading the payment link\'s orders');
        }
        $out = [];
        foreach ($res['json'] as $o) {
            if (!is_array($o) || !is_string($o['order_id'] ?? null)) {
                continue;
            }
            $out[] = [
                'provider_payment_id' => $o['order_id'],
                'gateway_payment_ref' => null,
                'provider_txn_ref'    => isset($o['cf_order_id']) ? (string) $o['cf_order_id'] : null,
                'amount_paise'        => self::paise($o['order_amount'] ?? null) ?? 0,
                'method'              => null,
                'paid_at'             => self::isoOrNull($o['created_at'] ?? null),
                'captured'            => ($o['order_status'] ?? null) === 'PAID',
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{status:int, body:?string, error:?string, json:mixed}
     */
    private function call(string $method, string $path, ?array $body): array
    {
        $headers = [
            'x-client-id'     => $this->appId,
            'x-client-secret' => $this->secret,
            'x-api-version'   => $this->version,
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
        ];
        $res = $this->http->request($method, $this->base . $path, $headers, $body === null ? ($method === 'POST' ? '{}' : null)
            : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($res['status'] === 0) {
            throw GatewayException::fromResponse('Cashfree', $res, $method . ' ' . $path);
        }

        return $res;
    }

    /**
     * @param array<string,mixed> $j
     * @param list<array<string,mixed>>|null $payments
     */
    private static function link(array $j, ?array $payments): array
    {
        return [
            'provider_link_id'  => (string) ($j['link_id'] ?? ''),
            'url'               => (string) ($j['link_url'] ?? ''),
            'status'            => self::status((string) ($j['link_status'] ?? '')),
            'provider_status'   => (string) ($j['link_status'] ?? ''),
            'amount_paid_paise' => self::paise($j['link_amount_paid'] ?? null) ?? 0,
            'payments'          => $payments,
            'order_id'          => null,
            'reference'         => is_string($j['link_id'] ?? null) ? $j['link_id'] : null,
        ];
    }

    private static function refund(array $r): array
    {
        return [
            'provider_refund_id'  => (string) ($r['refund_id'] ?? $r['cf_refund_id'] ?? ''),
            'provider_payment_id' => (string) ($r['order_id'] ?? ''),
            'amount_paise'        => self::paise($r['refund_amount'] ?? null) ?? 0,
            'processed'           => ($r['refund_status'] ?? null) === 'SUCCESS',
            'refunded_at'         => self::isoOrNull($r['processed_at'] ?? null),
        ];
    }

    public static function status(string $s): ?string
    {
        return match (strtoupper($s)) {
            'ACTIVE', 'TERMINATION_REQUESTED' => 'created',
            'PARTIALLY_PAID' => 'partially_paid',
            'PAID' => 'paid',
            'EXPIRED' => 'expired',
            'CANCELLED', 'TERMINATED' => 'cancelled',
            default => null,
        };
    }

    /** Rupee amount (JSON number or string, e.g. 11800 / 11800.5 / "11800.50") → paise. */
    public static function paise(mixed $v): ?int
    {
        if (is_float($v) || is_int($v) || is_string($v)) {
            return Money::parse(is_string($v) ? trim($v) : $v);
        }

        return null;
    }

    /** +91XXXXXXXXXX → XXXXXXXXXX (Cashfree wants the 10-digit Indian number). */
    public static function phone10(string $e164): string
    {
        return str_starts_with($e164, '+91') ? substr($e164, 3) : $e164;
    }

    private static function ourId(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^fin_pl_[0-9A-HJKMNP-TV-Z]{26}$/', $v) ? $v : null;
    }

    private static function isoOrNull(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
