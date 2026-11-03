-- =============================================================================
-- Migration 008: a customer can buy AI tokens
--
-- Finance already collects money for one thing that is not a company licence —
-- Aicountly Academy student fees — and 005 left the seam for the next one:
--
--   "Any future SaaS checkout should record its gateway payments in
--    fin_gateway_payments too, so the payment:<id> namespace stays single."
--
-- This is that checkout. A workspace owner buys AI tokens from Manage, Engage
-- raises the payment link through the route Academy already uses, the customer
-- pays on the provider's hosted page, Finance re-reads the payment from the
-- provider and issues a GST invoice. Engage then grants the tokens.
--
-- THREE THINGS HAD TO CHANGE.
--
-- 1. A third buyer kind. `company` demands a cmp_id and forbids nothing else;
--    `academy_student` FORBIDS a cmp_id, which means Manage — whose reads filter
--    by cmp_id — cannot see such an invoice at all. A token purchase must be
--    visible to the customer who made it, so `saas_customer` REQUIRES BOTH a
--    cmp_id (so Manage can show it) and a buyer_uuid (so it is attributable to
--    the licence holder who will be given the tokens). It is the first buyer kind
--    where buyer_uuid is not optional.
--
-- 2. buyer_uuid gets an index. It has been a nullable, unindexed audit crumb that
--    nothing queried and that the one live gateway flow hard-codes to NULL. The
--    moment tokens are granted on the strength of a payment, "which invoices
--    belong to this holder" is a question that has to have an answer.
--
-- 3. fin_payment_links stops being Academy-only. academy_ref and stream were NOT
--    NULL with stream restricted to edge|aspire. They are now required only for
--    an academy_fee link, and a `purpose` column says which kind a row is. Every
--    existing row is an academy_fee and is unchanged.
--
-- Additive. No existing row changes meaning, and nothing here can issue an
-- invoice by itself: an invoice is still only ever born from a payment Finance
-- has verified against the provider.
--
-- No BEGIN/COMMIT here: App\Database\Migrator wraps every migration in its own
-- transaction, and a COMMIT inside the file would end that transaction early and
-- leave the Migrator with nothing to commit.
-- =============================================================================

-- ------------------------------------------------------------------ buyer kind

ALTER TABLE fin_invoices DROP CONSTRAINT IF EXISTS fin_invoices_buyer_kind_check;
ALTER TABLE fin_invoices DROP CONSTRAINT IF EXISTS fin_invoices_buyer_kind_cmp;
ALTER TABLE fin_invoices ADD CONSTRAINT fin_invoices_buyer_kind_check
    CHECK (buyer_kind IN ('company', 'academy_student', 'saas_customer'));
ALTER TABLE fin_invoices ADD CONSTRAINT fin_invoices_buyer_kind_cmp CHECK (
       (buyer_kind = 'company' AND cmp_id IS NOT NULL)
    OR (buyer_kind = 'academy_student' AND cmp_id IS NULL AND source = 'payment' AND buyer_gstin IS NULL)
    -- A self-serve purchase by a signed-in customer: always a gateway payment, always
    -- attributable to the licence holder, always visible to the company they bought from.
    -- GSTIN stays optional — a registered business buying tokens gets it on the invoice.
    OR (buyer_kind = 'saas_customer' AND cmp_id IS NOT NULL AND source = 'payment' AND buyer_uuid IS NOT NULL)
);

ALTER TABLE fin_credit_notes DROP CONSTRAINT IF EXISTS fin_credit_notes_buyer_kind_check;
ALTER TABLE fin_credit_notes DROP CONSTRAINT IF EXISTS fin_credit_notes_buyer_kind_cmp;
ALTER TABLE fin_credit_notes ADD CONSTRAINT fin_credit_notes_buyer_kind_check
    CHECK (buyer_kind IN ('company', 'academy_student', 'saas_customer'));
ALTER TABLE fin_credit_notes ADD CONSTRAINT fin_credit_notes_buyer_kind_cmp CHECK (
       (buyer_kind = 'company' AND cmp_id IS NOT NULL)
    OR (buyer_kind = 'academy_student' AND cmp_id IS NULL)
    OR (buyer_kind = 'saas_customer' AND cmp_id IS NOT NULL)
);

-- "Which invoices belong to this holder" must have an answer once tokens are
-- granted on the strength of one.
CREATE INDEX IF NOT EXISTS fin_invoices_buyer_uuid_idx ON fin_invoices (buyer_uuid) WHERE buyer_uuid IS NOT NULL;

-- --------------------------------------------------------- payment link purpose

ALTER TABLE fin_payment_links ADD COLUMN IF NOT EXISTS purpose TEXT NOT NULL DEFAULT 'academy_fee';
ALTER TABLE fin_payment_links DROP CONSTRAINT IF EXISTS fin_payment_links_purpose_chk;
ALTER TABLE fin_payment_links ADD CONSTRAINT fin_payment_links_purpose_chk
    CHECK (purpose IN ('academy_fee', 'tokens'));

-- Who the tokens are for. Both are required for a `tokens` link and meaningless on
-- an academy fee, which is enforced below rather than left to the caller.
ALTER TABLE fin_payment_links ADD COLUMN IF NOT EXISTS cmp_id      BIGINT;
ALTER TABLE fin_payment_links ADD COLUMN IF NOT EXISTS buyer_uuid  TEXT;
ALTER TABLE fin_payment_links ADD COLUMN IF NOT EXISTS token_count BIGINT;
ALTER TABLE fin_payment_links ADD COLUMN IF NOT EXISTS buyer_gstin TEXT;

-- academy_ref and stream were NOT NULL for every row because every row was an
-- academy fee. They stay required for those, and are dropped for a token purchase,
-- which has neither an admission reference nor a course stream.
ALTER TABLE fin_payment_links ALTER COLUMN academy_ref DROP NOT NULL;
ALTER TABLE fin_payment_links ALTER COLUMN stream      DROP NOT NULL;
ALTER TABLE fin_payment_links DROP CONSTRAINT IF EXISTS fin_payment_links_stream_check;
ALTER TABLE fin_payment_links DROP CONSTRAINT IF EXISTS fin_payment_links_purpose_fields_chk;
ALTER TABLE fin_payment_links ADD CONSTRAINT fin_payment_links_purpose_fields_chk CHECK (
       (purpose = 'academy_fee'
        AND academy_ref IS NOT NULL AND stream IN ('edge', 'aspire')
        AND cmp_id IS NULL AND buyer_uuid IS NULL AND token_count IS NULL)
    OR (purpose = 'tokens'
        AND academy_ref IS NULL AND stream IS NULL
        AND cmp_id IS NOT NULL AND buyer_uuid IS NOT NULL AND token_count > 0)
);

-- One open and one paid link per academy_ref was enforced by two partial unique
-- indexes. The same rule has to hold per token order, and academy_ref is null for
-- those, so the ordering key becomes source_ref (Engage's order reference) for a
-- token link. Without this a customer could be shown two live links for one order
-- and pay both.
CREATE UNIQUE INDEX IF NOT EXISTS fin_payment_links_tokens_open_uniq
    ON fin_payment_links (source_ref)
    WHERE purpose = 'tokens' AND status IN ('pending_approval', 'creating', 'created', 'partially_paid');
CREATE UNIQUE INDEX IF NOT EXISTS fin_payment_links_tokens_paid_uniq
    ON fin_payment_links (source_ref)
    WHERE purpose = 'tokens' AND status = 'paid';

CREATE INDEX IF NOT EXISTS fin_payment_links_buyer_uuid_idx
    ON fin_payment_links (buyer_uuid) WHERE buyer_uuid IS NOT NULL;

COMMENT ON COLUMN fin_payment_links.purpose IS
    'academy_fee (a student fee) or tokens (a SaaS customer buying AI tokens). Decides which fields are required.';
COMMENT ON COLUMN fin_payment_links.token_count IS
    'Tokens the customer is buying. Engage grants exactly this many once Finance confirms the payment.';
