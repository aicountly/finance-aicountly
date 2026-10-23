<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Services\Invoicing\GstCalculator;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PaymentInvoiceSource;
use App\Support\FiscalYear;

/**
 * InvoiceService::issueForPayment()'s view of an Aicountly Academy fee payment (fin_gateway_payments + the
 * fin_payment_links row it paid). The buyer is the student (buyer_kind academy_student: no Manage company, no GSTIN),
 * the place of supply the student's state, and the one line is the fee exactly as fixed on the link — so the invoice
 * total equals the amount the provider settled, or the payment is reported as blocked_data.
 *
 * Only a payment Finance has re-read from the provider (verification_status = verified) is 'verified' here; a second
 * payment on an already-paid link is never invoiced (it is reported 'duplicate' so staff refund it).
 */
final class GatewayPaymentInvoiceSource implements PaymentInvoiceSource
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function forPayment(int $paymentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, l.link_id AS link_ref, l.academy_ref, l.product_code, l.stream, l.description, l.payer_name, l.payer_email, l.payer_state_code,
                    l.payer_address, l.payer_pincode, l.amount_paise AS link_amount_paise, l.fee_taxable_paise, l.fee_sac, l.fee_gst_rate, l.fee_price_basis
               FROM fin_gateway_payments p JOIN fin_payment_links l ON l.id = p.link_id WHERE p.id = ?',
        );
        $stmt->execute([$paymentId]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        $status = $r['verification_status'] === 'verified' && !self::isDuplicate($this->pdo, $r) ? 'verified' : (string) $r['verification_status'];
        $amount = $r['fee_price_basis'] === GstCalculator::EXCLUSIVE ? (int) $r['fee_taxable_paise'] : (int) $r['link_amount_paise'];

        return [
            'payment_id'   => (int) $r['id'],
            'status'       => $status === 'verified' ? 'verified' : ($status === 'pending' ? 'pending' : 'not_invoiceable'),
            'buyer_kind'   => InvoiceService::BUYER_ACADEMY_STUDENT,
            'cmp_id'       => null,
            'buyer_uuid'   => null,
            'buyer'        => [
                'legal_name' => (string) $r['payer_name'],
                'gstin'      => null,
                'state_code' => (string) $r['payer_state_code'],
                'address'    => (string) ($r['payer_address'] ?? ''),
                'pincode'    => (string) ($r['payer_pincode'] ?? ''),
                'country'    => 'IN',
                'email'      => $r['payer_email'],
            ],
            'lines'        => [[
                'item_code'    => (string) $r['product_code'],
                'item_kind'    => 'academy_fee',
                'description'  => (string) $r['description'],
                'sac'          => (string) $r['fee_sac'],
                'gst_rate'     => (string) $r['fee_gst_rate'],
                'price_basis'  => (string) $r['fee_price_basis'],
                'quantity'     => 1,
                'amount_paise' => $amount,
            ]],
            'amount_paise' => (int) $r['amount_paise'],
            'gateway'      => GatewayConnectionService::PROVIDERS[$r['provider']] ?? (string) $r['provider'],
            'method'       => (string) ($r['method'] ?? ''),
            'reference'    => (string) $r['provider_payment_id'],
            'paid_on'      => (new \DateTimeImmutable((string) $r['paid_at']))->setTimezone(FiscalYear::tz())->format('Y-m-d'),
        ];
    }

    /** True when another, earlier verified payment already paid this link in full. */
    public static function isDuplicate(\PDO $pdo, array $payment): bool
    {
        $stmt = $pdo->prepare(
            "SELECT coalesce(sum(amount_paise), 0) FROM fin_gateway_payments
              WHERE link_id = ? AND verification_status = 'verified' AND (verified_at, id) < (?::timestamptz, ?)",
        );
        $stmt->execute([(int) $payment['link_id'], (string) ($payment['verified_at'] ?? 'infinity'), (int) $payment['id']]);
        $before = (int) $stmt->fetchColumn();
        $link   = $pdo->prepare('SELECT amount_paise FROM fin_payment_links WHERE id = ?');
        $link->execute([(int) $payment['link_id']]);

        return $before >= (int) $link->fetchColumn();
    }
}
