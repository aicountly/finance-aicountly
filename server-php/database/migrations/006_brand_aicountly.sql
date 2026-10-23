-- 006_brand_aicountly — the brand is written "Aicountly" (owner decision, 2026-10-23).
--
-- 003_invoicing.sql is already applied and is never edited (the migrator refuses a changed file), so its table comment
-- is corrected here. Idempotent: COMMENT ON replaces the comment, so re-running is harmless.
-- Numbered 006: after 004_invoice_line_discounts and 005_academy_fees; the migrator applies pending files in name order.
--
-- The payout narration prefix seeded by 001_payouts.sql ('AICOUNTLY COMMISSION') is handled by
-- 007_narration_prefix_mixed_case (owner decision: "Aicountly Commission"; an admin's custom value is kept).

COMMENT ON TABLE fin_invoices IS 'GST tax invoices for Aicountly subscriptions and Aicountly Academy fees (immutable once issued; docs/INVOICING.md).';
