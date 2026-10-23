<?php
declare(strict_types=1);

namespace App\Services\AcademyFees;

use App\Services\Invoicing\GstCalculator;
use App\Services\Invoicing\InvoiceInput;
use App\Services\Invoicing\InvoiceService;
use App\Support\ApiException;
use App\Support\Gstin;
use App\Support\Money;

/**
 * Validates a fee demand (Engage's request, or the staff "create link" form) and fixes its GST split.
 *
 * Academy defines the fee and its GST treatment; Finance issues the invoice, so Finance must agree with the split
 * before the student is asked to pay — otherwise the invoice could not be issued for the amount paid:
 *
 *   Engage (strict)   sends amount_paise and fee {taxable_paise, cgst_paise, sgst_paise, igst_paise, sac, gst_rate,
 *                     price_basis}. Finance recomputes the split with its invoice rules (GstCalculator: per line, half up,
 *                     SGST = floor(tax / 2); intra-state when the student's state is the seller's) and refuses any
 *                     difference with 422 fee_breakdown_mismatch (details carry Finance's figures).
 *   Staff (compute)   type amount (rupees: the fee incl. GST when inclusive, the taxable value when exclusive) and
 *                     fee {sac, gst_rate, price_basis}; Finance computes the split and the amount payable.
 *
 * A fee may carry a discount (fee.list_amount_paise / discount_paise / discount_ref, the invoice-line discount fields):
 * the amount and GST are on the price after discount; the invoice prints the list price, the discount and its ref.
 *
 * Place of supply = the student's state (unregistered recipient with a known address, s.12(2)(b) IGST Act); the address
 * and PIN become required from a taxable value of ₹50,000 (CGST Rule 46(e)).
 */
final class PaymentLinkInput
{
    public const STREAMS          = ['edge' => 'Aicountly Edge', 'aspire' => 'Aicountly Aspire'];
    public const MIN_EXPIRY       = 15 * 60;            // Razorpay refuses an expiry under 15 minutes away
    public const MAX_EXPIRY       = 180 * 86400;
    public const DEFAULT_EXPIRY   = 15 * 86400;
    public const REF_PATTERN      = '/^[A-Za-z0-9_.:\/-]{1,64}$/';

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed> normalised: source_ref, academy_ref, stream, product_code, seller_code, description, payer{…},
     *                             amount_paise, fee{taxable_paise, cgst_paise, sgst_paise, igst_paise, sac, gst_rate, price_basis},
     *                             fee_breakdown (as sent), place_of_supply, supply_type, expire_at (unix), provider, notify_sms, notify_email
     */
    public static function validate(array $in, string $sellerState, bool $strict, int $now): array
    {
        $e = [];

        $sourceRef = self::ref($in['source_ref'] ?? null);
        if ($sourceRef === null && ($strict || self::present($in['source_ref'] ?? null))) {
            $e['source_ref'] = $strict ? 'The Engage lead code is required (1–64 letters, digits or _ . : / -).' : 'Letters, digits or _ . : / - (at most 64).';
        }
        $academyRef = self::ref($in['academy_ref'] ?? null);
        if ($academyRef === null) {
            $e['academy_ref'] = 'The Academy admission / fee-account reference is required (1–64 letters, digits or _ . : / -).';
        }
        $stream = is_string($in['stream'] ?? null) ? strtolower(trim($in['stream'])) : '';
        if (!isset(self::STREAMS[$stream])) {
            $e['stream'] = 'edge (Aicountly Edge) or aspire (Aicountly Aspire).';
        }
        $product = is_string($in['product_code'] ?? null) ? strtolower(trim($in['product_code'])) : '';
        if ($product === '' && isset(self::STREAMS[$stream])) {
            $product = $stream;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $product)) {
            $e['product_code'] = 'The Engage product code (lower case letters, digits, _ . -), e.g. edge.';
        }
        $seller = is_string($in['seller_code'] ?? null) ? trim($in['seller_code']) : '';
        if ($seller !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $seller)) {
            $e['seller_code'] = 'A seller code such as SLR-000123.';
        }
        $desc = InvoiceInput::text($in['description'] ?? null, 300);
        if ($desc === '') {
            $e['description'] = 'What the fee is for, as printed on the invoice (e.g. "Aicountly Edge — fee instalment 1 of 2").';
        }

        // Payer (the student).
        $p     = is_array($in['payer'] ?? null) ? $in['payer'] : [];
        $name  = InvoiceInput::text($p['name'] ?? null, 100);
        if ($name === '') {
            $e['payer.name'] = 'The student\'s name (as on the invoice).';
        }
        $email = InvoiceInput::text($p['email'] ?? null, 254);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $e['payer.email'] = 'A valid email address.';
        }
        $mobile = self::mobile($p['mobile'] ?? null);
        if ($mobile === null) {
            $e['payer.mobile'] = 'An Indian mobile number (10 digits, optionally with +91).';
        }
        $state = trim(is_scalar($p['state_code'] ?? null) ? (string) $p['state_code'] : '');
        if (preg_match('/^\d$/', $state)) {
            $state = '0' . $state;
        }
        if (!Gstin::isStateCode($state)) {
            $e['payer.state_code'] = 'The student\'s state as a GST state code (two digits, e.g. 27 for Maharashtra): it is the place of supply.';
        }
        $address = InvoiceInput::text($p['address'] ?? null, 300, true);
        $pincode = trim(is_scalar($p['pincode'] ?? null) ? (string) $p['pincode'] : '');
        if ($pincode !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            $e['payer.pincode'] = 'A six-digit PIN code.';
        }

        // Fee and amount.
        $f     = is_array($in['fee'] ?? null) ? $in['fee'] : [];
        $sac   = trim(is_scalar($f['sac'] ?? null) ? (string) $f['sac'] : '');
        if (!preg_match('/^99[0-9]{4}$/', $sac)) {
            $e['fee.sac'] = 'A six-digit SAC (services start with 99; Academy defines it per course).';
        }
        $rateBp = Money::parseRate($f['gst_rate'] ?? null, 9999);
        if ($rateBp === null) {
            $e['fee.gst_rate'] = 'The GST rate in percent (e.g. 18), at least 0 and below 100.';
        }
        $basis = is_string($f['price_basis'] ?? null) ? strtolower(trim($f['price_basis'])) : '';
        if (!in_array($basis, [GstCalculator::INCLUSIVE, GstCalculator::EXCLUSIVE], true)) {
            $e['fee.price_basis'] = 'inclusive (the fee includes GST) or exclusive (GST is added to the fee).';
        }

        $amount = null;
        $base   = null;      // what GstCalculator::line() takes: the total (inclusive) or the taxable value (exclusive)
        if ($strict) {
            $amount = self::paiseInt($in['amount_paise'] ?? null);
            if ($amount === null || $amount < 100) {
                $e['amount_paise'] = 'The amount the student pays, in paise (an integer, at least 100).';
            }
            foreach (['taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise'] as $k) {
                if (self::paiseInt($f[$k] ?? null, true) === null) {
                    $e['fee.' . $k] = 'Required: a non-negative integer in paise.';
                }
            }
            $base = $basis === GstCalculator::EXCLUSIVE ? self::paiseInt($f['taxable_paise'] ?? null, true) : $amount;
            if ($basis === GstCalculator::EXCLUSIVE && $base === 0) {
                $e['fee.taxable_paise'] = 'An exclusive fee needs a taxable value above zero.';
            }
        } else {
            $base = Money::parse($in['amount'] ?? null);
            if ($base === null || $base < 100) {
                $e['amount'] = 'The fee in rupees (e.g. "11800.00"): including GST when the price is inclusive, the taxable value when exclusive.';
            }
        }

        $expireAt = $now + self::DEFAULT_EXPIRY;
        if (self::present($in['expire_at'] ?? null)) {
            $t = is_string($in['expire_at']) ? strtotime($in['expire_at']) : false;
            if ($t === false || $t < $now + self::MIN_EXPIRY || $t > $now + self::MAX_EXPIRY) {
                $e['expire_at'] = 'An ISO 8601 time between 15 minutes and 180 days from now (default: 15 days).';
            } else {
                $expireAt = $t;
            }
        }
        $provider = is_string($in['provider'] ?? null) ? strtolower(trim($in['provider'])) : '';
        if ($provider !== '' && !isset(GatewayConnectionService::PROVIDERS[$provider])) {
            $e['provider'] = 'razorpay or cashfree (or leave it out for the default connection).';
        }
        $notify = is_array($in['notify'] ?? null) ? $in['notify'] : [];

        if ($e !== []) {
            throw ApiException::coded(422, 'validation_failed', 'The payment link request is invalid.', $e);
        }

        $pos   = $state;
        $intra = GstCalculator::supplyType($sellerState, $pos) === 'intra_state';
        $line  = GstCalculator::line((int) $base, (int) $rateBp, $basis, $intra);
        if ($strict) {
            $sent = ['taxable_paise' => (int) $f['taxable_paise'], 'cgst_paise' => (int) $f['cgst_paise'], 'sgst_paise' => (int) $f['sgst_paise'], 'igst_paise' => (int) $f['igst_paise']];
            $ours = ['taxable_paise' => $line['taxable'], 'cgst_paise' => $line['cgst'], 'sgst_paise' => $line['sgst'], 'igst_paise' => $line['igst']];
            if ($sent !== $ours || $line['total'] !== $amount) {
                throw ApiException::coded(422, 'fee_breakdown_mismatch',
                    'The GST split does not match Finance\'s invoice rules for this student\'s state (' . ($intra ? 'intra-state: CGST + SGST' : 'inter-state: IGST')
                    . '). Use Finance\'s figures, or fix the fee in Academy.',
                    ['expected' => $ours + ['amount_paise' => $line['total'], 'place_of_supply' => $pos, 'seller_state_code' => $sellerState,
                        'supply_type' => $intra ? 'intra_state' : 'inter_state'], 'sent' => $sent + ['amount_paise' => $amount]]);
            }
        }
        $discount = self::discount($f, (int) $base, $product, $desc, $sac, (int) $rateBp, $basis);
        if ($line['taxable'] >= InvoiceService::UNREGISTERED_ADDRESS_THRESHOLD_PAISE && ($address === '' || $pincode === '')) {
            throw ApiException::coded(422, 'validation_failed', 'The payment link request is invalid.', [
                'payer.address' => 'From a taxable value of ₹50,000 the invoice must carry the student\'s address and PIN (CGST Rule 46(e)).',
            ]);
        }

        return [
            'source_ref'    => $sourceRef,
            'academy_ref'   => $academyRef,
            'stream'        => $stream,
            'product_code'  => $product,
            'seller_code'   => $seller === '' ? null : $seller,
            'description'   => $desc,
            'payer'         => ['name' => $name, 'email' => $email === '' ? null : strtolower($email), 'mobile' => $mobile, 'state_code' => $state,
                'address' => $address === '' ? null : $address, 'pincode' => $pincode === '' ? null : $pincode],
            'amount_paise'  => $line['total'],
            'fee'           => ['taxable_paise' => $line['taxable'], 'cgst_paise' => $line['cgst'], 'sgst_paise' => $line['sgst'], 'igst_paise' => $line['igst'],
                'sac' => $sac, 'gst_rate' => Money::formatRate((int) $rateBp), 'price_basis' => $basis],
            'discount'      => $discount,
            'fee_breakdown' => $strict ? $f : ['computed_by' => 'finance', 'amount' => Money::format((int) $base)] + $f,
            'seller_state'  => $sellerState,
            'place_of_supply' => $pos,
            'supply_type'   => $intra ? 'intra_state' : 'inter_state',
            'expire_at'     => $expireAt,
            'provider'      => $provider === '' ? null : $provider,
            'notify_sms'    => ($notify['sms'] ?? false) === true,
            'notify_email'  => ($notify['email'] ?? false) === true,
        ];
    }

    /** The discount fields a fee may carry (same names and rules as invoice lines, docs/INVOICING.md "Discounted lines"). */
    public const DISCOUNT_FIELDS = ['list_amount_paise', 'list_amount', 'discount_paise', 'discount', 'discount_ref'];

    /**
     * Optional discount on the fee (a scholarship, a coupon, an Engage quote): fee.list_amount_paise + fee.discount_paise
     * (or list_amount / discount in rupees) + fee.discount_ref, on the fee's price basis. GST stays on the price after
     * discount, so the amount and split checked above are unchanged; the invoice prints "list less discount (ref)".
     * Checked now with the invoice-line rules (list − discount must be the fee as billed, ref required, net ≥ ₹1), so a
     * paid link can never be blocked by its discount later.
     *
     * @param array<string,mixed> $f
     * @return array{list_amount_paise:int, discount_paise:int, discount_ref:?string}|null
     */
    private static function discount(array $f, int $base, string $product, string $desc, string $sac, int $rateBp, string $basis): ?array
    {
        $given = array_intersect_key($f, array_flip(self::DISCOUNT_FIELDS));
        $given = array_filter($given, static fn ($v) => $v !== null && $v !== '');
        if ($given === []) {
            return null;
        }
        $errors = [];
        $lines  = InvoiceInput::lines([['item_code' => $product, 'item_kind' => 'academy_fee', 'description' => $desc, 'sac' => $sac,
            'gst_rate' => Money::formatRate($rateBp), 'price_basis' => $basis, 'amount_paise' => $base] + $given], $errors);
        if ($errors !== []) {
            $mapped = [];
            foreach ($errors as $k => $msg) {
                $field = str_replace('lines[0].', 'fee.', $k);
                $mapped[$field === 'fee.amount' ? 'fee.list_amount' : $field] = $field === 'fee.amount'
                    ? 'The list price less the discount must be the fee as billed (' . Money::format($base) . ').' : $msg;
            }
            throw ApiException::coded(422, 'validation_failed', 'The fee\'s discount is invalid.', $mapped);
        }
        $l = $lines[0];

        return ['list_amount_paise' => (int) $l['list_amount_paise'], 'discount_paise' => (int) $l['discount_paise'], 'discount_ref' => $l['discount_ref']];
    }

    /** "+91 98765-43210" | "919876543210" | "09876543210" | "9876543210" → "+919876543210"; null when not an Indian mobile. */
    public static function mobile(mixed $v): ?string
    {
        if (!is_string($v) && !is_int($v)) {
            return null;
        }
        $d = (string) preg_replace('/[\s().-]/', '', (string) $v);
        if (preg_match('/^(?:\+91|91|0)?([6-9][0-9]{9})$/', $d, $m)) {
            return '+91' . $m[1];
        }

        return null;
    }

    private static function ref(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : '';

        return preg_match(self::REF_PATTERN, $s) ? $s : null;
    }

    private static function present(mixed $v): bool
    {
        return $v !== null && $v !== '';
    }

    private static function paiseInt(mixed $v, bool $allowZero = false): ?int
    {
        if (!is_int($v) || $v > Money::MAX_PAISE || $v < 0 || (!$allowZero && $v === 0)) {
            return null;
        }

        return $v;
    }
}
