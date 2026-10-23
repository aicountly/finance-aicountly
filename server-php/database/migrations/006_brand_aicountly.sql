-- 006_brand_aicountly — the brand is written "Aicountly" (owner decision, 2026-10-23).
--
-- 003_invoicing.sql is already applied and is never edited (the migrator refuses a changed file), so its table comment
-- is corrected here. Idempotent: COMMENT ON replaces the comment, so re-running is harmless.
-- Numbered 006: 004 is taken by the unmerged claude/rectify-2026-10 (004_invoice_line_discounts) and 005 by
-- 005_academy_fees; the migrator applies pending files in name order, so any landing order works.
--
-- Deliberately NOT changed: the payout setting narration_prefix ('AICOUNTLY COMMISSION', seeded by 001_payouts.sql
-- and the default in SettingsService). It is written into the bank bulk-upload file's narration / remarks field,
-- which the bank parses and prints on the beneficiary's statement; banks commonly expect upper-case text there. A
-- finance admin can change it in Settings → Payouts if the bank accepts mixed case.

COMMENT ON TABLE fin_invoices IS 'GST tax invoices for Aicountly subscriptions and Aicountly Academy fees (immutable once issued; docs/INVOICING.md).';
