-- Discounted invoice lines (shared contract §4.2, founder decision D-C1: GST is charged on the price after discount).
--
-- Additive and nullable: a line without a discount keeps all three columns NULL and is stored, computed and printed
-- exactly as before. A discounted line carries
--   list_amount_paise  the list price on the line's own price basis (incl. GST for inclusive, taxable for exclusive)
--   discount_paise     the discount agreed before supply (coupon / quote), same basis
--   discount_ref       the coupon code or Engage quote_ref printed next to the discount
-- and amount_paise (as billed) = list − discount: GstCalculator runs on that discounted amount, unchanged, so the
-- taxable value and the GST are on the price after discount (CGST Act s.15(3)(a)).
-- Rules: discount ≥ 0 and (when > 0) ≤ list − 100 (the net is never below ₹1), and a positive discount names its ref.
--
-- Credit notes copy the three fields from the invoice line they credit (for reference on the note); the amounts a
-- credit note credits are still the net that was invoiced (taxable value + GST of the line), never the discount.
-- Lines stay immutable (fin_document_lines_guard): adding columns does not change any stored row.

ALTER TABLE fin_invoice_lines
    ADD COLUMN list_amount_paise BIGINT,
    ADD COLUMN discount_paise    BIGINT,
    ADD COLUMN discount_ref      TEXT;

ALTER TABLE fin_invoice_lines
    ADD CONSTRAINT fin_invoice_lines_discount_chk CHECK (
        (list_amount_paise IS NULL AND discount_paise IS NULL AND discount_ref IS NULL)
        OR (list_amount_paise IS NOT NULL AND discount_paise IS NOT NULL
            AND discount_paise >= 0
            AND (discount_paise = 0 OR discount_paise <= list_amount_paise - 100)
            AND list_amount_paise = amount_paise + discount_paise
            AND (discount_paise = 0 OR (discount_ref IS NOT NULL AND btrim(discount_ref) <> ''))
            AND (discount_ref IS NULL OR length(discount_ref) <= 64))
    );

ALTER TABLE fin_credit_note_lines
    ADD COLUMN list_amount_paise BIGINT,
    ADD COLUMN discount_paise    BIGINT,
    ADD COLUMN discount_ref      TEXT;

ALTER TABLE fin_credit_note_lines
    ADD CONSTRAINT fin_credit_note_lines_discount_chk CHECK (
        (list_amount_paise IS NULL AND discount_paise IS NULL AND discount_ref IS NULL)
        OR (list_amount_paise IS NOT NULL AND discount_paise IS NOT NULL AND discount_paise >= 0 AND discount_paise < list_amount_paise)
    );
