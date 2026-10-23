-- 005_academy_fees — Aicountly Academy fees (Aicountly Edge, Aicountly Aspire) collected through Razorpay / Cashfree
-- payment links (docs/ACADEMY_PAYMENTS.md).
--
-- Numbered 005 (not 004) on purpose: the unmerged branch claude/rectify-2026-10 already carries
-- 004_invoice_line_discounts. The migrator applies pending files in name order, so either can land first.
--
-- Dormant until configured: nothing here is used until a finance admin saves a gateway connection, and the Engage routes
-- answer 503 until FINANCE_INBOUND_ENGAGE_SECRET_<KEYID> is set. Payouts and subscription invoicing are unchanged for
-- every existing row (buyer_kind defaults to 'company' and keeps cmp_id required).
--
-- Money: BIGINT paise. Gateway secrets: DataVault ciphertext ("v1:…", FINANCE_DATA_KEY), never returned by the API.

-- ------------------------------------------------------------------ gateway connections (finance_admin)

-- One row per provider and mode (test / live). key_id is the provider's public identifier (Razorpay key id
-- rzp_live_…, Cashfree app id / x-client-id); the API secret and the webhook secret are encrypted with DataVault.
-- Exactly one enabled connection may be the default (used when Engage or staff do not name a provider).
CREATE TABLE fin_gateway_connections (
    id                  BIGSERIAL PRIMARY KEY,
    provider            TEXT NOT NULL CHECK (provider IN ('razorpay','cashfree')),
    mode                TEXT NOT NULL CHECK (mode IN ('test','live')),
    label               TEXT NOT NULL CHECK (btrim(label) <> ''),
    key_id              TEXT NOT NULL CHECK (key_id ~ '^[A-Za-z0-9_.:-]{6,100}$'),
    key_secret_enc      TEXT NOT NULL CHECK (key_secret_enc LIKE 'v1:%'),
    key_secret_last4    TEXT NOT NULL,
    webhook_secret_enc  TEXT CHECK (webhook_secret_enc IS NULL OR webhook_secret_enc LIKE 'v1:%'),
    enabled             BOOLEAN NOT NULL DEFAULT false,
    is_default          BOOLEAN NOT NULL DEFAULT false,
    last_test_at        TIMESTAMPTZ,
    last_test_ok        BOOLEAN,
    last_test_message   TEXT,
    created_by          TEXT NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by          TEXT,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (provider, mode),
    CHECK (NOT is_default OR enabled)
);
CREATE UNIQUE INDEX fin_gateway_connections_one_default ON fin_gateway_connections ((true)) WHERE is_default;

-- ------------------------------------------------------------------ payment links

-- One link per fee demand. Engage asks for it server to server (source_app 'engage', Idempotency-Key), or staff raise it
-- in Finance (source_app 'finance': a maker creates it, someone else approves it, only then is it created at the
-- provider). What the payer is asked to pay — payer snapshot, amount and the GST split Academy / Engage sent (checked
-- against Finance's own computation) — never changes after insert (trigger); only the workflow columns move.
CREATE TABLE fin_payment_links (
    id                  BIGSERIAL PRIMARY KEY,
    link_id             TEXT NOT NULL UNIQUE CHECK (link_id ~ '^fin_pl_[0-9A-HJKMNP-TV-Z]{26}$'),
    source_app          TEXT NOT NULL CHECK (source_app IN ('engage','finance')),
    idempotency_key     TEXT CHECK (idempotency_key IS NULL OR idempotency_key ~ '^[A-Za-z0-9_.:-]{8,100}$'),
    request_sha256      TEXT CHECK (request_sha256 IS NULL OR request_sha256 ~ '^[0-9a-f]{64}$'),
    source_ref          TEXT CHECK (source_ref IS NULL OR source_ref ~ '^[A-Za-z0-9_.:/-]{1,64}$'),   -- Engage lead code
    academy_ref         TEXT NOT NULL CHECK (academy_ref ~ '^[A-Za-z0-9_.:/-]{1,64}$'),               -- admission / fee-account ref
    stream              TEXT NOT NULL CHECK (stream IN ('edge','aspire')),
    product_code        TEXT NOT NULL CHECK (product_code ~ '^[a-z0-9][a-z0-9_.-]{0,63}$'),
    seller_code         TEXT CHECK (seller_code IS NULL OR seller_code ~ '^[A-Za-z0-9_.-]{1,32}$'),
    description         TEXT NOT NULL CHECK (btrim(description) <> '' AND length(description) <= 300),
    payer_name          TEXT NOT NULL CHECK (btrim(payer_name) <> ''),
    payer_email         TEXT,
    payer_mobile        TEXT NOT NULL CHECK (payer_mobile ~ '^\+91[6-9][0-9]{9}$'),
    payer_state_code    TEXT NOT NULL CHECK (payer_state_code ~ '^[0-9]{2}$'),
    payer_address       TEXT,
    payer_pincode       TEXT CHECK (payer_pincode IS NULL OR payer_pincode ~ '^[1-9][0-9]{5}$'),
    amount_paise        BIGINT NOT NULL CHECK (amount_paise >= 100),
    fee_taxable_paise   BIGINT NOT NULL CHECK (fee_taxable_paise >= 0),
    fee_cgst_paise      BIGINT NOT NULL CHECK (fee_cgst_paise >= 0),
    fee_sgst_paise      BIGINT NOT NULL CHECK (fee_sgst_paise >= 0),
    fee_igst_paise      BIGINT NOT NULL CHECK (fee_igst_paise >= 0),
    fee_sac             TEXT NOT NULL CHECK (fee_sac ~ '^99[0-9]{4}$'),
    fee_gst_rate        NUMERIC(5,2) NOT NULL CHECK (fee_gst_rate >= 0 AND fee_gst_rate < 100),
    fee_price_basis     TEXT NOT NULL CHECK (fee_price_basis IN ('inclusive','exclusive')),
    fee_breakdown       JSONB NOT NULL,                                  -- the fee object exactly as Academy / Engage sent it
    seller_state_code   TEXT NOT NULL CHECK (seller_state_code ~ '^[0-9]{2}$'),
    place_of_supply     TEXT NOT NULL CHECK (place_of_supply ~ '^[0-9]{2}$'),
    supply_type         TEXT NOT NULL CHECK (supply_type IN ('intra_state','inter_state')),
    connection_id       BIGINT NOT NULL REFERENCES fin_gateway_connections(id),
    provider            TEXT NOT NULL CHECK (provider IN ('razorpay','cashfree')),
    mode                TEXT NOT NULL CHECK (mode IN ('test','live')),
    expire_at           TIMESTAMPTZ NOT NULL,
    notify_sms          BOOLEAN NOT NULL DEFAULT false,
    notify_email        BOOLEAN NOT NULL DEFAULT false,
    -- workflow
    status              TEXT NOT NULL CHECK (status IN ('pending_approval','creating','created','partially_paid','paid','expired','cancelled','failed','rejected')),
    provider_link_id    TEXT,
    link_url            TEXT,
    provider_status     TEXT,
    amount_paid_paise   BIGINT NOT NULL DEFAULT 0 CHECK (amount_paid_paise >= 0),
    create_attempts     INTEGER NOT NULL DEFAULT 0,
    last_error          TEXT,
    created_by          TEXT NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    approved_by         TEXT,
    approved_at         TIMESTAMPTZ,
    rejected_by         TEXT,
    rejected_at         TIMESTAMPTZ,
    reject_reason       TEXT,
    cancelled_by        TEXT,
    cancelled_at        TIMESTAMPTZ,
    cancel_reason       TEXT,
    paid_at             TIMESTAMPTZ,
    last_synced_at      TIMESTAMPTZ,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (source_app, idempotency_key),
    CHECK (fee_taxable_paise + fee_cgst_paise + fee_sgst_paise + fee_igst_paise = amount_paise),
    CHECK (supply_type = 'inter_state' OR fee_igst_paise = 0),
    CHECK (supply_type = 'intra_state' OR (fee_cgst_paise = 0 AND fee_sgst_paise = 0)),
    CHECK (source_app <> 'engage' OR (idempotency_key IS NOT NULL AND request_sha256 IS NOT NULL AND source_ref IS NOT NULL)),
    CONSTRAINT fin_payment_links_four_eyes CHECK (approved_by IS NULL OR source_app <> 'finance' OR lower(approved_by) <> lower(created_by)),
    CHECK (status NOT IN ('created','partially_paid','paid','expired') OR (provider_link_id IS NOT NULL AND link_url IS NOT NULL)),
    CHECK (status <> 'rejected' OR (rejected_by IS NOT NULL AND reject_reason IS NOT NULL AND btrim(reject_reason) <> ''))
);
-- One open demand and one paid demand per Academy fee account: a second link for the same fee is refused (409),
-- so a student is never asked twice for the same fee.
CREATE UNIQUE INDEX fin_payment_links_one_open ON fin_payment_links (academy_ref)
    WHERE status IN ('pending_approval','creating','created','partially_paid');
CREATE UNIQUE INDEX fin_payment_links_one_paid ON fin_payment_links (academy_ref) WHERE status = 'paid';
CREATE INDEX fin_payment_links_status_idx ON fin_payment_links (status, created_at DESC);
CREATE INDEX fin_payment_links_provider_idx ON fin_payment_links (provider, provider_link_id);
CREATE INDEX fin_payment_links_source_idx ON fin_payment_links (source_ref);

CREATE FUNCTION fin_payment_links_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    workflow text[] := ARRAY['status','provider_link_id','link_url','provider_status','amount_paid_paise','create_attempts','last_error',
                             'approved_by','approved_at','rejected_by','rejected_at','reject_reason','cancelled_by','cancelled_at','cancel_reason',
                             'paid_at','last_synced_at','updated_at'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_payment_links rows are never deleted (cancel or reject them instead)';
    END IF;
    IF (to_jsonb(NEW) - workflow) IS DISTINCT FROM (to_jsonb(OLD) - workflow) THEN
        RAISE EXCEPTION 'fin_payment_links: the payer, amount and fee of a link never change (cancel it and raise a new one)';
    END IF;
    IF OLD.status IN ('paid','rejected') AND NEW.status <> OLD.status THEN
        RAISE EXCEPTION 'fin_payment_links: a % link cannot become %', OLD.status, NEW.status;
    END IF;
    IF OLD.provider_link_id IS NOT NULL AND NEW.provider_link_id IS DISTINCT FROM OLD.provider_link_id THEN
        RAISE EXCEPTION 'fin_payment_links: the provider link id never changes once set';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_payment_links_immutable BEFORE UPDATE OR DELETE ON fin_payment_links
    FOR EACH ROW EXECUTE FUNCTION fin_payment_links_guard();

-- ------------------------------------------------------------------ gateway payments

-- A payment the provider reports against one of our links. 'verified' only once Finance has re-read it from the
-- provider's own API (the link's payments / orders): a signed webhook alone is not enough. Only verified payments are
-- invoiced, mark the link paid and are reported to Engage. raw_event is the first event that announced it, with
-- personal and instrument data scrubbed (no email, phone, VPA, card or bank details).
--
-- This is the table InvoiceService::issueForPayment()'s payment_id (fin_invoices.payment_id) refers to. No foreign key
-- is added: the Phase A invoicing tests and the numbering concurrency test issue against synthetic payment ids.
CREATE TABLE fin_gateway_payments (
    id                     BIGSERIAL PRIMARY KEY,
    payment_ref            TEXT NOT NULL UNIQUE CHECK (payment_ref ~ '^fin_pay_[0-9A-HJKMNP-TV-Z]{26}$'),
    provider               TEXT NOT NULL CHECK (provider IN ('razorpay','cashfree')),
    connection_id          BIGINT NOT NULL REFERENCES fin_gateway_connections(id),
    mode                   TEXT NOT NULL CHECK (mode IN ('test','live')),
    provider_payment_id    TEXT NOT NULL CHECK (provider_payment_id ~ '^[A-Za-z0-9_.:-]{3,100}$'),
    provider_txn_ref       TEXT,
    -- The payment id as the provider's dashboard shows it and as staff type it into Engage: Razorpay pay_…, Cashfree the
    -- bare cf_payment_id. Read from the provider's API at verification; Engage de-duplicates on (lead, payment_ref).
    gateway_payment_ref    TEXT CHECK (gateway_payment_ref IS NULL OR gateway_payment_ref ~ '^[A-Za-z0-9_.:-]{1,100}$'),
    link_id                BIGINT NOT NULL REFERENCES fin_payment_links(id),
    amount_paise           BIGINT NOT NULL CHECK (amount_paise > 0),
    currency               TEXT NOT NULL DEFAULT 'INR' CHECK (currency = 'INR'),
    method                 TEXT,
    paid_at                TIMESTAMPTZ NOT NULL,
    verification_status    TEXT NOT NULL DEFAULT 'pending' CHECK (verification_status IN ('pending','verified','mismatch')),
    verification_error     TEXT,
    verification_attempts  INTEGER NOT NULL DEFAULT 0,
    verified_at            TIMESTAMPTZ,
    refunded_paise         BIGINT NOT NULL DEFAULT 0,
    invoice_state          TEXT NOT NULL DEFAULT 'not_issued' CHECK (invoice_state IN ('not_issued','issued','blocked_config','blocked_data')),
    invoice_error          TEXT,
    invoice_attempts       INTEGER NOT NULL DEFAULT 0,
    raw_event              JSONB,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (provider, provider_payment_id),
    CHECK (refunded_paise BETWEEN 0 AND amount_paise),
    CHECK (verification_status <> 'verified' OR (verified_at IS NOT NULL AND gateway_payment_ref IS NOT NULL)),
    CHECK (invoice_state = 'not_issued' OR verification_status = 'verified')
);
CREATE INDEX fin_gateway_payments_link_idx ON fin_gateway_payments (link_id);
CREATE INDEX fin_gateway_payments_state_idx ON fin_gateway_payments (verification_status, invoice_state);

CREATE FUNCTION fin_gateway_payments_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    workflow text[] := ARRAY['method','gateway_payment_ref','verification_status','verification_error','verification_attempts','verified_at',
                             'refunded_paise','invoice_state','invoice_error','invoice_attempts','updated_at'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_gateway_payments rows are never deleted';
    END IF;
    IF OLD.gateway_payment_ref IS NOT NULL AND NEW.gateway_payment_ref IS DISTINCT FROM OLD.gateway_payment_ref THEN
        RAISE EXCEPTION 'fin_gateway_payments: the gateway payment reference never changes once set';
    END IF;
    IF (to_jsonb(NEW) - workflow) IS DISTINCT FROM (to_jsonb(OLD) - workflow) THEN
        RAISE EXCEPTION 'fin_gateway_payments: what the provider reported never changes';
    END IF;
    IF OLD.verification_status = 'verified' AND NEW.verification_status <> 'verified' THEN
        RAISE EXCEPTION 'fin_gateway_payments: a verified payment stays verified';
    END IF;
    IF OLD.invoice_state = 'issued' AND NEW.invoice_state <> 'issued' THEN
        RAISE EXCEPTION 'fin_gateway_payments: an issued invoice stays issued';
    END IF;
    IF NEW.refunded_paise < OLD.refunded_paise THEN
        RAISE EXCEPTION 'fin_gateway_payments: the refunded amount cannot go down';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_gateway_payments_immutable BEFORE UPDATE OR DELETE ON fin_gateway_payments
    FOR EACH ROW EXECUTE FUNCTION fin_gateway_payments_guard();

-- ------------------------------------------------------------------ refunds (recorded, not initiated)

-- Refunds made in the provider's dashboard, reported by webhook and re-read from the provider before they count.
-- Finance does not initiate refunds yet, and the credit note is raised by staff through the existing four-eyes flow.
CREATE TABLE fin_gateway_refunds (
    id                     BIGSERIAL PRIMARY KEY,
    provider               TEXT NOT NULL CHECK (provider IN ('razorpay','cashfree')),
    provider_refund_id     TEXT NOT NULL CHECK (provider_refund_id ~ '^[A-Za-z0-9_.:-]{3,100}$'),
    payment_id             BIGINT NOT NULL REFERENCES fin_gateway_payments(id),
    amount_paise           BIGINT NOT NULL CHECK (amount_paise > 0),
    refunded_at            TIMESTAMPTZ NOT NULL,
    verification_status    TEXT NOT NULL DEFAULT 'pending' CHECK (verification_status IN ('pending','verified','mismatch')),
    verification_error     TEXT,
    verification_attempts  INTEGER NOT NULL DEFAULT 0,
    verified_at            TIMESTAMPTZ,
    raw_event              JSONB,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (provider, provider_refund_id)
);
CREATE INDEX fin_gateway_refunds_payment_idx ON fin_gateway_refunds (payment_id);

-- ------------------------------------------------------------------ webhook deliveries (replay safety + log)

-- Every delivery whose signature verifies is stored once: `fingerprint` (provider event id, else sha256 of the raw
-- body) is unique, so a replayed or retried delivery is recognised and not processed twice. Deliveries with a bad
-- signature are logged with fingerprint NULL (they must never block the genuine delivery of the same body) and no
-- payload, capped per hour so the log cannot be flooded.
CREATE TABLE fin_gateway_webhook_events (
    id                 BIGSERIAL PRIMARY KEY,
    provider           TEXT NOT NULL CHECK (provider IN ('razorpay','cashfree')),
    fingerprint        TEXT UNIQUE,
    body_sha256        TEXT NOT NULL CHECK (body_sha256 ~ '^[0-9a-f]{64}$'),
    event_type         TEXT,
    provider_event_id  TEXT,
    connection_id      BIGINT REFERENCES fin_gateway_connections(id),
    signature_valid    BOOLEAN NOT NULL,
    outcome            TEXT NOT NULL CHECK (outcome IN ('received','processed','ignored','rejected','error')),
    detail             TEXT,
    link_id            BIGINT REFERENCES fin_payment_links(id),
    gateway_payment_id BIGINT REFERENCES fin_gateway_payments(id),
    deliveries         INTEGER NOT NULL DEFAULT 1,
    payload            JSONB,
    received_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at       TIMESTAMPTZ,
    CHECK (signature_valid = (fingerprint IS NOT NULL)),
    CHECK (signature_valid OR (outcome = 'rejected' AND payload IS NULL))
);
CREATE INDEX fin_gateway_webhook_events_received_idx ON fin_gateway_webhook_events (received_at DESC);

-- ------------------------------------------------------------------ Finance → Engage payment events (outbox)

-- payment.succeeded / payment.refunded, written in the same transaction as the verification that causes them and
-- delivered by bin/academy-fees-cron.php (AIC-S2S v1 signed) with back-off; events of one link go strictly in order.
CREATE TABLE fin_engage_payment_events (
    id                  BIGSERIAL PRIMARY KEY,
    event_id            TEXT NOT NULL UNIQUE CHECK (event_id ~ '^fin_evt_[0-9A-HJKMNP-TV-Z]{26}$'),
    event_type          TEXT NOT NULL CHECK (event_type IN ('payment.succeeded','payment.refunded')),
    aggregate_key       TEXT NOT NULL,
    link_id             BIGINT NOT NULL REFERENCES fin_payment_links(id),
    gateway_payment_id  BIGINT NOT NULL REFERENCES fin_gateway_payments(id),
    refund_id           BIGINT REFERENCES fin_gateway_refunds(id),
    payload             JSON NOT NULL,                 -- json (not jsonb): signed and sent byte-for-byte as queued
    status              TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','delivered','dead')),
    attempts            INTEGER NOT NULL DEFAULT 0,
    next_attempt_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_http_status    INTEGER,
    last_error          TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    delivered_at        TIMESTAMPTZ,
    CHECK ((event_type = 'payment.refunded') = (refund_id IS NOT NULL))
);
CREATE UNIQUE INDEX fin_engage_payment_events_succeeded_once ON fin_engage_payment_events (gateway_payment_id) WHERE event_type = 'payment.succeeded';
CREATE UNIQUE INDEX fin_engage_payment_events_refund_once ON fin_engage_payment_events (refund_id) WHERE refund_id IS NOT NULL;
CREATE INDEX fin_engage_payment_events_due_idx ON fin_engage_payment_events (status, next_attempt_at);
CREATE INDEX fin_engage_payment_events_aggregate_idx ON fin_engage_payment_events (aggregate_key, id);

-- ------------------------------------------------------------------ Academy students as buyers (B2C, no cmp_id)

-- Existing invoices and credit notes are 'company' rows and keep cmp_id required. An 'academy_student' row is an
-- individual student (unregistered, no GSTIN) paying an Academy fee through a gateway payment: it has no Manage company,
-- so cmp_id is NULL and Manage never sees it (its reads filter by cmp_id). Adding the columns fires no row trigger.
ALTER TABLE fin_invoices ADD COLUMN buyer_kind TEXT NOT NULL DEFAULT 'company' CHECK (buyer_kind IN ('company','academy_student'));
ALTER TABLE fin_invoices ALTER COLUMN cmp_id DROP NOT NULL;
ALTER TABLE fin_invoices ADD CONSTRAINT fin_invoices_buyer_kind_cmp CHECK (
       (buyer_kind = 'company' AND cmp_id IS NOT NULL)
    OR (buyer_kind = 'academy_student' AND cmp_id IS NULL AND source = 'payment' AND buyer_gstin IS NULL)
);

ALTER TABLE fin_credit_notes ADD COLUMN buyer_kind TEXT NOT NULL DEFAULT 'company' CHECK (buyer_kind IN ('company','academy_student'));
ALTER TABLE fin_credit_notes ALTER COLUMN cmp_id DROP NOT NULL;
ALTER TABLE fin_credit_notes ADD CONSTRAINT fin_credit_notes_buyer_kind_cmp CHECK (
       (buyer_kind = 'company' AND cmp_id IS NOT NULL)
    OR (buyer_kind = 'academy_student' AND cmp_id IS NULL)
);

-- A credit note belongs to its invoice's buyer: same company, or the same Academy student invoice (both without cmp_id).
CREATE OR REPLACE FUNCTION fin_credit_notes_cmp_check() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM fin_invoices WHERE id = NEW.invoice_id AND cmp_id IS NOT DISTINCT FROM NEW.cmp_id AND buyer_kind = NEW.buyer_kind) THEN
        RAISE EXCEPTION 'fin_credit_notes: cmp_id must be the original invoice''s company';
    END IF;
    RETURN NEW;
END;
$$;

COMMENT ON TABLE fin_payment_links IS 'Aicountly Academy fee payment links (Razorpay / Cashfree); docs/ACADEMY_PAYMENTS.md.';
