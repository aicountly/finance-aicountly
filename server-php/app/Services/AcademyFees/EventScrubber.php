<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

/**
 * Removes personal and payment-instrument data from a provider webhook before Finance stores it (fin_gateway_webhook_events
 * .payload, fin_gateway_payments.raw_event). Kept: ids, amounts, statuses, methods, timestamps, notes Finance itself set.
 * Removed (value replaced by "[scrubbed]"): names, e-mail, phone, addresses, VPA / UPI ids, card, bank and wallet details,
 * tokens, and anything nested under them. Finance already holds the payer snapshot it needs on the link.
 */
final class EventScrubber
{
    public const MARK = '[scrubbed]';

    /** Keys (lower case) whose value is removed, wherever they appear. */
    private const KEYS = [
        'email', 'contact', 'phone', 'mobile', 'name', 'customer_name', 'customer_email', 'customer_phone', 'customer_details', 'customer',
        'address', 'billing_address', 'shipping_address', 'vpa', 'upi', 'upi_id', 'payer_vpa', 'card', 'card_id', 'card_number', 'cardholder',
        'bank_account', 'account_number', 'ifsc', 'bank', 'wallet', 'token', 'token_id', 'payment_method', 'acquirer_data', 'auth_code',
        'customer_id', 'channel', 'emi', 'netbanking', 'app', 'cardless_emi', 'paylater', 'error_details',
    ];

    public static function scrub(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 12 || !is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::KEYS, true)) {
                $out[$k] = self::MARK;
                continue;
            }
            $out[$k] = self::scrub($v, $depth + 1);
        }

        return $out;
    }

    /** The raw JSON body, scrubbed; null when it is not JSON. */
    public static function fromBody(string $rawBody): ?array
    {
        $j = json_decode($rawBody, true);

        return is_array($j) ? self::scrub($j) : null;
    }
}
