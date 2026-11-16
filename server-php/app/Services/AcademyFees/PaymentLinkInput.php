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
    /**
     * What is being paid for. Decides which fields are required — and the database
     * says the same thing, so a caller cannot talk its way past this (migration 008,
     * fin_payment_links_purpose_fields_chk).
     *
     *   academy_fee  a student's course fee: an admission reference and a course
     *                stream, no Manage company, no GSTIN.
     *   tokens       a signed-in customer buying AI tokens: a company (so Manage can
     *                show them the invoice), a licence holder (so the tokens can be
     *                granted to the right account), a token count, and optionally a
     *                GSTIN if they are a registered business.
     *   licences     an Aicountly seller buying licences to resell (Engage only, migration
     *                009): the seller (seller_code) is the buyer, invoiced as a business
     *                with its GSTIN when registered; an order of one or more lines, each
     *                with its own SAC, rate, price basis and GST split (validateLicences()).
     */
    public const PURPOSES         = ['academy_fee', 'tokens', 'licences'];

    /** Lines of one licence order (an invoice takes up to InvoiceInput::MAX_LINES). */
    public const MAX_LICENCE_LINES = 50;
    public const MAX_LICENCE_QUANTITY = 100000;

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
        $purpose = is_string($in['purpose'] ?? null) ? strtolower(trim($in['purpose'])) : 'academy_fee';
        if ($purpose === 'licences') {
            if (!$strict) {
                throw ApiException::coded(422, 'validation_failed', 'The payment link request is invalid.',
                    ['purpose' => 'A licence order\'s link is raised by Engage, from the seller\'s order — not by hand.']);
            }

            return self::validateLicences($in, $sellerState, $now);
        }
        if (!in_array($purpose, self::PURPOSES, true)) {
            $e['purpose'] = 'academy_fee (a student fee), tokens (a customer buying AI tokens) or licences (a seller buying licences).';
            $purpose = 'academy_fee';
        }
        $tokens = $purpose === 'tokens';

        $academyRef = self::ref($in['academy_ref'] ?? null);
        $stream     = is_string($in['stream'] ?? null) ? strtolower(trim($in['stream'])) : '';
        $cmpId = null;
        $holder = null;
        $tokenCount = null;
        $buyerGstin = null;

        if ($tokens) {
            // Academy's two identifiers are meaningless here and are refused rather than
            // ignored: a caller that sends them has confused the two flows, and silently
            // dropping them would hide that until somebody read the database.
            foreach (['academy_ref' => $academyRef, 'stream' => $stream === '' ? null : $stream] as $k => $v) {
                if ($v !== null) {
                    $e[$k] = 'Not used for a token purchase.';
                }
            }
            $academyRef = null;
            $stream     = '';

            $cmpId = self::posInt($in['cmp_id'] ?? null);
            if ($cmpId === null) {
                // Without it the invoice is invisible to the customer who paid for it:
                // Manage's document reads filter by cmp_id.
                $e['cmp_id'] = 'The Manage company id, or the customer cannot see their own invoice.';
            }
            $holder = is_scalar($in['buyer_uuid'] ?? null) ? trim((string) $in['buyer_uuid']) : '';
            if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $holder) !== 1) {
                $e['buyer_uuid'] = 'The licence holder\'s my.aicountly uuid: the tokens are granted to them.';
                $holder = null;
            }
            $tokenCount = self::posInt($in['token_count'] ?? null);
            if ($tokenCount === null || $tokenCount > 100000000000) {
                $e['token_count'] = 'How many tokens the customer is buying (a whole number above zero).';
            }
            $buyerGstin = Gstin::normalise($in['buyer_gstin'] ?? null);
            if ($buyerGstin !== '' && ($problem = Gstin::problem($buyerGstin)) !== null) {
                $e['buyer_gstin'] = $problem;
            }
        } else {
            if ($academyRef === null) {
                $e['academy_ref'] = 'The Academy admission / fee-account reference is required (1–64 letters, digits or _ . : / -).';
            }
            if (!isset(self::STREAMS[$stream])) {
                $e['stream'] = 'edge (Aicountly Edge) or aspire (Aicountly Aspire).';
            }
        }

        $product = is_string($in['product_code'] ?? null) ? strtolower(trim($in['product_code'])) : '';
        if ($product === '' && isset(self::STREAMS[$stream])) {
            $product = $stream;
        }
        if ($product === '' && $tokens) {
            // The AI tokens are sold by the platform rather than by one application.
            $product = 'manage';
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
            $e['payer.name'] = $tokens ? 'The payer\'s name (as on the invoice).' : 'The student\'s name (as on the invoice).';
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
            $e['payer.state_code'] = 'The payer\'s state as a GST state code (two digits, e.g. 27 for Maharashtra): it is the place of supply.';
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
            'purpose'       => $purpose,
            'cmp_id'        => $cmpId,
            'buyer_uuid'    => $holder,
            'token_count'   => $tokenCount,
            'buyer_gstin'   => ($buyerGstin ?? '') !== '' ? $buyerGstin : null,
            'source_ref'    => $sourceRef,
            'academy_ref'   => $academyRef,
            'stream'        => $stream === '' ? null : $stream,
            'product_code'  => $product,
            'seller_code'   => $seller === '' ? null : $seller,
            'description'   => $desc,
            'payer'         => ['name' => $name, 'email' => $email === '' ? null : strtolower($email), 'mobile' => $mobile, 'state_code' => $state,
                'address' => $address === '' ? null : $address, 'pincode' => $pincode === '' ? null : $pincode],
            'amount_paise'  => $line['total'],
            'fee'           => ['taxable_paise' => $line['taxable'], 'cgst_paise' => $line['cgst'], 'sgst_paise' => $line['sgst'], 'igst_paise' => $line['igst'],
                'sac' => $sac, 'gst_rate' => Money::formatRate((int) $rateBp), 'price_basis' => $basis],
            'discount'      => $discount,
            'lines'         => null,
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

    /**
     * A seller's licence order (purpose licences; Engage only, strict). Engage prices every line — the offer's price on
     * its basis × quantity, GST once on the line amount — and Finance checks each line against its own invoice rules
     * (GstCalculator::line, the split by the place of supply), so the invoice can always be issued for the amount paid.
     *
     *   {purpose: licences, source_ref: SLO-…, seller_code: SLR-…, buyer_gstin?, description?, product_code?,
     *    payer{name (legal name), email?, mobile, state_code, address?, pincode?}, amount_paise,
     *    lines[{product_code, offer_code, offer_version?, description, quantity, unit_price_paise, amount_paise,
     *           price_basis, gst_rate, sac, taxable_paise, cgst_paise, sgst_paise, igst_paise}],
     *    fee?{taxable_paise, cgst_paise, sgst_paise, igst_paise} (the totals, checked when sent), expire_at?, provider?, notify?}
     *
     * The buyer is the seller's business. A GSTIN must be registered in payer.state_code (422 gstin_state_mismatch): the
     * place of supply of a registered buyer is its GSTIN's state, and the split on the link must be the invoice's. A
     * registered buyer's invoice carries its address and PIN (CGST Rule 46(d)); an unregistered one needs them from a
     * taxable value of ₹50,000 (Rule 46(e)). Lines carry no discount: a seller pays the published price.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed> the same keys as validate(), plus lines (the checked order lines)
     */
    private static function validateLicences(array $in, string $sellerState, int $now): array
    {
        $e = [];
        $sourceRef = self::ref($in['source_ref'] ?? null);
        if ($sourceRef === null) {
            $e['source_ref'] = 'The Engage licence order reference is required (1–64 letters, digits or _ . : / -).';
        }
        $seller = is_string($in['seller_code'] ?? null) ? trim($in['seller_code']) : '';
        if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $seller)) {
            $e['seller_code'] = 'The buying seller\'s code, such as SLR-000123.';
        }
        foreach (['academy_ref', 'stream', 'cmp_id', 'buyer_uuid', 'token_count'] as $k) {
            if (self::present($in[$k] ?? null)) {
                $e[$k] = 'Not used for a licence purchase.';
            }
        }
        $product = is_string($in['product_code'] ?? null) && trim($in['product_code']) !== '' ? strtolower(trim($in['product_code'])) : 'licences';
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $product)) {
            $e['product_code'] = 'Lower case letters, digits, _ . - (or leave it out).';
        }
        $desc = InvoiceInput::text($in['description'] ?? null, 300);
        if ($desc === '' && $sourceRef !== null) {
            $desc = 'Aicountly licences — order ' . $sourceRef;
        }

        $p     = is_array($in['payer'] ?? null) ? $in['payer'] : [];
        $name  = InvoiceInput::text($p['name'] ?? null, 100);
        if ($name === '') {
            $e['payer.name'] = 'The seller\'s legal name, as on the invoice.';
        }
        $email = InvoiceInput::text($p['email'] ?? null, 254);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $e['payer.email'] = 'A valid email address.';
        }
        $mobile = self::mobile($p['mobile'] ?? null);
        if ($mobile === null) {
            $e['payer.mobile'] = 'An Indian mobile number (10 digits, optionally with +91): the payment page needs one.';
        }
        $state = trim(is_scalar($p['state_code'] ?? null) ? (string) $p['state_code'] : '');
        if (preg_match('/^\d$/', $state)) {
            $state = '0' . $state;
        }
        if (!Gstin::isStateCode($state)) {
            $e['payer.state_code'] = 'The seller\'s state as a GST state code (two digits, e.g. 27 for Maharashtra): it is the place of supply.';
        }
        $address = InvoiceInput::text($p['address'] ?? null, 300, true);
        $pincode = trim(is_scalar($p['pincode'] ?? null) ? (string) $p['pincode'] : '');
        if ($pincode !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            $e['payer.pincode'] = 'A six-digit PIN code.';
        }
        $gstin = Gstin::normalise($in['buyer_gstin'] ?? null);
        if ($gstin !== '' && ($problem = Gstin::problem($gstin)) !== null) {
            $e['buyer_gstin'] = $problem;
        }

        $amount = self::paiseInt($in['amount_paise'] ?? null);
        if ($amount === null || $amount < 100) {
            $e['amount_paise'] = 'The amount the seller pays, in paise (an integer, at least 100).';
        }
        $rawLines = $in['lines'] ?? null;
        $lines = [];
        if (!is_array($rawLines) || $rawLines === [] || !array_is_list($rawLines)) {
            $e['lines'] = 'The order\'s lines: at least one.';
            $rawLines = [];
        } elseif (count($rawLines) > self::MAX_LICENCE_LINES) {
            $e['lines'] = 'At most ' . self::MAX_LICENCE_LINES . ' lines in one order.';
            $rawLines = [];
        }
        foreach ($rawLines as $i => $l) {
            $k = "lines[{$i}].";
            if (!is_array($l)) {
                $e[$k] = 'Each line is an object.';
                continue;
            }
            $lp = is_string($l['product_code'] ?? null) ? strtolower(trim($l['product_code'])) : '';
            if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $lp)) {
                $e[$k . 'product_code'] = 'The Engage product code.';
            }
            $offer = is_string($l['offer_code'] ?? null) ? strtolower(trim($l['offer_code'])) : '';
            if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $offer)) {
                $e[$k . 'offer_code'] = 'The Engage offer code.';
            }
            $version = $l['offer_version'] ?? null;
            if ($version !== null && (!is_int($version) || $version < 1)) {
                $e[$k . 'offer_version'] = 'A version number from 1.';
            }
            $ld = InvoiceInput::text($l['description'] ?? null, 300);
            if ($ld === '') {
                $e[$k . 'description'] = 'What the line is, as printed on the invoice.';
            }
            $qty = $l['quantity'] ?? null;
            if (!is_int($qty) || $qty < 1 || $qty > self::MAX_LICENCE_QUANTITY) {
                $e[$k . 'quantity'] = 'How many licences (1 to ' . self::MAX_LICENCE_QUANTITY . ').';
            }
            $unit = self::paiseInt($l['unit_price_paise'] ?? null);
            $la   = self::paiseInt($l['amount_paise'] ?? null);
            if ($unit === null) {
                $e[$k . 'unit_price_paise'] = 'The price of one licence in paise, on the line\'s price basis.';
            }
            if ($la === null) {
                $e[$k . 'amount_paise'] = 'The line amount in paise: the unit price × the quantity.';
            } elseif ($unit !== null && is_int($qty) && $la !== $unit * $qty) {
                $e[$k . 'amount_paise'] = 'Must be the unit price × the quantity (' . ($unit * $qty) . ').';
            }
            $sac = trim(is_scalar($l['sac'] ?? null) ? (string) $l['sac'] : '');
            if (!preg_match('/^99[0-9]{4}$/', $sac)) {
                $e[$k . 'sac'] = 'A six-digit SAC (services start with 99).';
            }
            $rateBp = Money::parseRate($l['gst_rate'] ?? null, 9999);
            if ($rateBp === null) {
                $e[$k . 'gst_rate'] = 'The GST rate in percent (e.g. 18), at least 0 and below 100.';
            }
            $basis = is_string($l['price_basis'] ?? null) ? strtolower(trim($l['price_basis'])) : '';
            if (!in_array($basis, [GstCalculator::INCLUSIVE, GstCalculator::EXCLUSIVE], true)) {
                $e[$k . 'price_basis'] = 'inclusive (the price includes GST) or exclusive (GST is added).';
            }
            $sent = [];
            foreach (['taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise'] as $f) {
                $sent[$f] = self::paiseInt($l[$f] ?? null, true);
                if ($sent[$f] === null) {
                    $e[$k . $f] = 'Required: a non-negative integer in paise.';
                }
            }
            foreach (PaymentLinkInput::DISCOUNT_FIELDS as $f) {
                if (self::present($l[$f] ?? null)) {
                    $e[$k . $f] = 'A seller pays the published price: no discount on a licence order.';
                }
            }
            $lines[] = ['line_no' => $i + 1, 'product_code' => $lp, 'offer_code' => $offer, 'offer_version' => is_int($version) ? $version : null,
                'description' => $ld, 'sac' => $sac, 'rate_bp' => $rateBp, 'price_basis' => $basis, 'quantity' => is_int($qty) ? $qty : 0,
                'unit_price_paise' => $unit, 'amount_paise' => $la, 'sent' => $sent];
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

        // A registered buyer is supplied at its GSTIN's state (GstCalculator::placeOfSupply): the state Engage priced
        // the order for must be that one, or the link would collect one split and the invoice show another.
        if ($gstin !== '' && Gstin::stateOf($gstin) !== $state) {
            throw ApiException::coded(422, 'gstin_state_mismatch',
                'The GSTIN ' . $gstin . ' is registered in ' . Gstin::stateName(Gstin::stateOf($gstin)) . ' (' . Gstin::stateOf($gstin) . '), but the order was priced for '
                . Gstin::stateName($state) . ' (' . $state . '). Correct the seller\'s state or GSTIN in their KYC, then place the order again.',
                ['gstin_state_code' => Gstin::stateOf($gstin), 'state_code' => $state]);
        }

        $intra    = GstCalculator::supplyType($sellerState, $state) === 'intra_state';
        $checked  = [];
        $expected = [];
        $mismatch = false;
        $totals   = ['taxable_paise' => 0, 'cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 0, 'total_paise' => 0];
        foreach ($lines as $l) {
            // An invoice line bills amount_paise on its basis: the taxable value when exclusive, the gross when inclusive.
            $c    = GstCalculator::line((int) $l['amount_paise'], (int) $l['rate_bp'], $l['price_basis'], $intra);
            $ours = ['taxable_paise' => $c['taxable'], 'cgst_paise' => $c['cgst'], 'sgst_paise' => $c['sgst'], 'igst_paise' => $c['igst']];
            $mismatch = $mismatch || $ours !== $l['sent'];
            $expected[] = $ours + ['total_paise' => $c['total']];
            $checked[]  = [
                'line_no' => $l['line_no'], 'product_code' => $l['product_code'], 'offer_code' => $l['offer_code'], 'offer_version' => $l['offer_version'],
                'description' => $l['description'], 'sac' => $l['sac'], 'gst_rate' => Money::formatRate((int) $l['rate_bp']), 'price_basis' => $l['price_basis'],
                'quantity' => $l['quantity'], 'unit_price_paise' => $l['unit_price_paise'], 'amount_paise' => $l['amount_paise'],
            ] + $ours + ['total_paise' => $c['total']];
            foreach ($totals as $f => $_) {
                $totals[$f] += $f === 'total_paise' ? $c['total'] : $ours[$f];
            }
        }
        $fee = is_array($in['fee'] ?? null) ? $in['fee'] : [];
        foreach (['taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise'] as $f) {
            if (array_key_exists($f, $fee) && $fee[$f] !== $totals[$f]) {
                $mismatch = true;
            }
        }
        if ($mismatch || $totals['total_paise'] !== $amount) {
            throw ApiException::coded(422, 'fee_breakdown_mismatch',
                'The GST split does not match Finance\'s invoice rules for this seller\'s state (' . ($intra ? 'intra-state: CGST + SGST' : 'inter-state: IGST')
                . '). Use Finance\'s figures.',
                ['expected' => ['lines' => $expected, 'amount_paise' => $totals['total_paise'], 'place_of_supply' => $state, 'seller_state_code' => $sellerState,
                    'supply_type' => $intra ? 'intra_state' : 'inter_state'], 'sent' => ['lines' => array_column($lines, 'sent'), 'amount_paise' => $amount]]);
        }
        if (($address === '' || $pincode === '') && ($gstin !== '' || $totals['taxable_paise'] >= InvoiceService::UNREGISTERED_ADDRESS_THRESHOLD_PAISE)) {
            throw ApiException::coded(422, 'validation_failed', 'The payment link request is invalid.', [
                'payer.address' => $gstin !== ''
                    ? 'A registered buyer\'s invoice carries its address and PIN (CGST Rule 46(d)).'
                    : 'From a taxable value of ₹50,000 the invoice must carry the buyer\'s address and PIN (CGST Rule 46(e)).',
            ]);
        }

        return [
            'purpose'       => 'licences',
            'cmp_id'        => null,
            'buyer_uuid'    => null,
            'token_count'   => null,
            'buyer_gstin'   => $gstin !== '' ? $gstin : null,
            'source_ref'    => $sourceRef,
            'academy_ref'   => null,
            'stream'        => null,
            'product_code'  => $product,
            'seller_code'   => $seller,
            'description'   => $desc,
            'payer'         => ['name' => $name, 'email' => $email === '' ? null : strtolower($email), 'mobile' => $mobile, 'state_code' => $state,
                'address' => $address === '' ? null : $address, 'pincode' => $pincode === '' ? null : $pincode],
            'amount_paise'  => $totals['total_paise'],
            'fee'           => ['taxable_paise' => $totals['taxable_paise'], 'cgst_paise' => $totals['cgst_paise'], 'sgst_paise' => $totals['sgst_paise'],
                'igst_paise' => $totals['igst_paise'], 'sac' => null, 'gst_rate' => null, 'price_basis' => null],
            'discount'      => null,
            'lines'         => $checked,
            'fee_breakdown' => $fee + ['lines' => count($checked)],
            'seller_state'  => $sellerState,
            'place_of_supply' => $state,
            'supply_type'   => $intra ? 'intra_state' : 'inter_state',
            'expire_at'     => $expireAt,
            'provider'      => $provider === '' ? null : $provider,
            'notify_sms'    => ($notify['sms'] ?? false) === true,
            'notify_email'  => ($notify['email'] ?? false) === true,
        ];
    }

    /** A positive whole number, or null. */
    private static function posInt(mixed $v): ?int
    {
        if (!is_scalar($v) || trim((string) $v) === '' || preg_match('/^\d+$/', trim((string) $v)) !== 1) {
            return null;
        }
        $n = (int) trim((string) $v);

        return $n > 0 ? $n : null;
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
