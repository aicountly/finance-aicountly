-- =============================================================================
-- Migration 009: an Aicountly seller buys licences
--
-- Sellers in Aicountly's seller programme (Engage, seller.aicountly.com) buy
-- licences — one order, one or more plans, any quantity — and apply them to
-- their customers' accounts. Engage asks Finance for a payment link for the
-- order through the route Academy fees and AI tokens already use; the seller
-- pays on the provider's page; Finance re-reads the payment, issues the GST
-- invoice to the seller's business and tells Engage, which creates the
-- seller's licence stock (engage-aicountly docs/SELLING_LICENCES.md §7, §9).
--
-- THREE THINGS CHANGE.
--
-- 1. A fourth buyer kind, `reseller`: an Aicountly seller buying for resale.
--    It is a business, usually GST-registered, and it is not a Manage company
--    (the licences go to its customers' accounts, not its own), so it carries
--    no cmp_id and no buyer_uuid, and it is invoiced only for a verified
--    gateway payment. Its invoices are not in Manage's document reads, which
--    filter by cmp_id: the seller sees the invoice number in the seller portal.
--
-- 2. purpose `licences` on fin_payment_links. An order has several lines, each
--    with its own SAC, GST rate and price basis, so the order's lines are kept
--    in `lines` (JSONB; immutable with the rest of the demand, by the existing
--    trigger) and the single-line fee_sac / fee_gst_rate / fee_price_basis are
--    null on such a link. fee_*_paise and amount_paise are the order's totals,
--    so every existing check on them still holds. Only Engage raises them.
--
-- 3. One open and one paid link per order (source_ref, the SLO-… reference),
--    as for token orders.
--
-- Additive: no existing row changes meaning, and an invoice is still only
-- ever born from a payment Finance has verified against the provider.
--
-- No BEGIN/COMMIT here: App\Database\Migrator wraps every migration in its own
-- transaction.
-- =============================================================================

-- ------------------------------------------------------------------ buyer kind

ALTER TABLE fin_invoices DROP CONSTRAINT IF EXISTS fin_invoices_buyer_kind_check;
ALTER TABLE fin_invoices DROP CONSTRAINT IF EXISTS fin_invoices_buyer_kind_cmp;
ALTER TABLE fin_invoices ADD CONSTRAINT fin_invoices_buyer_kind_check
    CHECK (buyer_kind IN ('company', 'academy_student', 'saas_customer', 'reseller'));
ALTER TABLE fin_invoices ADD CONSTRAINT fin_invoices_buyer_kind_cmp CHECK (
       (buyer_kind = 'company' AND cmp_id IS NOT NULL)
    OR (buyer_kind = 'academy_student' AND cmp_id IS NULL AND source = 'payment' AND buyer_gstin IS NULL)
    OR (buyer_kind = 'saas_customer' AND cmp_id IS NOT NULL AND source = 'payment' AND buyer_uuid IS NOT NULL)
    -- An Aicountly seller buying licences to resell: a business with no Manage company here.
    OR (buyer_kind = 'reseller' AND cmp_id IS NULL AND buyer_uuid IS NULL AND source = 'payment')
);

ALTER TABLE fin_credit_notes DROP CONSTRAINT IF EXISTS fin_credit_notes_buyer_kind_check;
ALTER TABLE fin_credit_notes DROP CONSTRAINT IF EXISTS fin_credit_notes_buyer_kind_cmp;
ALTER TABLE fin_credit_notes ADD CONSTRAINT fin_credit_notes_buyer_kind_check
    CHECK (buyer_kind IN ('company', 'academy_student', 'saas_customer', 'reseller'));
ALTER TABLE fin_credit_notes ADD CONSTRAINT fin_credit_notes_buyer_kind_cmp CHECK (
       (buyer_kind = 'company' AND cmp_id IS NOT NULL)
    OR (buyer_kind = 'academy_student' AND cmp_id IS NULL)
    OR (buyer_kind = 'saas_customer' AND cmp_id IS NOT NULL)
    OR (buyer_kind = 'reseller' AND cmp_id IS NULL)
);

-- --------------------------------------------------------- payment link purpose

ALTER TABLE fin_payment_links DROP CONSTRAINT IF EXISTS fin_payment_links_purpose_chk;
ALTER TABLE fin_payment_links ADD CONSTRAINT fin_payment_links_purpose_chk
    CHECK (purpose IN ('academy_fee', 'tokens', 'licences'));

-- The order's lines, as Finance checked them: [{line_no, product_code, offer_code, offer_version, description, sac,
-- gst_rate, price_basis, quantity, unit_price_paise, amount_paise, taxable_paise, cgst_paise, sgst_paise, igst_paise,
-- total_paise}]. amount_paise is what an invoice line bills (the taxable value on an exclusive price, the gross on an
-- inclusive one); the line totals add up to the link's amount_paise.
ALTER TABLE fin_payment_links ADD COLUMN IF NOT EXISTS lines JSONB;

-- One line, one SAC / rate / basis: true of a fee or a token purchase, not of an order of several plans.
ALTER TABLE fin_payment_links ALTER COLUMN fee_sac         DROP NOT NULL;
ALTER TABLE fin_payment_links ALTER COLUMN fee_gst_rate    DROP NOT NULL;
ALTER TABLE fin_payment_links ALTER COLUMN fee_price_basis DROP NOT NULL;
ALTER TABLE fin_payment_links DROP CONSTRAINT IF EXISTS fin_payment_links_lines_chk;
ALTER TABLE fin_payment_links ADD CONSTRAINT fin_payment_links_lines_chk CHECK (
       (purpose <> 'licences' AND lines IS NULL
        AND fee_sac IS NOT NULL AND fee_gst_rate IS NOT NULL AND fee_price_basis IS NOT NULL)
    OR (purpose = 'licences' AND source_app = 'engage'
        AND jsonb_typeof(lines) = 'array' AND jsonb_array_length(lines) BETWEEN 1 AND 50
        AND fee_sac IS NULL AND fee_gst_rate IS NULL AND fee_price_basis IS NULL)
);

ALTER TABLE fin_payment_links DROP CONSTRAINT IF EXISTS fin_payment_links_purpose_fields_chk;
ALTER TABLE fin_payment_links ADD CONSTRAINT fin_payment_links_purpose_fields_chk CHECK (
       (purpose = 'academy_fee'
        AND academy_ref IS NOT NULL AND stream IN ('edge', 'aspire')
        AND cmp_id IS NULL AND buyer_uuid IS NULL AND token_count IS NULL)
    OR (purpose = 'tokens'
        AND academy_ref IS NULL AND stream IS NULL
        AND cmp_id IS NOT NULL AND buyer_uuid IS NOT NULL AND token_count > 0)
    -- The seller (seller_code) is the buyer; the order (source_ref) is what is paid for.
    OR (purpose = 'licences'
        AND academy_ref IS NULL AND stream IS NULL
        AND cmp_id IS NULL AND buyer_uuid IS NULL AND token_count IS NULL
        AND seller_code IS NOT NULL AND source_ref IS NOT NULL)
);

-- A seller must never be shown two live links for one order.
CREATE UNIQUE INDEX IF NOT EXISTS fin_payment_links_licences_open_uniq
    ON fin_payment_links (source_ref)
    WHERE purpose = 'licences' AND status IN ('pending_approval', 'creating', 'created', 'partially_paid');
CREATE UNIQUE INDEX IF NOT EXISTS fin_payment_links_licences_paid_uniq
    ON fin_payment_links (source_ref)
    WHERE purpose = 'licences' AND status = 'paid';

COMMENT ON COLUMN fin_payment_links.purpose IS
    'academy_fee (a student fee), tokens (a SaaS customer buying AI tokens) or licences (an Aicountly seller buying licences). Decides which fields are required.';
COMMENT ON COLUMN fin_payment_links.lines IS
    'licences only: the order lines as Finance checked them (GST per line, as invoiced). Null for a single-fee link.';
