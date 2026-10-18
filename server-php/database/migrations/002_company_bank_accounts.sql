-- 002_company_bank_accounts — the company's own (debit) bank accounts that seller payouts are paid from.
--
-- Replaces the single setting `debit_account_number`. The account number is libsodium secretbox ciphertext
-- ("v1:<base64>") written by App\Services\DataVault with FINANCE_DATA_KEY, like the payee data; only the last
-- four digits are stored in clear, and account_number_hash (keyed HMAC blind index) refuses duplicates.
--
-- Moving an existing `debit_account_number` value in here needs FINANCE_DATA_KEY, which SQL does not have:
-- App\Services\CompanyBankAccountService::adoptLegacySetting() does it right after this migration
-- (bin/migrate.php), or on first use if the key was missing then. It creates "Primary account" (default),
-- writes audit_log and blanks the setting, so the number is no longer kept in clear in `settings`.

CREATE TABLE company_bank_accounts (
    id                    BIGSERIAL PRIMARY KEY,
    label                 TEXT NOT NULL CHECK (btrim(label) <> ''),
    bank_name             TEXT,                 -- NULL only on the row adopted from the old setting; the API requires it
    account_holder        TEXT NOT NULL DEFAULT '',  -- '' only on the adopted row; the API requires it
    account_number_enc    TEXT NOT NULL CHECK (account_number_enc LIKE 'v1:%'),
    account_number_last4  TEXT NOT NULL CHECK (account_number_last4 ~ '^[0-9]{4}$'),
    account_number_hash   TEXT NOT NULL,
    ifsc                  TEXT CHECK (ifsc IS NULL OR ifsc ~ '^[A-Z]{4}0[A-Z0-9]{6}$'),  -- NULL only on the adopted row
    branch                TEXT,
    account_type          TEXT NOT NULL DEFAULT 'current' CHECK (account_type IN ('current','savings','od','cc')),
    bank_file_format      TEXT,                 -- NULL = the Settings default (bank_file_format); else a BankFileFormatter format
    is_default            BOOLEAN NOT NULL DEFAULT false,
    active                BOOLEAN NOT NULL DEFAULT true,
    source                TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','legacy_setting')),
    created_by            TEXT NOT NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_by            TEXT,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (active OR NOT is_default)          -- an inactive account is never the default
);
CREATE UNIQUE INDEX company_bank_accounts_number_uniq ON company_bank_accounts (account_number_hash);
-- At most one default among the active accounts…
CREATE UNIQUE INDEX company_bank_accounts_one_default ON company_bank_accounts ((true)) WHERE is_default AND active;

-- …and at least one whenever any account is active, checked at commit (so a change of default inside one
-- transaction may pass through "none" for a moment).
CREATE FUNCTION company_bank_accounts_default_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    n_active  integer;
    n_default integer;
BEGIN
    SELECT count(*) FILTER (WHERE active), count(*) FILTER (WHERE active AND is_default)
      INTO n_active, n_default FROM company_bank_accounts;
    IF n_active > 0 AND n_default <> 1 THEN
        RAISE EXCEPTION 'company_bank_accounts: exactly one active account must be the default (% active, % default)', n_active, n_default
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER company_bank_accounts_default_exactly_one
    AFTER INSERT OR UPDATE OR DELETE ON company_bank_accounts
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION company_bank_accounts_default_check();

-- Which company account a generated payment file debits, with a snapshot of what it showed then (the account's
-- label or number may be edited later). NULL on files generated before this migration and on response files.
ALTER TABLE bank_files
    ADD COLUMN bank_account_id     BIGINT REFERENCES company_bank_accounts(id),
    ADD COLUMN debit_account_label TEXT,
    ADD COLUMN debit_account_last4 TEXT;

-- The old settings key stays (GET v1/settings still answers `debit_account_number`, masked, derived from the
-- default account) but nothing uses its stored value any more except the one-time adoption above.
COMMENT ON TABLE company_bank_accounts IS 'Company (debit) bank accounts for seller payout bank files. Replaces settings.debit_account_number.';
