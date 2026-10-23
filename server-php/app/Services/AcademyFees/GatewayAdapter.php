<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

/**
 * One payment-link provider (Razorpay, Cashfree) behind one shape. Every provider detail — URLs, headers, field
 * names, statuses, signature schemes — lives in its adapter; nothing else in Finance knows them.
 * docs/ACADEMY_PAYMENTS.md § "Provider details to verify" lists what must be checked against the provider docs.
 *
 * Normalised shapes (amounts always integer paise, times ISO 8601):
 *
 *   link     {provider_link_id, url, status, provider_status, amount_paid_paise, payments: list<payment>|null}
 *            status: created | partially_paid | paid | expired | cancelled
 *            payments: null when the provider's answer does not list them
 *   payment  {provider_payment_id, gateway_payment_ref, provider_txn_ref, amount_paise, method, paid_at, captured}
 *            provider_payment_id  Finance's key for the payment (Razorpay pay_…, Cashfree order_id: one order per link payment)
 *            gateway_payment_ref  the payment id staff see in the dashboard and type into Engage (Razorpay pay_…, Cashfree
 *                                 the bare cf_payment_id); only certain when read from the provider API (confirmPayment)
 *   refund   {provider_refund_id, provider_payment_id, amount_paise, processed, refunded_at}
 *   event    {kind: payment | link | refund | ignored, event_type, event_id, reference (our fin_pl_… id or null),
 *             provider_link_id, link_status, link_amount_paid_paise, payment: ?payment, refund: ?refund, note}
 *
 * Calls throw GatewayException (message safe to show: never a secret or header).
 */
interface GatewayAdapter
{
    public function provider(): string;

    /** Provider auth check with the stored credentials: {ok, message}. Never throws. */
    public function testConnection(): array;

    /**
     * @param array{link_id:string, amount_paise:int, description:string, payer:array{name:string, email:?string, mobile:string},
     *              expire_at:int, notify_sms:bool, notify_email:bool, notes:array<string,string>, return_url:?string, notify_url:?string} $link
     * @return array<string,mixed> link
     */
    public function createLink(array $link): array;

    /** The link created for our id (after a timeout we may not know the provider's id), or null. */
    public function findLink(string $ourLinkId): ?array;

    /** @return array<string,mixed> link */
    public function fetchLink(string $providerLinkId, string $ourLinkId): array;

    /** @return array<string,mixed> link */
    public function cancelLink(string $providerLinkId, string $ourLinkId): array;

    /**
     * The payment as the provider's API shows it for this link, captured — or null when the provider does not
     * (yet) show that payment as a captured payment of this link.
     *
     * @return array<string,mixed>|null payment
     */
    public function confirmPayment(string $providerLinkId, string $ourLinkId, string $providerPaymentId): ?array;

    /** @return array<string,mixed>|null refund (null: unknown to the provider) */
    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ?array;

    /** True when the webhook signature over the raw body verifies with $secret (constant-time). */
    public static function verifySignature(array $headers, string $rawBody, string $secret): bool;

    /** @return array<string,mixed> event */
    public static function parseWebhook(string $rawBody, array $headers): array;

    /** Replay-safety key for a delivery: the provider's event id when it sends one, else the body hash. */
    public static function fingerprint(string $rawBody, array $headers): string;
}
