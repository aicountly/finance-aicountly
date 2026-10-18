-- 001_payouts — weekly seller payouts (drafts from Engage → review → approval → bank file → UTR).
--
-- Money: NUMERIC(14,2) INR. Rates: NUMERIC(5,2) percent.
-- Sensitive numbers (PAN, GSTIN — it contains the PAN —, bank account) are libsodium secretbox ciphertext ("v1:<base64>") written by
-- App\Services\DataVault with FINANCE_DATA_KEY; only the last four characters are stored in clear, and
-- pan_hash is a keyed blind index (HMAC) so TDS can be cumulated per PAN without decrypting.

CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by TEXT
);

-- Defaults. The operator must confirm the tax values with their CA (docs/DEPLOYMENT.md § Tax assumptions).
INSERT INTO settings (key, value) VALUES
    ('tds_rate_percent',      '2.00'),            -- section 194H rate
    ('tds_threshold_per_fy',  '20000.00'),        -- 194H annual threshold per payee (PAN)
    ('gst_mode',              'exclusive'),       -- none | exclusive (GST added on top) | inclusive (gross includes GST)
    ('gst_rate_percent',      '18.00'),
    ('minimum_payout',        '0.00'),            -- items whose net is below this are put on hold (0 = off; Engage applies its own rollover minimum)
    ('bank_file_format',      'generic_neft_csv'),
    ('payment_mode',          'NEFT'),            -- NEFT | IMPS | RTGS
    ('debit_account_number',  ''),               -- the company account the bank debits (required to generate a bank file)
    ('narration_prefix',      'AICOUNTLY COMMISSION'),
    ('four_eyes_enabled',     'true');            -- approver must differ from whoever submitted / last edited the batch

CREATE TABLE payees (
    id                  BIGSERIAL PRIMARY KEY,
    seller_code         TEXT NOT NULL UNIQUE,
    legal_name          TEXT NOT NULL,
    entity_type         TEXT NOT NULL,
    pan_enc             TEXT NOT NULL,
    pan_last4           TEXT NOT NULL,
    pan_hash            TEXT NOT NULL,
    gstin_enc           TEXT,                 -- a GSTIN embeds the PAN, so it is encrypted too
    gstin_masked        TEXT,                 -- state code + XXXXXXXXXX + last 3, e.g. 27XXXXXXXXXX1Z5
    bank_holder_name    TEXT NOT NULL,
    bank_account_enc    TEXT NOT NULL,
    bank_account_last4  TEXT NOT NULL,
    bank_account_hash   TEXT NOT NULL,
    bank_ifsc           TEXT NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX payees_pan_hash_idx ON payees (pan_hash);

CREATE TABLE payout_batches (
    id                  BIGSERIAL PRIMARY KEY,
    batch_ref           TEXT NOT NULL UNIQUE,
    source              TEXT NOT NULL DEFAULT 'engage',
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    currency            TEXT NOT NULL DEFAULT 'INR' CHECK (currency = 'INR'),
    fy                  TEXT NOT NULL,
    status              TEXT NOT NULL DEFAULT 'draft' CHECK (status IN
                          ('draft','in_review','approved','file_generated','partially_paid','paid','cancelled')),
    payload_sha256      TEXT NOT NULL,
    version             INTEGER NOT NULL DEFAULT 1,
    prepared_by         TEXT,            -- who submitted the batch for review
    prepared_at         TIMESTAMPTZ,
    last_edited_by      TEXT,            -- who last changed an item (hold, adjust, recalculate)
    last_edited_at      TIMESTAMPTZ,
    approved_by         TEXT,
    approved_at         TIMESTAMPTZ,
    cancelled_by        TEXT,
    cancelled_at        TIMESTAMPTZ,
    cancel_reason       TEXT,
    received_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (period_start <= period_end),
    CHECK (status <> 'approved' OR approved_by IS NOT NULL),
    CHECK (status <> 'cancelled' OR (cancelled_by IS NOT NULL AND cancel_reason IS NOT NULL))
);
CREATE INDEX payout_batches_status_idx ON payout_batches (status);

CREATE TABLE payout_items (
    id                  BIGSERIAL PRIMARY KEY,
    batch_id            BIGINT NOT NULL REFERENCES payout_batches(id),
    item_ref            TEXT NOT NULL,
    payee_id            BIGINT NOT NULL REFERENCES payees(id),
    seller_code         TEXT NOT NULL,
    legal_name          TEXT NOT NULL,
    entity_type         TEXT NOT NULL,
    pan_enc             TEXT NOT NULL,        -- snapshot at batch time (Form 26Q needs the PAN that was deducted against)
    pan_hash            TEXT NOT NULL,
    pan_last4           TEXT NOT NULL,
    gstin_enc           TEXT,
    gstin_masked        TEXT,                 -- NULL = payee has no GSTIN (no GST on the payout)
    bank_holder_name    TEXT NOT NULL,
    bank_account_enc    TEXT NOT NULL,
    bank_account_last4  TEXT NOT NULL,
    bank_ifsc           TEXT NOT NULL,
    bank_changed        BOOLEAN NOT NULL DEFAULT false,  -- bank details differ from this payee's previous payout
    entry_ids           JSONB NOT NULL DEFAULT '[]'::jsonb,
    gross_amount        NUMERIC(14,2) NOT NULL CHECK (gross_amount > 0),
    taxable_amount      NUMERIC(14,2) NOT NULL,
    gst_mode            TEXT NOT NULL,
    gst_rate            NUMERIC(5,2) NOT NULL DEFAULT 0,
    gst_amount          NUMERIC(14,2) NOT NULL DEFAULT 0,
    tds_section         TEXT NOT NULL DEFAULT '194H',
    tds_rate            NUMERIC(5,2) NOT NULL DEFAULT 0,
    tds_amount          NUMERIC(14,2) NOT NULL DEFAULT 0,
    tds_note            TEXT,
    adjustment_total    NUMERIC(14,2) NOT NULL DEFAULT 0,
    net_amount          NUMERIC(14,2) NOT NULL CHECK (net_amount >= 0),
    status              TEXT NOT NULL DEFAULT 'draft' CHECK (status IN
                          ('draft','on_hold','approved','paid','failed','reversed')),
    hold_reason         TEXT,
    failure_reason      TEXT,
    utr                 TEXT,
    paid_at             TIMESTAMPTZ,
    payment_recorded_by TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (batch_id, item_ref),
    -- Nothing is ever paid without a recorded UTR (also enforced in PayoutService).
    CONSTRAINT payout_items_paid_needs_utr CHECK (
        status NOT IN ('paid','reversed') OR (utr IS NOT NULL AND btrim(utr) <> '' AND paid_at IS NOT NULL)
    ),
    CHECK (status <> 'on_hold' OR hold_reason IS NOT NULL)
);
CREATE INDEX payout_items_batch_idx ON payout_items (batch_id);
CREATE INDEX payout_items_payee_idx ON payout_items (payee_id);
CREATE UNIQUE INDEX payout_items_utr_uniq ON payout_items (upper(utr)) WHERE utr IS NOT NULL;

CREATE TABLE payout_adjustments (
    id          BIGSERIAL PRIMARY KEY,
    item_id     BIGINT NOT NULL REFERENCES payout_items(id),
    amount      NUMERIC(14,2) NOT NULL CHECK (amount <> 0),
    reason      TEXT NOT NULL CHECK (btrim(reason) <> ''),
    created_by  TEXT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX payout_adjustments_item_idx ON payout_adjustments (item_id);

-- One row per payout item. provisional (draft / approved, not yet paid) → deducted (paid) | void
-- (failed, cancelled, reversed). FY cumulative per PAN = sum over non-void rows.
CREATE TABLE tds_ledger (
    id              BIGSERIAL PRIMARY KEY,
    item_id         BIGINT NOT NULL UNIQUE REFERENCES payout_items(id),
    pan_hash        TEXT NOT NULL,
    fy              TEXT NOT NULL,
    section         TEXT NOT NULL DEFAULT '194H',
    taxable_amount  NUMERIC(14,2) NOT NULL,
    tds_rate        NUMERIC(5,2) NOT NULL,
    tds_amount      NUMERIC(14,2) NOT NULL,
    status          TEXT NOT NULL DEFAULT 'provisional' CHECK (status IN ('provisional','deducted','void')),
    deducted_on     DATE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (status <> 'deducted' OR deducted_on IS NOT NULL)
);
CREATE INDEX tds_ledger_pan_fy_idx ON tds_ledger (pan_hash, fy);

-- Generated bank bulk-upload files (kind payment) and uploaded bank response files (kind response).
-- Content holds full account numbers, so it is encrypted like the payee data.
CREATE TABLE bank_files (
    id            BIGSERIAL PRIMARY KEY,
    batch_id      BIGINT NOT NULL REFERENCES payout_batches(id),
    kind          TEXT NOT NULL DEFAULT 'payment' CHECK (kind IN ('payment','response')),
    format        TEXT NOT NULL,
    filename      TEXT NOT NULL,
    content_enc   TEXT NOT NULL,
    sha256        TEXT NOT NULL,
    item_count    INTEGER NOT NULL,
    total_amount  NUMERIC(14,2) NOT NULL,
    superseded    BOOLEAN NOT NULL DEFAULT false,
    note          TEXT,
    created_by    TEXT NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX bank_files_batch_idx ON bank_files (batch_id);

-- Every change: who / what / when / before / after. Append-only.
CREATE TABLE audit_log (
    id           BIGSERIAL PRIMARY KEY,
    occurred_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    actor        TEXT NOT NULL,
    actor_name   TEXT,
    action       TEXT NOT NULL,
    entity_type  TEXT NOT NULL,
    entity_id    TEXT,
    before       JSONB,
    after        JSONB,
    ip           TEXT
);
CREATE INDEX audit_log_entity_idx ON audit_log (entity_type, entity_id);
CREATE INDEX audit_log_occurred_idx ON audit_log (occurred_at DESC);

CREATE FUNCTION audit_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'audit_log is append-only';
END;
$$;
CREATE TRIGGER audit_log_no_update BEFORE UPDATE OR DELETE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION audit_log_append_only();

-- Status callbacks to Engage, delivered by bin/outbox-deliver.php (cron, every 5 min).
-- Events for one aggregate (one payout item) are delivered strictly in order.
CREATE TABLE outbox (
    id               BIGSERIAL PRIMARY KEY,
    event_key        TEXT NOT NULL UNIQUE,     -- sent as Idempotency-Key
    aggregate_key    TEXT NOT NULL,
    target           TEXT NOT NULL DEFAULT 'engage',
    path             TEXT NOT NULL,
    payload          JSON NOT NULL,            -- json (not jsonb): sent byte-for-byte as queued
    status           TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','delivered','dead')),
    attempts         INTEGER NOT NULL DEFAULT 0,
    next_attempt_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_http_status INTEGER,
    last_error       TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    delivered_at     TIMESTAMPTZ
);
CREATE INDEX outbox_due_idx ON outbox (status, next_attempt_at);
CREATE INDEX outbox_aggregate_idx ON outbox (aggregate_key, id);
