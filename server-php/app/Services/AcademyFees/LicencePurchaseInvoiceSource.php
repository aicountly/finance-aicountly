<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PaymentInvoiceSource;
use App\Support\FiscalYear;

/**
 * InvoiceService::issueForPayment()'s view of an Aicountly seller buying licences (purpose licences, migration 009).
 *
 * The third sibling of GatewayPaymentInvoiceSource (an Academy fee) and TokenPurchaseInvoiceSource (AI tokens): the
 * payment is in fin_gateway_payments like theirs, so one reconciliation covers all three. What differs:
 *
 *   the buyer   the seller's business (buyer_kind reseller): its legal name, its GSTIN when it is registered — so it
 *               can claim the input credit — and its address. It is not a Manage company and holds no licence
 *               itself (it applies them to its customers' accounts), so there is no cmp_id and no buyer_uuid.
 *   the lines   one invoice line per order line, exactly as Finance checked them when the link was raised: the plan,
 *               the quantity, the line amount on its price basis, its SAC and rate. The invoice therefore totals what
 *               the provider settled, or the payment is reported blocked_data.
 *
 * Only a payment Finance has re-read from the provider (verification_status = verified) is invoiced; a second payment
 * on an already-paid link is never invoiced (reported as a duplicate for staff to refund).
 */
final class LicencePurchaseInvoiceSource implements PaymentInvoiceSource
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function forPayment(int $paymentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, l.link_id AS link_ref, l.source_ref, l.seller_code, l.buyer_gstin, l.payer_name, l.payer_email, l.payer_state_code,
                    l.payer_address, l.payer_pincode, l.lines
               FROM fin_gateway_payments p
               JOIN fin_payment_links l ON l.id = p.link_id
              WHERE p.id = ? AND l.purpose = 'licences'",
        );
        $stmt->execute([$paymentId]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }

        $status = $r['verification_status'] === 'verified' && !GatewayPaymentInvoiceSource::isDuplicate($this->pdo, $r)
            ? 'verified'
            : (string) $r['verification_status'];

        $lines = [];
        foreach ((array) json_decode((string) $r['lines'], true) as $l) {
            if (!is_array($l)) {
                continue;
            }
            $lines[] = [
                'item_code'    => (string) $l['offer_code'],
                'item_kind'    => 'licence',
                'description'  => (string) $l['description'],
                'sac'          => (string) $l['sac'],
                'gst_rate'     => (string) $l['gst_rate'],
                'price_basis'  => (string) $l['price_basis'],
                'period_start' => null,
                'period_end'   => null,
                // The line bills its whole amount (unit price × quantity) on its basis; the quantity is what it buys.
                'quantity'     => (int) $l['quantity'],
                'amount_paise' => (int) $l['amount_paise'],
            ];
        }

        return [
            'payment_id' => (int) $r['id'],
            'status'     => $status === 'verified' ? 'verified' : ($status === 'pending' ? 'pending' : 'not_invoiceable'),
            'buyer_kind' => InvoiceService::BUYER_RESELLER,
            'cmp_id'     => null,
            'buyer_uuid' => null,
            'buyer'      => [
                'legal_name' => (string) $r['payer_name'],
                'gstin'      => ($r['buyer_gstin'] ?? '') !== '' ? (string) $r['buyer_gstin'] : null,
                'state_code' => (string) $r['payer_state_code'],
                'address'    => (string) ($r['payer_address'] ?? ''),
                'pincode'    => (string) ($r['payer_pincode'] ?? ''),
                'country'    => 'IN',
                'email'      => $r['payer_email'],
            ],
            'lines'        => $lines,
            'amount_paise' => (int) $r['amount_paise'],
            'gateway'      => GatewayConnectionService::PROVIDERS[$r['provider']] ?? (string) $r['provider'],
            'method'       => (string) ($r['method'] ?? ''),
            'reference'    => (string) $r['provider_payment_id'],
            'paid_on'      => (new \DateTimeImmutable((string) $r['paid_at']))->setTimezone(FiscalYear::tz())->format('Y-m-d'),
        ];
    }
}
