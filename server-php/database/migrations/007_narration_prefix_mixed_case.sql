-- 007_narration_prefix_mixed_case — the payout narration prefix is written "Aicountly Commission" (owner decision,
-- 2026-10-23). 001_payouts.sql seeded 'AICOUNTLY COMMISSION' and is never edited, so the seeded row is updated here —
-- only while it still holds exactly that seeded value: a prefix a finance admin has already changed is left alone.
-- Idempotent: once updated (or when customised) the WHERE matches nothing. The change is recorded in the append-only
-- audit_log like a Settings change.
--
-- The prefix is written as-is into the bank upload file's narration fields (generic_neft_csv: Narration;
-- icici_hdfc_style_csv: DEBIT_NARR / CREDIT_NARR). BankFileFormatter keeps the case (it only strips characters outside
-- letters, digits, space and . , & - / ( ) and trims to 30); no format upper-cases it. If your bank rejects lower case
-- in these fields, set an upper-case prefix in Settings → Bank file → Narration prefix.

WITH changed AS (
    UPDATE settings
       SET value = 'Aicountly Commission', updated_at = now(), updated_by = 'system:migration'
     WHERE key = 'narration_prefix' AND value = 'AICOUNTLY COMMISSION'
 RETURNING key
)
INSERT INTO audit_log (actor, actor_name, action, entity_type, entity_id, before, after)
SELECT 'system:migration', 'Migration 007 (brand: Aicountly)', 'settings.updated', 'settings', 'narration_prefix',
       '{"narration_prefix": "AICOUNTLY COMMISSION"}'::jsonb, '{"narration_prefix": "Aicountly Commission"}'::jsonb
  FROM changed;
