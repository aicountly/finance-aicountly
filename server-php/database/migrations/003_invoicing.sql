-- 003_invoicing — subscription tax invoices and credit notes (commerce plan, Phase A; docs/INVOICING.md).
--
-- Dormant until configured: nothing here is used by the payout ledger, and invoices can only be issued once a
-- finance admin has saved the seller profile and a document series for the financial year.
--
-- Money: BIGINT paise (never floats; contract field names end in _paise). Rates: NUMERIC(5,2) percent.
-- Issued documents are immutable: triggers refuse UPDATE / DELETE of their financial columns; corrections are made
-- by credit note only. Numbering is gapless per FY and document type: the series row is locked (SELECT … FOR UPDATE)
-- inside the issuing transaction, so a rolled-back issue never consumes a number.

-- ------------------------------------------------------------------ seller profile (single active row)

-- Changing the profile inserts a new row and retires the old one (history kept; every document snapshots the
-- profile it was issued under anyway). Rows are never edited or deleted.
CREATE TABLE fin_seller_profile (
    id                    BIGSERIAL PRIMARY KEY,
    legal_name            TEXT NOT NULL CHECK (btrim(legal_name) <> ''),
    trade_name            TEXT,
    gstin                 TEXT NOT NULL CHECK (gstin ~ '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$'),
    pan                   TEXT NOT NULL CHECK (pan ~ '^[A-Z]{5}[0-9]{4}[A-Z]$'),
    address_line1         TEXT NOT NULL CHECK (btrim(address_line1) <> ''),
    address_line2         TEXT,
    city                  TEXT NOT NULL CHECK (btrim(city) <> ''),
    pincode               TEXT NOT NULL CHECK (pincode ~ '^[1-9][0-9]{5}$'),
    state_code            TEXT NOT NULL CHECK (state_code ~ '^[0-9]{2}$'),
    email                 TEXT,
    phone                 TEXT,
    signatory_name        TEXT NOT NULL CHECK (btrim(signatory_name) <> ''),
    signatory_designation TEXT,
    effective_from        DATE NOT NULL,
    active                BOOLEAN NOT NULL DEFAULT true,
    created_by            TEXT NOT NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    retired_by            TEXT,
    retired_at            TIMESTAMPTZ,
    CHECK (substr(gstin, 3, 10) = pan),          -- a GSTIN embeds the holder's PAN
    CHECK (substr(gstin, 1, 2) = state_code),    -- …and the state of registration
    CHECK (active OR retired_at IS NOT NULL)
);
CREATE UNIQUE INDEX fin_seller_profile_one_active ON fin_seller_profile ((true)) WHERE active;

CREATE FUNCTION fin_seller_profile_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_seller_profile rows are never deleted (retire them instead)';
    END IF;
    -- The only change allowed: retiring an active row.
    IF (to_jsonb(NEW) - ARRAY['active','retired_by','retired_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['active','retired_by','retired_at'])
       OR NOT OLD.active OR NEW.active THEN
        RAISE EXCEPTION 'fin_seller_profile is immutable: save a new profile instead';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_seller_profile_immutable BEFORE UPDATE OR DELETE ON fin_seller_profile
    FOR EACH ROW EXECUTE FUNCTION fin_seller_profile_guard();

-- ------------------------------------------------------------------ document series (one per FY and doc type)

-- doc_no = prefix || lpad(next_no, width, '0'), at most 16 characters (CGST Rule 46(b)): letters, digits, '-' and '/'.
-- The prefix must not end in a digit, so two different prefixes can never produce the same document number;
-- with UNIQUE (fy, prefix) every number is unique within the FY across invoices and credit notes.
CREATE TABLE fin_doc_series (
    id          BIGSERIAL PRIMARY KEY,
    fy          TEXT NOT NULL CHECK (fy ~ '^[0-9]{4}-[0-9]{2}$'),
    doc_type    TEXT NOT NULL CHECK (doc_type IN ('tax_invoice','credit_note')),
    prefix      TEXT NOT NULL CHECK (prefix ~ '^[A-Z0-9/-]*[A-Z/-]$'),
    next_no     BIGINT NOT NULL DEFAULT 1 CHECK (next_no >= 1),
    width       SMALLINT NOT NULL CHECK (width BETWEEN 1 AND 12),
    created_by  TEXT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by  TEXT,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (fy, doc_type),
    UNIQUE (fy, prefix),
    CHECK (length(prefix) + width <= 16)
);

CREATE FUNCTION fin_doc_series_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_doc_series rows are never deleted';
    END IF;
    IF NEW.fy <> OLD.fy OR NEW.doc_type <> OLD.doc_type THEN
        RAISE EXCEPTION 'fin_doc_series: fy and doc_type cannot change';
    END IF;
    IF NEW.next_no < OLD.next_no AND (
           EXISTS (SELECT 1 FROM fin_invoices WHERE series_id = OLD.id)
        OR EXISTS (SELECT 1 FROM fin_credit_notes WHERE series_id = OLD.id)) THEN
        RAISE EXCEPTION 'fin_doc_series: the next number cannot go back once documents are numbered from this series';
    END IF;
    IF (NEW.prefix <> OLD.prefix OR NEW.width <> OLD.width) AND (
           EXISTS (SELECT 1 FROM fin_invoices WHERE series_id = OLD.id)
        OR EXISTS (SELECT 1 FROM fin_credit_notes WHERE series_id = OLD.id)) THEN
        RAISE EXCEPTION 'fin_doc_series: prefix and width cannot change once documents are numbered from this series';
    END IF;
    RETURN NEW;
END;
$$;

-- Invoices and credit notes share one id sequence, so (doc_date, id) orders and pages both kinds together
-- (contract §0.3 cursor) without ambiguity.
CREATE SEQUENCE fin_document_seq;

-- ------------------------------------------------------------------ offline payments (staff-recorded, four-eyes)

-- Bank transfer / UPI / cheque received outside a gateway. A maker records it (UTR, amount, buyer, what is being
-- billed); a different person (checker) verifies it against the bank statement; only then is the tax invoice issued
-- (contract §3.5). The recorded facts never change: a wrong record is rejected and recorded again.
CREATE TABLE fin_offline_payments (
    id                 BIGSERIAL PRIMARY KEY,
    cmp_id             BIGINT NOT NULL CHECK (cmp_id > 0),
    buyer_uuid         TEXT,
    buyer_legal_name   TEXT NOT NULL CHECK (btrim(buyer_legal_name) <> ''),
    buyer_gstin        TEXT CHECK (buyer_gstin IS NULL OR buyer_gstin ~ '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$'),
    buyer_state_code   TEXT NOT NULL CHECK (buyer_state_code ~ '^[0-9]{2}$'),
    buyer_address      TEXT NOT NULL CHECK (btrim(buyer_address) <> ''),
    buyer_pincode      TEXT NOT NULL CHECK (buyer_pincode ~ '^[1-9][0-9]{5}$'),
    buyer_country      TEXT NOT NULL DEFAULT 'IN' CHECK (buyer_country = 'IN'),
    buyer_email        TEXT,
    lines              JSONB NOT NULL CHECK (jsonb_typeof(lines) = 'array' AND jsonb_array_length(lines) > 0),
    amount_paise       BIGINT NOT NULL CHECK (amount_paise > 0),
    method             TEXT NOT NULL CHECK (method IN ('neft','rtgs','imps','upi','cheque')),
    utr                TEXT NOT NULL CHECK (utr ~ '^[A-Z0-9]{6,35}$'),
    received_on        DATE NOT NULL,
    note               TEXT,
    status             TEXT NOT NULL DEFAULT 'recorded' CHECK (status IN ('recorded','verified','rejected')),
    invoice_state      TEXT NOT NULL DEFAULT 'not_issued' CHECK (invoice_state IN ('not_issued','issued','blocked_config','blocked_data')),
    invoice_error      TEXT,
    invoice_attempts   INTEGER NOT NULL DEFAULT 0,
    recorded_by        TEXT NOT NULL,
    recorded_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    verified_by        TEXT,
    verified_at        TIMESTAMPTZ,
    rejected_by        TEXT,
    rejected_at        TIMESTAMPTZ,
    reject_reason      TEXT,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT fin_offline_payments_four_eyes CHECK (verified_by IS NULL OR lower(verified_by) <> lower(recorded_by)),
    CHECK (status <> 'verified' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL)),
    CHECK (status <> 'rejected' OR (rejected_by IS NOT NULL AND reject_reason IS NOT NULL AND btrim(reject_reason) <> '')),
    CHECK (invoice_state = 'not_issued' OR status = 'verified')
);
-- A UTR identifies one receipt; a rejected record frees it for the corrected record.
CREATE UNIQUE INDEX fin_offline_payments_utr_uniq ON fin_offline_payments (utr) WHERE status <> 'rejected';
CREATE INDEX fin_offline_payments_status_idx ON fin_offline_payments (status, invoice_state);

CREATE FUNCTION fin_offline_payments_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    workflow text[] := ARRAY['status','invoice_state','invoice_error','invoice_attempts','verified_by','verified_at',
                             'rejected_by','rejected_at','reject_reason','updated_at'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_offline_payments rows are never deleted (reject them instead)';
    END IF;
    IF (to_jsonb(NEW) - workflow) IS DISTINCT FROM (to_jsonb(OLD) - workflow) THEN
        RAISE EXCEPTION 'fin_offline_payments: the recorded payment is immutable (reject it and record it again)';
    END IF;
    IF OLD.status <> 'recorded' AND NEW.status <> OLD.status THEN
        RAISE EXCEPTION 'fin_offline_payments: a % payment cannot become %', OLD.status, NEW.status;
    END IF;
    IF OLD.invoice_state = 'issued' AND NEW.invoice_state <> 'issued' THEN
        RAISE EXCEPTION 'fin_offline_payments: an issued invoice stays issued';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_offline_payments_immutable BEFORE UPDATE OR DELETE ON fin_offline_payments
    FOR EACH ROW EXECUTE FUNCTION fin_offline_payments_guard();

-- ------------------------------------------------------------------ tax invoices

CREATE TABLE fin_invoices (
    id                  BIGINT PRIMARY KEY DEFAULT nextval('fin_document_seq'),
    document_id         TEXT NOT NULL UNIQUE CHECK (document_id ~ '^fin_inv_[0-9A-HJKMNP-TV-Z]{26}$'),
    cmp_id              BIGINT NOT NULL CHECK (cmp_id > 0),
    buyer_uuid          TEXT,
    series_id           BIGINT NOT NULL REFERENCES fin_doc_series(id),
    fy                  TEXT NOT NULL,
    seq_no              BIGINT NOT NULL CHECK (seq_no >= 1),
    doc_no              TEXT NOT NULL CHECK (length(doc_no) <= 16 AND doc_no ~ '^[A-Z0-9/-]+$'),
    doc_date            DATE NOT NULL,
    source              TEXT NOT NULL CHECK (source IN ('offline_payment','payment')),
    source_ref          TEXT NOT NULL UNIQUE,                 -- 'offline:<id>' | 'payment:<id>': one invoice per payment
    payment_id          BIGINT UNIQUE,                        -- fin_payments.id (phase B checkout; FK added with that table)
    offline_payment_id  BIGINT UNIQUE REFERENCES fin_offline_payments(id),
    seller_profile_id   BIGINT NOT NULL REFERENCES fin_seller_profile(id),
    seller              JSONB NOT NULL,                       -- snapshot of the profile as printed
    seller_gstin        TEXT NOT NULL,
    seller_state_code   TEXT NOT NULL,
    buyer_legal_name    TEXT NOT NULL CHECK (btrim(buyer_legal_name) <> ''),
    buyer_gstin         TEXT,                                 -- NULL = unregistered buyer (B2C)
    buyer_state_code    TEXT NOT NULL,
    buyer_address       TEXT NOT NULL,
    buyer_pincode       TEXT,
    buyer_country       TEXT NOT NULL DEFAULT 'IN',
    buyer_email         TEXT,
    place_of_supply     TEXT NOT NULL CHECK (place_of_supply ~ '^[0-9]{2}$'),
    supply_type         TEXT NOT NULL CHECK (supply_type IN ('intra_state','inter_state')),
    reverse_charge      BOOLEAN NOT NULL DEFAULT false,
    currency            TEXT NOT NULL DEFAULT 'INR' CHECK (currency = 'INR'),
    taxable_paise       BIGINT NOT NULL CHECK (taxable_paise >= 0),
    cgst_paise          BIGINT NOT NULL CHECK (cgst_paise >= 0),
    sgst_paise          BIGINT NOT NULL CHECK (sgst_paise >= 0),
    igst_paise          BIGINT NOT NULL CHECK (igst_paise >= 0),
    total_paise         BIGINT NOT NULL CHECK (total_paise > 0),
    amount_in_words     TEXT NOT NULL,
    payment_method      TEXT NOT NULL,
    payment_gateway     TEXT,
    payment_reference   TEXT NOT NULL,
    paid_on             DATE NOT NULL,
    status              TEXT NOT NULL DEFAULT 'issued' CHECK (status IN ('issued','partially_credited','credited')),
    credited_paise      BIGINT NOT NULL DEFAULT 0,
    issued_by           TEXT NOT NULL,
    issued_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_txid        BIGINT NOT NULL DEFAULT txid_current(),
    UNIQUE (fy, doc_no),
    UNIQUE (series_id, seq_no),
    CHECK (total_paise = taxable_paise + cgst_paise + sgst_paise + igst_paise),
    CHECK (supply_type = 'inter_state' OR igst_paise = 0),
    CHECK (supply_type = 'intra_state' OR (cgst_paise = 0 AND sgst_paise = 0)),
    CHECK ((source = 'payment') = (payment_id IS NOT NULL)),
    CHECK ((source = 'offline_payment') = (offline_payment_id IS NOT NULL)),
    CHECK (credited_paise BETWEEN 0 AND total_paise),
    CHECK (status = CASE WHEN credited_paise = 0 THEN 'issued' WHEN credited_paise = total_paise THEN 'credited' ELSE 'partially_credited' END)
);
CREATE INDEX fin_invoices_cmp_idx ON fin_invoices (cmp_id, doc_date DESC, id DESC);
CREATE INDEX fin_invoices_date_idx ON fin_invoices (doc_date DESC, id DESC);

CREATE TABLE fin_invoice_lines (
    id              BIGSERIAL PRIMARY KEY,
    invoice_id      BIGINT NOT NULL REFERENCES fin_invoices(id),
    line_no         INTEGER NOT NULL CHECK (line_no >= 1),
    item_code       TEXT NOT NULL CHECK (btrim(item_code) <> ''),
    item_kind       TEXT NOT NULL CHECK (btrim(item_kind) <> ''),
    description     TEXT NOT NULL CHECK (btrim(description) <> ''),
    sac             TEXT NOT NULL CHECK (sac ~ '^99[0-9]{4}$'),
    gst_rate        NUMERIC(5,2) NOT NULL CHECK (gst_rate >= 0 AND gst_rate < 100),
    price_basis     TEXT NOT NULL CHECK (price_basis IN ('inclusive','exclusive')),
    period_start    DATE,
    period_end      DATE,
    quantity        NUMERIC(12,3) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    amount_paise    BIGINT NOT NULL CHECK (amount_paise > 0),   -- as billed: incl. GST (inclusive) or taxable value (exclusive)
    taxable_paise   BIGINT NOT NULL CHECK (taxable_paise >= 0),
    cgst_paise      BIGINT NOT NULL CHECK (cgst_paise >= 0),
    sgst_paise      BIGINT NOT NULL CHECK (sgst_paise >= 0),
    igst_paise      BIGINT NOT NULL CHECK (igst_paise >= 0),
    total_paise     BIGINT NOT NULL CHECK (total_paise > 0),
    UNIQUE (invoice_id, line_no),
    CHECK (total_paise = taxable_paise + cgst_paise + sgst_paise + igst_paise),
    CHECK (period_start IS NULL OR period_end IS NULL OR period_start <= period_end)
);
CREATE INDEX fin_invoice_lines_invoice_idx ON fin_invoice_lines (invoice_id);

-- ------------------------------------------------------------------ credit notes (four-eyes)

-- Requested by a maker (amounts computed, no number yet: status pending_approval), approved by someone else (number,
-- date and seller snapshot assigned, status issued) or rejected. Once issued or rejected the row never changes.
CREATE TABLE fin_credit_notes (
    id                 BIGINT PRIMARY KEY DEFAULT nextval('fin_document_seq'),
    document_id        TEXT NOT NULL UNIQUE CHECK (document_id ~ '^fin_cn_[0-9A-HJKMNP-TV-Z]{26}$'),
    invoice_id         BIGINT NOT NULL REFERENCES fin_invoices(id),
    cmp_id             BIGINT NOT NULL CHECK (cmp_id > 0),
    status             TEXT NOT NULL DEFAULT 'pending_approval' CHECK (status IN ('pending_approval','issued','rejected')),
    kind               TEXT NOT NULL CHECK (kind IN ('full','partial')),
    reason             TEXT NOT NULL CHECK (btrim(reason) <> ''),
    series_id          BIGINT REFERENCES fin_doc_series(id),
    fy                 TEXT,
    seq_no             BIGINT,
    doc_no             TEXT CHECK (doc_no IS NULL OR (length(doc_no) <= 16 AND doc_no ~ '^[A-Z0-9/-]+$')),
    doc_date           DATE,
    seller_profile_id  BIGINT REFERENCES fin_seller_profile(id),
    seller             JSONB,
    seller_gstin       TEXT,
    buyer_legal_name   TEXT NOT NULL,
    buyer_gstin        TEXT,
    buyer_state_code   TEXT NOT NULL,
    buyer_address      TEXT NOT NULL,
    buyer_pincode      TEXT,
    buyer_country      TEXT NOT NULL DEFAULT 'IN',
    buyer_email        TEXT,
    place_of_supply    TEXT NOT NULL,
    supply_type        TEXT NOT NULL CHECK (supply_type IN ('intra_state','inter_state')),
    currency           TEXT NOT NULL DEFAULT 'INR' CHECK (currency = 'INR'),
    taxable_paise      BIGINT NOT NULL CHECK (taxable_paise >= 0),
    cgst_paise         BIGINT NOT NULL CHECK (cgst_paise >= 0),
    sgst_paise         BIGINT NOT NULL CHECK (sgst_paise >= 0),
    igst_paise         BIGINT NOT NULL CHECK (igst_paise >= 0),
    total_paise        BIGINT NOT NULL CHECK (total_paise > 0),
    amount_in_words    TEXT NOT NULL,
    requested_by       TEXT NOT NULL,
    requested_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    approved_by        TEXT,
    approved_at        TIMESTAMPTZ,
    rejected_by        TEXT,
    rejected_at        TIMESTAMPTZ,
    reject_reason      TEXT,
    created_txid       BIGINT NOT NULL DEFAULT txid_current(),
    UNIQUE (fy, doc_no),
    UNIQUE (series_id, seq_no),
    CONSTRAINT fin_credit_notes_four_eyes CHECK (approved_by IS NULL OR lower(approved_by) <> lower(requested_by)),
    CHECK (total_paise = taxable_paise + cgst_paise + sgst_paise + igst_paise),
    CHECK (status <> 'issued' OR (series_id IS NOT NULL AND fy IS NOT NULL AND seq_no IS NOT NULL AND doc_no IS NOT NULL
        AND doc_date IS NOT NULL AND seller_profile_id IS NOT NULL AND seller IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL)),
    CHECK (status = 'issued' OR (doc_no IS NULL AND seq_no IS NULL)),
    CHECK (status <> 'rejected' OR (rejected_by IS NOT NULL AND reject_reason IS NOT NULL AND btrim(reject_reason) <> ''))
);
CREATE INDEX fin_credit_notes_invoice_idx ON fin_credit_notes (invoice_id);
CREATE INDEX fin_credit_notes_cmp_idx ON fin_credit_notes (cmp_id, doc_date DESC, id DESC) WHERE status = 'issued';

CREATE TABLE fin_credit_note_lines (
    id               BIGSERIAL PRIMARY KEY,
    credit_note_id   BIGINT NOT NULL REFERENCES fin_credit_notes(id),
    line_no          INTEGER NOT NULL CHECK (line_no >= 1),
    invoice_line_id  BIGINT NOT NULL REFERENCES fin_invoice_lines(id),
    description      TEXT NOT NULL,
    sac              TEXT NOT NULL,
    gst_rate         NUMERIC(5,2) NOT NULL,
    taxable_paise    BIGINT NOT NULL CHECK (taxable_paise >= 0),
    cgst_paise       BIGINT NOT NULL CHECK (cgst_paise >= 0),
    sgst_paise       BIGINT NOT NULL CHECK (sgst_paise >= 0),
    igst_paise       BIGINT NOT NULL CHECK (igst_paise >= 0),
    total_paise      BIGINT NOT NULL CHECK (total_paise > 0),
    UNIQUE (credit_note_id, line_no),
    CHECK (total_paise = taxable_paise + cgst_paise + sgst_paise + igst_paise)
);
CREATE INDEX fin_credit_note_lines_cn_idx ON fin_credit_note_lines (credit_note_id);
CREATE INDEX fin_credit_note_lines_invoice_line_idx ON fin_credit_note_lines (invoice_line_id);

CREATE TRIGGER fin_doc_series_guarded BEFORE UPDATE OR DELETE ON fin_doc_series
    FOR EACH ROW EXECUTE FUNCTION fin_doc_series_guard();

-- Issued invoices: only the credit tracking (status, credited_paise) may change, and only upwards.
CREATE FUNCTION fin_invoices_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_invoices is immutable: an issued invoice is never deleted (issue a credit note)';
    END IF;
    IF (to_jsonb(NEW) - ARRAY['status','credited_paise']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','credited_paise']) THEN
        RAISE EXCEPTION 'fin_invoices is immutable: the financial columns of an issued invoice cannot change (issue a credit note)';
    END IF;
    IF NEW.credited_paise < OLD.credited_paise THEN
        RAISE EXCEPTION 'fin_invoices: credited amount cannot go down';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_invoices_immutable BEFORE UPDATE OR DELETE ON fin_invoices
    FOR EACH ROW EXECUTE FUNCTION fin_invoices_guard();

CREATE FUNCTION fin_credit_notes_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    issue_cols text[] := ARRAY['status','series_id','fy','seq_no','doc_no','doc_date','seller_profile_id','seller','seller_gstin',
                               'approved_by','approved_at','rejected_by','rejected_at','reject_reason'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_credit_notes is immutable: a credit note is never deleted';
    END IF;
    IF OLD.status <> 'pending_approval' THEN
        RAISE EXCEPTION 'fin_credit_notes is immutable: a % credit note cannot change', OLD.status;
    END IF;
    IF (to_jsonb(NEW) - issue_cols) IS DISTINCT FROM (to_jsonb(OLD) - issue_cols) THEN
        RAISE EXCEPTION 'fin_credit_notes is immutable: the amounts and buyer of a credit note cannot change';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_credit_notes_immutable BEFORE UPDATE OR DELETE ON fin_credit_notes
    FOR EACH ROW EXECUTE FUNCTION fin_credit_notes_guard();

CREATE FUNCTION fin_credit_notes_cmp_check() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM fin_invoices WHERE id = NEW.invoice_id AND cmp_id = NEW.cmp_id) THEN
        RAISE EXCEPTION 'fin_credit_notes: cmp_id must be the original invoice''s company';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_credit_notes_same_company BEFORE INSERT ON fin_credit_notes
    FOR EACH ROW EXECUTE FUNCTION fin_credit_notes_cmp_check();

-- Lines: never updated or deleted, and only added in the transaction that created their document.
CREATE FUNCTION fin_document_lines_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    parent_txid bigint;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION '% is immutable: document lines cannot be changed or deleted', TG_TABLE_NAME;
    END IF;
    IF TG_TABLE_NAME = 'fin_invoice_lines' THEN
        SELECT created_txid INTO parent_txid FROM fin_invoices WHERE id = NEW.invoice_id;
    ELSE
        SELECT created_txid INTO parent_txid FROM fin_credit_notes WHERE id = NEW.credit_note_id;
    END IF;
    IF parent_txid IS DISTINCT FROM txid_current() THEN
        RAISE EXCEPTION '%: lines can only be added when the document is created', TG_TABLE_NAME;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_invoice_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON fin_invoice_lines
    FOR EACH ROW EXECUTE FUNCTION fin_document_lines_guard();
CREATE TRIGGER fin_credit_note_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON fin_credit_note_lines
    FOR EACH ROW EXECUTE FUNCTION fin_document_lines_guard();

-- At commit: a document has at least one line and its totals are exactly the sum of its lines.
CREATE FUNCTION fin_document_totals_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    s record;
BEGIN
    IF TG_TABLE_NAME = 'fin_invoices' THEN
        SELECT count(*) AS n, coalesce(sum(taxable_paise),0) AS t, coalesce(sum(cgst_paise),0) AS c, coalesce(sum(sgst_paise),0) AS g,
               coalesce(sum(igst_paise),0) AS i, coalesce(sum(total_paise),0) AS tot
          INTO s FROM fin_invoice_lines WHERE invoice_id = NEW.id;
    ELSE
        SELECT count(*) AS n, coalesce(sum(taxable_paise),0) AS t, coalesce(sum(cgst_paise),0) AS c, coalesce(sum(sgst_paise),0) AS g,
               coalesce(sum(igst_paise),0) AS i, coalesce(sum(total_paise),0) AS tot
          INTO s FROM fin_credit_note_lines WHERE credit_note_id = NEW.id;
    END IF;
    IF s.n = 0 OR s.t <> NEW.taxable_paise OR s.c <> NEW.cgst_paise OR s.g <> NEW.sgst_paise OR s.i <> NEW.igst_paise OR s.tot <> NEW.total_paise THEN
        RAISE EXCEPTION '%: document % totals do not match its lines', TG_TABLE_NAME, NEW.id USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER fin_invoices_totals_match_lines AFTER INSERT ON fin_invoices
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fin_document_totals_check();
CREATE CONSTRAINT TRIGGER fin_credit_notes_totals_match_lines AFTER INSERT ON fin_credit_notes
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fin_document_totals_check();

-- ------------------------------------------------------------------ rendered documents (stored at issue)

-- The HTML is stored in the issuing transaction. The PDF (Gotenberg, GOTENBERG_URL) is rendered from exactly that
-- HTML right after commit, or later by bin/invoicing-cron.php when the PDF service was unavailable; once stored it
-- is never replaced, and reads stream these bytes (no re-render).
CREATE TABLE fin_doc_pdfs (
    id               BIGSERIAL PRIMARY KEY,
    invoice_id       BIGINT UNIQUE REFERENCES fin_invoices(id),
    credit_note_id   BIGINT UNIQUE REFERENCES fin_credit_notes(id),
    html             TEXT NOT NULL,
    html_sha256      TEXT NOT NULL CHECK (html_sha256 ~ '^[0-9a-f]{64}$'),
    pdf              BYTEA,
    pdf_sha256       TEXT CHECK (pdf_sha256 IS NULL OR pdf_sha256 ~ '^[0-9a-f]{64}$'),
    renderer         TEXT,
    rendered_at      TIMESTAMPTZ,
    render_attempts  INTEGER NOT NULL DEFAULT 0,
    last_error       TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK ((invoice_id IS NULL) <> (credit_note_id IS NULL)),
    CHECK ((pdf IS NULL) = (pdf_sha256 IS NULL)),
    CHECK (pdf IS NULL OR (renderer IS NOT NULL AND rendered_at IS NOT NULL))
);

CREATE FUNCTION fin_doc_pdfs_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    render_cols text[] := ARRAY['pdf','pdf_sha256','renderer','rendered_at','render_attempts','last_error'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'fin_doc_pdfs is immutable: a stored document is never deleted';
    END IF;
    IF (to_jsonb(NEW) - render_cols) IS DISTINCT FROM (to_jsonb(OLD) - render_cols) THEN
        RAISE EXCEPTION 'fin_doc_pdfs is immutable: the stored HTML cannot change';
    END IF;
    IF OLD.pdf IS NOT NULL THEN
        RAISE EXCEPTION 'fin_doc_pdfs is immutable: a stored PDF is never replaced';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER fin_doc_pdfs_immutable BEFORE UPDATE OR DELETE ON fin_doc_pdfs
    FOR EACH ROW EXECUTE FUNCTION fin_doc_pdfs_guard();

-- ------------------------------------------------------------------ AIC-S2S v1 (inbound server-to-server)

-- Nonces seen in the last 600 s per key id (replay guard, contract §0.1); older rows are purged.
CREATE TABLE fin_s2s_nonces (
    key_id   TEXT NOT NULL,
    nonce    TEXT NOT NULL,
    seen_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (key_id, nonce)
);
CREATE INDEX fin_s2s_nonces_seen_idx ON fin_s2s_nonces (seen_at);

-- Fixed one-minute windows per key id and scope (contract §0.4: 120/min reads, 30/min issues, a PDF counts 5).
CREATE TABLE fin_s2s_rate (
    key_id        TEXT NOT NULL,
    scope         TEXT NOT NULL,
    window_start  TIMESTAMPTZ NOT NULL,
    used          INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key_id, scope, window_start)
);

-- Light access log of S2S list / detail reads, kept 180 days (contract §0.5, CERT-In). PDF downloads go to audit_log.
CREATE TABLE fin_s2s_access_log (
    id           BIGSERIAL PRIMARY KEY,
    occurred_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    client       TEXT NOT NULL,
    key_id       TEXT NOT NULL,
    actor        TEXT,
    request_id   TEXT NOT NULL,
    method       TEXT NOT NULL,
    path         TEXT NOT NULL,
    cmp_id       BIGINT,
    document_id  TEXT,
    http_status  INTEGER NOT NULL,
    ip           TEXT
);
CREATE INDEX fin_s2s_access_log_occurred_idx ON fin_s2s_access_log (occurred_at);

COMMENT ON TABLE fin_invoices IS 'GST tax invoices for AICOUNTLY subscriptions (immutable once issued; docs/INVOICING.md).';
