<?php
declare(strict_types=1);

namespace App\Services\Invoicing;

/**
 * Where InvoiceService::issueForPayment() reads a verified gateway payment and its order from (contract §1.1:
 * fin_payments + fin_checkout_orders). Phase B's checkout implements it over those tables; in Phase A nothing
 * implements it in production, so no gateway invoice can be issued. The test suite provides its own.
 *
 * forPayment() returns null for an unknown payment, else:
 *   [
 *     'payment_id'   => int,
 *     'status'       => 'verified' | … (only 'verified' is invoiced),
 *     'cmp_id'       => int,
 *     'buyer_uuid'   => ?string,
 *     'buyer'        => {legal_name, gstin|null, state_code, address, pincode, country, email},
 *     'lines'        => [{item_code, item_kind, description, sac, gst_rate, period_start, period_end, quantity, amount_paise, price_basis}],
 *     'amount_paise' => int (what the gateway settled; must equal the invoice total),
 *     'gateway'      => string (e.g. razorpay), 'method' => string, 'reference' => string (gateway payment id),
 *     'paid_on'      => 'YYYY-MM-DD',
 *   ]
 */
interface PaymentInvoiceSource
{
    /** @return array<string,mixed>|null */
    public function forPayment(int $paymentId): ?array;
}
