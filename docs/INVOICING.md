# Subscription invoicing (commerce plan, Phase A)

Finance issues every GST tax invoice and credit note for Aicountly subscriptions, and Manage
(manage.aicountly.com) shows them to the customer by reading them from Finance server to server.
Customers never sign in to Finance: the staff screens use Console SSO and Finance roles like the rest of
Finance, and the only routes Manage can call are signed AIC-S2S v1 reads.

Phase A builds the read path and staff issuing. **Not built (Phase B):** checkout, payment gateways, the Pay
integration, gateway-payment verification, refunds, and the licence order to Engage. The entry point a verified
gateway payment will use (`InvoiceService::issueForPayment`) exists and is tested; for subscriptions nothing calls it
yet. Aicountly Academy fees (Razorpay / Cashfree payment links, B2C student invoices) use it — see
[ACADEMY_PAYMENTS.md](ACADEMY_PAYMENTS.md).

Contract: `commerce-contract-v1-draft.md` §0 (AIC-S2S conventions), §1 (Finance inbound API), §3 (what triggers
issuance). Deviations are listed at the end.

## Dormant until configured

Nothing changes for payouts. Until a finance admin configures invoicing:

- `GET /api/health` reports `invoicing.status: "dormant"` and every `invoicing.checks` flag false (the top-level
  `status`, which reflects payout readiness, is not affected by invoicing at all);
- the Manage routes answer `503 inbound_not_configured` (no AIC-S2S key in the environment);
- recording an offline payment answers `422 seller_not_configured`; a payment verified while the series is missing
  waits as `blocked_config` and is invoiced by the cron once it exists.

## Who does what (Finance roles, `FinanceAccessPolicy::GATES`)

| Action | Gate | Roles |
| --- | --- | --- |
| Invoices register, invoice / credit note detail, PDF download (audited), offline payments list, Settings → Invoicing (read) | `view` | every Finance role |
| GSTR-1 CSV export (audited) | `edit` | accountant, payout approver, finance admin |
| Record an offline payment (maker) · request a credit note (maker) | `edit` | accountant, payout approver, finance admin |
| Verify an offline payment (checker → invoice issued) · approve / reject a credit note · reject a payment · retry a blocked invoice | `approve` | payout approver, finance admin |
| Seller profile, document series | `settings` | finance admin |

Four-eyes is always on for invoicing (it does not follow the payout setting `four_eyes_enabled`): the person
who recorded a payment cannot verify it, and the person who requested a credit note cannot approve it. Both rules
are also database constraints (`fin_offline_payments_four_eyes`, `fin_credit_notes_four_eyes`).

## Data model (migration `003_invoicing`)

| Table | Holds |
| --- | --- |
| `fin_seller_profile` | the seller printed on documents: legal / trade name, GSTIN, PAN (must be the PAN inside the GSTIN), address, state (from the GSTIN), signatory, `effective_from`. One active row; saving inserts a new row and retires the old one (rows are never edited or deleted — trigger). |
| `fin_doc_series` | one series per FY and `doc_type` (`tax_invoice` / `credit_note`): `prefix`, `next_no`, `width`. `prefix + width ≤ 16`, the prefix may not end in a digit, and a prefix is unique within its FY, so every number is unique within the FY across both document types. Prefix / width / next number can change only while no document is numbered from the series; `next_no` never goes back. |
| `fin_offline_payments` | staff-recorded receipts: company (`cmp_id`), buyer snapshot, lines, amount, method (NEFT / RTGS / IMPS / UPI / cheque), UTR (unique among non-rejected rows), received date, maker / checker, `status` recorded → verified \| rejected, `invoice_state` not_issued \| issued \| blocked_config \| blocked_data. What was recorded never changes (trigger). |
| `fin_invoices` + `fin_invoice_lines` | issued tax invoices. Public id `fin_inv_<ULID>`, `cmp_id`, number, date, FY, seller snapshot (JSON + GSTIN / state columns), buyer snapshot (legal name, GSTIN or null, state, address, PIN, email), place of supply, intra / inter-state, totals in paise, amount in words, payment block (method, gateway, reference, date), `source_ref` (`offline:<id>` / `payment:<id>`, unique), `payment_id` (unique, for Phase B) or `offline_payment_id` (unique). Lines: `item_code`, `item_kind`, description, SAC, GST rate, price basis, period, quantity, amount as billed, taxable value and CGST / SGST / IGST in paise. `status` issued \| partially_credited \| credited with `credited_paise`. |
| `fin_credit_notes` + `fin_credit_note_lines` | credit notes against one invoice (same company, trigger-checked): kind full / partial, reason, amounts per invoice line; `status` pending_approval → issued \| rejected. Number, date and seller snapshot are assigned at approval. |
| `fin_doc_pdfs` | per document: the HTML stored in the issuing transaction (+ sha256) and the PDF bytes (+ sha256) once rendered. |
| `fin_s2s_nonces`, `fin_s2s_rate`, `fin_s2s_access_log` | AIC-S2S replay guard (600 s), per-key one-minute rate windows, access log of Manage list / detail reads (kept 180 days). |

Money is `BIGINT` paise everywhere; the API's document fields end in `_paise` (contract). Invoices and credit
notes share one id sequence (`fin_document_seq`), which keeps the `(doc_date, id)` cursor unambiguous.

**Immutability (database triggers):** an issued invoice cannot be updated (except `status` / `credited_paise`,
and `credited_paise` only upwards) or deleted; lines can only be inserted in the transaction that created their
document and never changed; an issued or rejected credit note never changes; the stored HTML never changes and a
stored PDF is never replaced. At commit, a deferred trigger checks every new document has lines and that its
totals are exactly the sums of its lines. Corrections are made by credit note only.

## Numbering

`doc_no = prefix || lpad(next_no, width, '0')`, e.g. prefix `AIC/26-27/` + width 6 → `AIC/26-27/000123`
(16 characters, CGST Rule 46(b): letters, digits, `-` and `/`, consecutive, unique for the FY).

The number is taken inside the issuing transaction with `SELECT … FOR UPDATE` on the series row: a second issuer
waits for the first to commit or roll back, and a rollback also undoes the increment, so there are no gaps and no
duplicates (tested with two concurrent worker processes and with a rolled-back issue). The FY is the Indian FY of
the document date in IST (`App\Support\FiscalYear`). On 1 April the new FY needs its own series; without it issuing
stops with `series_not_configured` (payments wait as `blocked_config`) — **create next year's two series in March.**

A series whose numbers no longer fit its width answers `422 series_exhausted`.

## Tax rules (`App\Services\Invoicing\GstCalculator`)

- The GST rate comes from each line (`gst_rate`), never a constant.
- `price_basis = inclusive`: tax = round(amount × R / (100 + R)) to the paisa, half up; taxable = amount − tax.
  `exclusive`: the amount is the taxable value; tax = round(amount × R / 100), added on top.
- Place of supply = the buyer GSTIN's state code; without a GSTIN, the billing state. A buyer's state must equal its
  GSTIN's state (otherwise `422`).
- Seller state (from the seller GSTIN) = place of supply → CGST + SGST with **SGST = floor(tax / 2) and CGST the odd
  paisa**; otherwise IGST.
- Each line is rounded on its own; document totals are sums of lines.
- GSTIN: format, known state code and the GSTN mod-36 check character (`App\Support\Gstin`). Buyers outside India
  are refused (exports are not supported in Phase A).
- Amount in words: Indian system (thousand / lakh / crore), "Rupees … and … Paise Only" — ported from Smart Books'
  `AmountInWordsService` (attribution in `App\Support\AmountInWords`).

### Credit notes

Full: credits exactly what is still left on every line (taxable value and each tax head), so a fully credited
invoice nets to zero. Partial: a GST-inclusive amount per line, split at the line's rate like an inclusive price
and kept within every remaining head. Pending requests count against what is left; approval re-checks against
issued notes (`409 over_credit` if another one won). Deadline (s.34(2) CGST Act): 30 November after the end of the
invoice's FY (`422 credit_note_deadline_passed`); the annual-return date, if earlier, is not tracked.

## PDF

`App\Services\Invoicing\DocumentHtml` renders a self-contained A4 HTML document (no external fonts, images or
scripts): seller block (name, address, state, GSTIN, PAN), document number / date / place of supply / reverse
charge, buyer block (GSTIN or "Unregistered"), lines with SAC, taxable value and the CGST + SGST or IGST split with
rates, totals, amount in words, the **Paid** block (amount, date, method, reference — invoices), the original
invoice and reason (credit notes), "For <seller> — Authorised signatory: <name, designation>" and "This is a
computer-generated invoice / credit note."

The HTML is stored with the document in the issuing transaction. Right after commit it is sent to Gotenberg
(`GOTENBERG_URL`, client ported from Smart Books' `GotenbergPdfClient`) and the PDF bytes are stored with their
sha256. If the PDF service is not configured or fails, the document is still issued; the PDF routes answer
`503 pdf_unavailable` and `bin/invoicing-cron.php` renders **the stored HTML** later. A stored PDF is streamed
as-is on every read (checksum verified), never re-rendered.

## Manage read API (AIC-S2S v1)

| Route (all `GET`, read keys only) | Answer |
| --- | --- |
| `/api/v1/inbound/manage/documents?cmp_id=&fy=&doc_type=&limit=&cursor=` | issued invoices and credit notes of that company, newest first (`doc_date DESC, id DESC`); `limit` 1–100 (25); `page.next_cursor` null on the last page. Each row: `document_id, doc_type, doc_no, doc_date, fy, total_paise, status, summary, period_start, period_end, related[]`. |
| `/api/v1/inbound/manage/documents/{document_id}?cmp_id=` | everything on the face of the document: seller, buyer, place of supply, lines (SAC, taxable, tax split, rates), totals, `amount_in_words`, `payment` (invoices), `original_invoice` and `reason` (credit notes), `irn` / `ack_no` / `ack_date` (null), `pdf_sha256`. |
| `/api/v1/inbound/manage/documents/{document_id}/pdf?cmp_id=` | `200 application/pdf`, `Content-Disposition: attachment; filename="AIC-26-27-000123.pdf"`, `ETag: "<sha256>"`, `Cache-Control: private, no-store`. Counts 5 towards the rate limit. |

Every query is filtered by `cmp_id`; another company's document, an unknown id and a pending or rejected credit
note all answer exactly the same `404 not_found`. A company without documents gets an empty list. Bad query
parameters → `400 bad_request` (`details.field`).

Envelope: `{"success":true,"data":…,"page":{"next_cursor":…,"limit":25},"request_id":"…"}` /
`{"success":false,"error":{"code","message","details","retryable"},"request_id":"…"}`; `request_id` echoes
`X-AIC-Request-Id` (also returned as a header).

Audit: each PDF download is an `audit_log` row (`document.pdf_downloaded`, actor `service:manage`, actor name =
`X-AIC-Actor`, with `cmp_id`, `document_id`, `request_id`, key id); list and detail reads go to
`fin_s2s_access_log`.

Rate limits per key id (fixed one-minute windows): 120 units/min for read keys, 30 for issue keys (configurable),
a PDF counts 5 → `429 rate_limited` with `Retry-After`.

These routes never accept a Finance staff token, get no CORS headers and have no `OPTIONS` grant: a browser cannot
use them.

### Authentication

```
X-AIC-Client:      manage
X-AIC-Key-Id:      manage-read-2026-10
X-AIC-Timestamp:   <unix seconds>                (refused if more than 300 s from Finance's clock)
X-AIC-Nonce:       <uuid v4>                     (refused if seen in the last 600 s for this key id)
X-AIC-Environment: production | sandbox | local  (must equal Finance's FINANCE_S2S_ENVIRONMENT)
X-AIC-Signature:   v1=<hex HMAC-SHA256(secret, canonical)>
X-AIC-Actor:       <my.aicountly uuid of the customer> | service   (audit only)
X-AIC-Request-Id:  <uuid>

canonical = METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" hex(sha256(raw body or ""))
  PATH            the path after /api, e.g. /v1/inbound/manage/documents
  CANONICAL_QUERY pairs sorted by key (then value), RFC 3986 encoded ("%20", not "+"), k=v joined by &
```

The secret for key id `manage-read-2026-10` is the environment variable
`FINANCE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10` (the key id upper-cased, every other character → `_`), at least
32 bytes; Manage holds the same value. Its scope is `FINANCE_INBOUND_MANAGE_SCOPE_<KEYID>` (`read` by default, or
`issue`); a read key may only call read routes and an issue key only issue routes (there are no issue routes in
Phase A). Signatures are compared in constant time.

Refusals, in the order they are checked: `401 signature_missing` → `400 environment_required` →
`403 environment_mismatch` → `401 unknown_key` (unknown client / key id, or a secret shorter than 32 bytes, which
is ignored) → `401 timestamp_stale` → `401 signature_invalid` → `403 route_not_allowed_for_key` →
`401 nonce_replayed` → `429 rate_limited`. `503 inbound_not_configured` when no key is set,
`503 database_unavailable` when PostgreSQL is down.

**Worked example** (example secret, never use it):

```
secret     manage-to-finance-example-secret-0123456789
request    GET /api/v1/inbound/manage/documents?limit=25&cmp_id=42
timestamp  1760781600
nonce      3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37

canonical (6 lines, no trailing newline):
GET
/v1/inbound/manage/documents
cmp_id=42&limit=25
1760781600
3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855

X-AIC-Signature: v1=2c93e407965eea0f40532c26a36b978912a090d845d7a662983489139110684b
```

Reproduce it with
`printf 'GET\n/v1/inbound/manage/documents\ncmp_id=42&limit=25\n1760781600\n3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855' | openssl dgst -sha256 -hmac 'manage-to-finance-example-secret-0123456789'`
or `App\Security\AicS2s::sign()` (a unit test pins this value).

## Environment variables (server-php `.env`)

| Variable | Purpose |
| --- | --- |
| `FINANCE_INBOUND_MANAGE_SECRET_<KEYID>` | AIC-S2S secret Manage signs with (≥ 32 bytes). Two key ids may be live during a rotation. |
| `FINANCE_INBOUND_MANAGE_SCOPE_<KEYID>` | optional: `read` (default) or `issue` |
| `FINANCE_S2S_ENVIRONMENT` | `production` \| `sandbox` \| `local`; default `production` when `APP_ENV=production`, else `local` |
| `FINANCE_S2S_READ_PER_MINUTE` / `FINANCE_S2S_ISSUE_PER_MINUTE` | optional rate limits per key id (120 / 30) |
| `GOTENBERG_URL` | PDF service base URL (unset → documents stored as HTML only, PDF routes 503) |
| `GOTENBERG_AUTH_MODE` | `none` (default) \| `basic` \| `bearer` |
| `GOTENBERG_USERNAME`, `GOTENBERG_PASSWORD` / `GOTENBERG_BEARER_TOKEN` | credentials for that mode |
| `GOTENBERG_TIMEOUT_SECONDS` | optional, default 30 |

The seller profile and the series are not environment variables: they are saved in Settings → Invoicing (audited).

## Health (`GET /api/health`, booleans only)

```
"invoicing": {"status": "dormant" | "incomplete" | "ready", "checks": {
  "schema":            migration 003 applied
  "seller_profile":    a seller profile is saved
  "series_current_fy": tax_invoice and credit_note series exist for the current FY
  "pdf_renderer":      GOTENBERG_URL is set
  "manage_inbound":    at least one FINANCE_INBOUND_MANAGE_SECRET_<KEYID> of ≥ 32 bytes
  "issuance_blocked":  true = a verified payment is waiting for its invoice (alert)
  "pdfs_pending":      true = an issued document has no stored PDF yet
}}
```

`ready` needs the first five true and the last two false; `dormant` means neither a seller profile nor a Manage key.

## Runbook

**Activate** (after deploying; `bin/migrate.php` applies `003_invoicing`):

1. Agree a key id and a ≥ 32-byte secret with the Manage operator (`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`),
   set `FINANCE_INBOUND_MANAGE_SECRET_<KEYID>` here (and `FINANCE_S2S_ENVIRONMENT` if this is not production).
2. Set `GOTENBERG_URL` (+ auth) to the PDF service.
3. As a finance admin: Settings → Invoicing → save the seller profile (legal name, GSTIN, address, signatory), then
   add the current FY's tax invoice and credit note series (e.g. `AIC/26-27/` and `CN/26-27/`, 6 digits; set the
   next number higher only to continue an existing manual series).
4. Add the cron line below and check `/api/health` → `invoicing.status: "ready"`.

**Cron** (every 5 minutes; safe before invoicing is configured):

```cron
*/5 * * * * php /home/<user>/public_html/api/bin/invoicing-cron.php >> /home/<user>/logs/finance-invoicing.log 2>&1
```

It retries verified payments in `blocked_config`, renders missing PDFs from the stored HTML (attempts and the last
error are kept per document), and purges old nonces, rate windows and access-log rows (180 days).

**Offline payment → invoice:** a maker records the receipt (Offline payments → Record payment; the screen computes
the GST first; the amount received must equal the invoice total). Someone else verifies it against the bank
statement → the invoice is issued and appears in Manage. A wrong record is rejected (with a reason) and recorded
again; the UTR becomes free when rejected.

**`issuance_blocked` in health:** open Offline payments; a `Blocked: setup missing` payment names what is missing
(seller profile or the FY's series). Fix it in Settings → Invoicing; the cron (or "Retry invoice") issues it.

**`pdfs_pending` in health:** check `GOTENBERG_URL` and the PDF service; the invoice detail shows the last error.
The cron keeps retrying.

**Mistake on an issued invoice:** request a credit note (full or partial) on the invoice; someone else approves
it. For a wrong buyer, credit in full and issue a new invoice from a corrected payment record.

**Rotate the Manage key:** add the new `FINANCE_INBOUND_MANAGE_SECRET_<NEWKEYID>`, let Manage switch, then remove
the old one.

**Backups:** the invoice tables are part of the Finance database backup. Invoice data is not encrypted with
`FINANCE_DATA_KEY` (it is printed on the documents and filed in GSTR-1).

## GSTR-1 CSV

Invoices → Export CSV: one row per document per (SAC, rate) with the GSTR-1 table it belongs to — `B2B` (buyer has a
GSTIN), `B2CL` (inter-state, unregistered, document value above ₹1,00,000 — Notification 12/2024-CT), `B2CS`
(other unregistered), `CDNR` (credit notes to registered buyers), `CDNUR` (credit notes against B2CL invoices); a
credit note to a B2CS buyer is listed as `B2CS` and reduces that table. Columns: section, document type, number,
date (dd/mm/yyyy), original invoice number and date, company, buyer name, GSTIN, place of supply (`27-Maharashtra`),
supply type, reverse charge, document value, SAC, rate, taxable value, IGST, CGST, SGST, cess. Values are positive;
the document type says which rows are credit notes.

## Confirm with your CA

- SAC per product (997331 is used in tests only; the plan's Q3 is open) and the GST rate per line.
- GST-inclusive vs exclusive pricing (both are supported per line).
- The B2CL threshold and the GSTR-1 mapping above; the credit-note deadline rule.
- Whether e-invoicing (IRN) applies (turnover > ₹5 cr): `irn` / `ack_no` are always null in Phase A.
- State codes 25 (Daman & Diu) and 28 (undivided Andhra Pradesh) are not accepted for new documents.

## Phase B hooks

- `InvoiceService::issueForPayment($paymentId, PaymentInvoiceSource $source)` issues the invoice for a gateway
  payment Finance has verified, idempotent on `payment_id`; `seller_not_configured` / `series_not_configured` are
  thrown after an ops alert so the caller can mark the payment `blocked_config`; bad order data → `422 blocked_data`.
  **Wired for Aicountly Academy fees** (migration `005_academy_fees`, [ACADEMY_PAYMENTS.md](ACADEMY_PAYMENTS.md)):
  `App\Services\AcademyFees\GatewayPaymentInvoiceSource` reads `fin_gateway_payments`, and the buyer is an
  `academy_student` (`fin_invoices.buyer_kind`; `cmp_id` NULL, no GSTIN — company rows keep `cmp_id` required). A
  future SaaS checkout should record its gateway payments in the same `fin_gateway_payments` table; the
  `fin_invoices.payment_id` foreign key is still not added (the test fixtures use synthetic payment ids).
- Refund-driven credit notes will reuse `CreditNoteService`'s issue path.

## Deviations from the contract draft

- **Secret names** follow contract §0.1 (`FINANCE_INBOUND_MANAGE_SECRET_<KEYID>`); the key's scope is declared in
  `FINANCE_INBOUND_MANAGE_SCOPE_<KEYID>` (the contract recommends separate read / issue keys without saying how a
  receiver knows which is which).
- **16-character numbers:** the contract's example credit-note number `AIC-CN/26-27/000004` is 19 characters and is
  refused (Rule 46(b)); use e.g. `CN/26-27/` with 6 digits.
- **Status values:** a fully credited invoice is `credited` (the contract's `cancelled_by_credit_note` is not used).
- **Extra error codes:** `503 pdf_unavailable` (retryable) when a document's PDF is not stored yet,
  `500 internal_error`; a request with no signing headers at all is `401 signature_missing` (the environment header
  is checked once the request is signed).
- **Nonce format:** 16–64 characters of letters, digits and `-` (a uuid v4 is what callers should send).
- **`X-Saas-Origin`** is not checked (Finance makes no call back to the caller on these routes).
- **Extra fields** on the document (`summary`, `totals.tax_paise`, per-line `amount_paise`, `price_basis`, rates) are
  additive.
- **Offline payments** must match the invoice total exactly (no TDS short-payments; plan Q10 is open).
