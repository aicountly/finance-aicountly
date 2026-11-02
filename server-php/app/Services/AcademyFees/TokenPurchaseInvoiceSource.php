<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Services\Invoicing\GstCalculator;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PaymentInvoiceSource;

/**
 * InvoiceService::issueForPayment()'s view of a customer buying AI tokens.
 *
 * The sibling of GatewayPaymentInvoiceSource, which does the same for an Academy fee. Both
 * read fin_gateway_payments and the fin_payment_links row that was paid, because 005 asked
 * that any later checkout record its payments in the same table so the `payment:<id>`
 * namespace stays single — and so one reconciliation covers both.
 *
 * What differs is the buyer. An Academy student has no Manage company and no GSTIN; a token
 * buyer is a signed-in customer who has both a company (so Manage can show them the invoice)
 * and a licence holder (so the tokens can be granted to the right account), and may well be
 * GST-registered. That is the whole reason for buyer_kind saas_customer.
 *
 * One line, always: the tokens. Any coupon the customer used was applied when Engage priced
 * the order, and travels here as the line's list price, discount and code, so the invoice
 * shows what was given away — GST on the price after discount, which is correct for a
 * discount agreed before supply (CGST s.15(3)(a)).
 *
 * Only a payment Finance has re-read from the provider (verification_status = verified) is
 * invoiced. A second payment on an already-paid link is never invoiced — it is reported as a
 * duplicate so staff refund it.
 *
 * NOTE this class lives in the AcademyFees namespace with its sibling rather than in a folder
 * of its own: it shares every service around it (GatewayPaymentService, WebhookService, the
 * adapters), and splitting one of the two out would scatter the gateway code rather than
 * organise it. The namespace is now a misnomer; renaming it is a separate, mechanical change.
 */
final class TokenPurchaseInvoiceSource implements PaymentInvoiceSource
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function forPayment(int $paymentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, l.link_id AS link_ref, l.purpose, l.source_ref, l.product_code, l.description, l.token_count,
                    l.cmp_id, l.buyer_uuid, l.buyer_gstin, l.payer_name, l.payer_email, l.payer_state_code,
                    l.payer_address, l.payer_pincode, l.amount_paise AS link_amount_paise, l.fee_taxable_paise,
                    l.fee_sac, l.fee_gst_rate, l.fee_price_basis, l.fee_breakdown
               FROM fin_gateway_payments p
               JOIN fin_payment_links l ON l.id = p.link_id
              WHERE p.id = ? AND l.purpose = \'tokens\'',
        );
        $stmt->execute([$paymentId]);
        $r = $stmt->fetch();
        if (!$r) {
            // Not a token purchase (or not a payment at all). The Academy source handles its own.
            return null;
        }

        $status = $r['verification_status'] === 'verified' && !GatewayPaymentInvoiceSource::isDuplicate($this->pdo, $r)
            ? 'verified'
            : (string) $r['verification_status'];

        // On an exclusive link the line is billed at the taxable value and GST is added; on an
        // inclusive one the line IS the gross. Either way the computed total has to equal what
        // the provider actually settled, or InvoiceService refuses it as a mismatch.
        $amount = $r['fee_price_basis'] === GstCalculator::EXCLUSIVE
            ? (int) $r['fee_taxable_paise']
            : (int) $r['link_amount_paise'];

        $fee      = json_decode((string) $r['fee_breakdown'], true);
        $discount = is_array($fee)
            ? array_filter(array_intersect_key($fee, array_flip(PaymentLinkInput::DISCOUNT_FIELDS)), static fn ($v) => $v !== null && $v !== '')
            : [];

        $tokens = (int) $r['token_count'];

        return [
            'payment_id' => (int) $r['id'],
            'status'     => $status === 'verified' ? 'verified' : ($status === 'pending' ? 'pending' : 'not_invoiceable'),
            'buyer_kind' => InvoiceService::BUYER_SAAS_CUSTOMER,
            'cmp_id'     => (int) $r['cmp_id'],
            'buyer_uuid' => (string) $r['buyer_uuid'],
            'buyer'      => [
                'legal_name' => (string) $r['payer_name'],
                // A business buying tokens gets its GSTIN on the invoice and can claim the input
                // credit; an individual leaves it empty and is invoiced unregistered.
                'gstin'      => ($r['buyer_gstin'] ?? '') !== '' ? (string) $r['buyer_gstin'] : null,
                'state_code' => (string) $r['payer_state_code'],
                'address'    => (string) ($r['payer_address'] ?? ''),
                'pincode'    => (string) ($r['payer_pincode'] ?? ''),
                'country'    => 'IN',
                'email'      => $r['payer_email'],
            ],
            'lines' => [[
                'item_code'    => 'ai_tokens',
                'item_kind'    => 'tokens',
                'description'  => (string) $r['description'],
                'sac'          => (string) $r['fee_sac'],
                'gst_rate'     => (string) $r['fee_gst_rate'],
                'price_basis'  => (string) $r['fee_price_basis'],
                'period_start' => null,
                'period_end'   => null,
                // Tokens do not expire, so the line is one supply of N tokens rather than a
                // quantity priced per unit — a quantity of 1,000,000 at a fraction of a paisa
                // would round on the line and not foot against what was collected.
                'quantity'     => 1,
                'amount_paise' => $amount,
            ] + $discount],
            'amount_paise' => (int) $r['amount_paise'],
            'gateway'      => (string) $r['provider'],
            'method'       => (string) ($r['method'] ?? 'gateway'),
            'reference'    => (string) $r['provider_payment_id'],
            'paid_on'      => substr((string) $r['paid_at'], 0, 10),
            // Not read by InvoiceService; carried so the caller can tell Engage how many
            // tokens this invoice paid for without re-reading the link.
            'token_count'  => $tokens,
        ];
    }
}
