# Aicountly Academy fees: payment links (Phase 1)

Aicountly Academy sells two kinds of training to college students: **Aicountly Edge** (long-term programmes) and
**Aicountly Aspire** (short courses and workshops). academy.aicountly.org defines each fee and its GST treatment. The
fee is sales revenue, so the sale runs through engage.aicountly.org: leads, the sales bot and seller commission.
Aicountly's own Razorpay and Cashfree merchant accounts belong in Finance, so Finance creates the payment links,
verifies payments, issues the GST invoice and tells Engage the fee is paid.

> **Status:** built on branch `claude/academy-fees-gateway`, **not deployed**. It must not go live for the 2026-10-25
> soft launch without the owner's review and the checklist below. On launch day, links are created by hand in the
> Razorpay / Cashfree dashboards (see [Launch day](#launch-day-manual-links)).
>
> The deploy workflow runs `bin/migrate.php`, so deploying this branch would apply migration `005_academy_fees`. The
> migration only adds tables and columns and is safe for existing data. Everything stays dormant until it is configured.

## What existed before (gateways)

Nothing. Finance had **no** gateway configuration or code on `main` or on any branch (`claude/rectify-2026-10`,
`claude/invoice-line-discounts`, `claude/aps-finance-connected-app-rrtope`, `claude/stoic-faraday-3w841k`,
`claude/youthful-babbage-sn5b1g`, `claude/optimistic-knuth-u20tyq`, `claude/finance-auto-login-dashboard-8vf3v3`,
`claude/finance-sso-login-issue-igk6ih`):

- The `settings` table holds payout settings only (`001_payouts.sql`).
- `.env.example` has no provider variables.
- The only mentions of `razorpay` were test fixtures for `InvoiceService::issueForPayment`.
- `docs/INVOICING.md` listed gateways as Phase B.
- `InvoiceService::issueForPayment` and `PaymentInvoiceSource` existed, but nothing called them.

"Configured but pending connection" can therefore only describe the merchant accounts in the providers' dashboards.
Finance connects to them once a finance admin enters the keys in Settings → Payment gateways.

## Architecture

```
academy.aicountly.org          engage.aicountly.org               finance.aicountly.org                 Razorpay / Cashfree
(fee + GST per course)         (lead, sales bot, commission)      (gateways, invoices)
        │ fee due                       │                                  │                                   │
        ├──────────────────────────────▶│ "Collect fee" on the lead        │                                   │
        │                               ├─ POST /v1/inbound/engage/ ──────▶│ check GST split, store link        │
        │                               │  payment-links (AIC-S2S)         ├─ create payment link ─────────────▶│
        │                               │◀──── 201 {link_id, url} ─────────┤◀──────────── {id, url} ───────────┤
        │                               │ sends the URL (WhatsApp / mail)  │                                   │
        │                               │                    student pays on the provider's page ────────────▶│
        │                               │                                  │◀── webhook (signed) ──────────────┤
        │                               │                                  ├─ re-read link / payment ─────────▶│
        │                               │                                  │  verified → link paid,             │
        │                               │                                  │  GST invoice (B2C), outbox event   │
        │                               │◀─ payment.succeeded (AIC-S2S) ───┤  (bin/academy-fees-cron.php)       │
        │                               │ confirm lead payment (commission)│                                   │
        │◀──── fee.paid (Engage → Academy, Engage's AcademyFulfilmentOutbox; not part of Finance) ─────────────│
```

Principles:

- **Finance never trusts a webhook body alone.** A signed webhook only tells Finance that something happened.
  Finance then re-reads the link and the payment from the provider's own API. Only a payment the provider confirms
  there is `verified`, invoiced and reported to Engage. If the provider cannot be reached, the payment waits as
  `pending` and the cron retries.
- **One fee is asked for once.** A fee account (`academy_ref`) can have one open link and one paid link at a time
  (unique indexes). Engage requests are idempotent through `Idempotency-Key`.
- **The GST split is agreed before the student pays.** Finance recomputes the split that Academy / Engage send,
  using its invoice rules. If the split differs, Finance refuses the link, so every paid link can be invoiced for
  exactly the amount paid.
- **Append-only and audited.** The payer, amount and fee of a link never change (trigger), and neither does what
  the provider reported. Every create, approve, reject, cancel, verify and re-queue is written to `audit_log`.

## Data model (migration `005_academy_fees`)

| Table | Holds |
| --- | --- |
| `fin_gateway_connections` | One row per provider (`razorpay` / `cashfree`) and mode (`test` / `live`). Holds the key id (Razorpay `rzp_…` / Cashfree App ID), the API secret and the optional webhook secret (both DataVault ciphertext), `enabled`, `is_default` (one at most) and the last test-connection verdict. |
| `fin_payment_links` | Public id `fin_pl_<ULID>` and `source_app` (`engage` or `finance`), with `idempotency_key` + `request_sha256` (Engage). Refs: `source_ref` (the Engage lead code), `academy_ref` and `stream` (`edge` / `aspire`). Also `product_code` and `seller_code`. The payer snapshot is name, email, mobile (E.164), state, address and PIN. `amount_paise` is what the student pays. The fee snapshot covers taxable value, CGST / SGST / IGST, SAC, rate and basis, plus `fee_breakdown` exactly as sent. Also seller state, place of supply, supply type, provider / connection / mode and expiry. Status: `pending_approval → creating → created → paid` (`partially_paid`, `expired`, `cancelled`, `failed`, `rejected`), with the provider link id / URL. Maker / checker / cancel columns. |
| `fin_gateway_payments` | `payment_ref` `fin_pay_<ULID>`. `provider_payment_id` is Finance's key for the payment (Razorpay `pay_…`, Cashfree `order_id`), unique per provider. `gateway_payment_ref` is the id the dashboard shows (Razorpay `pay_…`, Cashfree `cf_payment_id`), read from the provider API at verification. Also the link, amount, method, `paid_at`, `verification_status` `pending → verified \| mismatch`, `refunded_paise` and `invoice_state` (`not_issued \| issued \| blocked_config \| blocked_data`). `raw_event` is the first event, scrubbed. This is what `fin_invoices.payment_id` refers to (see the note on the foreign key below). |
| `fin_gateway_refunds` | Refunds made in the provider dashboard: provider refund id (unique), payment, amount, verification. |
| `fin_gateway_webhook_events` | One row per signed delivery. `fingerprint` (the provider event id, else the sha256 of the body) is **unique**, which makes the webhooks replay-safe. Each row also holds `outcome` (`processed`, `ignored`, `rejected`, `error`), a detail, the delivery count and the payload, scrubbed. Deliveries with a bad signature are logged without payload and with no fingerprint, capped at 200 per hour. |
| `fin_engage_payment_events` | The outbox to Engage: `event_id` `fin_evt_<ULID>`, type, payload (JSON, signed byte for byte), status, attempts and back-off. Events of one link are delivered in order. Each payment produces at most one `payment.succeeded`, and each refund at most one `payment.refunded`. |
| `fin_invoices` / `fin_credit_notes` + `buyer_kind` | `company` is the default: every existing row keeps `cmp_id` required. `academy_student` rows must have `cmp_id IS NULL`, no GSTIN and `source = 'payment'` (CHECK constraints). Manage never sees them because its reads filter by `cmp_id`. The credit-note trigger now matches a student invoice's buyer. |

Money is integer paise throughout. Personal and instrument data (email, phone, VPA, card, bank, wallet) is removed
from every stored provider payload by `App\Services\AcademyFees\EventScrubber`.

*Foreign key note:* `fin_invoices.payment_id` still has no foreign key to `fin_gateway_payments`. The Phase A
invoicing tests and the numbering concurrency test issue invoices against synthetic payment ids. Integrity comes from
`UNIQUE source_ref = 'payment:<id>'` and from `GatewayPaymentInvoiceSource`, which reads only this table. A later
migration can add the FK once those fixtures create real rows. Any future SaaS checkout should record its gateway
payments in `fin_gateway_payments` too, so the `payment:<id>` namespace stays single.

## Engage → Finance (AIC-S2S v1)

Same signature scheme as the Manage read API ([INVOICING.md § Authentication](INVOICING.md#authentication)):
`X-AIC-Client: engage`, an **issue**-scope key, PATH after `/api`. Rate limit: 30 requests per minute per key
(`FINANCE_S2S_ISSUE_PER_MINUTE`). No CORS. A staff JWT is not accepted. Engage signs with its
`App\Libraries\AicS2s\AicS2sSignature::headers('engage', $keyId, $secret, $env, …)`.

| Route | Answer |
| --- | --- |
| `POST /api/v1/inbound/engage/payment-links` (+ `Idempotency-Key`) | `201` the new link, created at the provider. `200` + `duplicate: true` for the same request again. |
| `GET /api/v1/inbound/engage/payment-links/{link_id}` | The link, its payments (`payment_ref` = the raw gateway id) and their invoices. Links staff raised in Finance answer `404`. |
| `POST /api/v1/inbound/engage/payment-links/{link_id}/cancel` `{reason?}` | Cancelled at the provider too. Repeating it answers `duplicate: true`. A paid link answers `409 already_paid`. |

Request:

```json
POST /api/v1/inbound/engage/payment-links
Idempotency-Key: lead-LD-2026-000123-fee-1          (8–100 of A-Z a-z 0-9 _ . : -; one per fee demand)

{
  "source_ref":   "LD-2026-000123",                    // Engage lead code (required) — comes back as lead_code
  "academy_ref":  "EDGE-ADM-0042-F1",                  // Academy admission / fee-account ref (one open + one paid link each)
  "stream":       "edge",                              // edge | aspire
  "product_code": "edge",                              // Engage product code (default = stream)
  "seller_code":  "SLR-000123",                        // optional, echoed in events
  "description":  "Aicountly Edge — Accounting & GST programme, Oct 2026 batch: fee instalment 1 of 2",   // ≤ 300, printed on the invoice
  "payer": {"name": "Ravi Kumar", "email": "ravi@example.com", "mobile": "+919876543210",
            "state_code": "27", "address": "…", "pincode": "411001"},   // address + PIN required from ₹50,000 taxable (Rule 46(e))
  "amount_paise": 1180000,                             // what the student pays
  "fee": {"taxable_paise": 1000000, "cgst_paise": 90000, "sgst_paise": 90000, "igst_paise": 0,
          "sac": "999293", "gst_rate": "18", "price_basis": "inclusive"},
  "expire_at": "2026-11-15T23:59:59+05:30",            // optional: 15 min … 180 days; default 15 days
  "provider": "razorpay",                              // optional: razorpay | cashfree; default = the default connection
  "notify": {"sms": false, "email": false}             // optional: let the provider send the link too
}
```

Finance's GST rules, which the `fee` must match: place of supply = `payer.state_code`. The supply is intra-state when
that equals the seller's state (the seller profile's GSTIN, e.g. 27 Maharashtra), and then CGST + SGST apply, with
SGST = floor(tax / 2) and CGST carrying the odd paisa. Otherwise it is inter-state and IGST applies.
`inclusive`: tax = round_half_up(amount × R / (100 + R)). `exclusive`: tax = round_half_up(taxable × R / 100), and
amount = taxable + tax. A difference answers `422 fee_breakdown_mismatch`, with `details.expected` carrying Finance's
figures.

Answer (`201`, AIC envelope `{"success":true,"data":…,"request_id":…}`):

```json
{"link_id": "fin_pl_01JB…", "status": "created", "provider": "razorpay", "mode": "live", "url": "https://rzp.io/i/…",
 "amount_paise": 1180000, "amount_paid_paise": 0, "currency": "INR", "expire_at": "…", "source_ref": "LD-2026-000123",
 "academy_ref": "EDGE-ADM-0042-F1", "stream": "edge", "product_code": "edge", "seller_code": "SLR-000123",
 "fee": {…}, "created_at": "…", "paid_at": null, "cancelled_at": null, "last_error": null, "payments": []}
```

Errors (`{"success":false,"error":{"code","message","details","retryable"}}`): `400 idempotency_key_required` /
`bad_request`. `401` / `403` / `429`: the AIC-S2S refusals. `409 idempotency_conflict` (same key, different body),
`open_link_exists` / `already_paid` (`details.link_id`). `422 validation_failed` (`details` per field),
`fee_breakdown_mismatch`, `seller_not_configured`. `502 provider_error`: the provider refused or did not answer; the
link is stored as `failed`, and sending **the same request again** retries without ever creating two links (Finance
first looks the link up at the provider by its id). `503 gateway_not_configured` / `inbound_not_configured`.

## Finance → Engage: payment events

`bin/academy-fees-cron.php` sends events every 5 minutes from the outbox. Each event is written in the same
transaction that verifies the payment or refund.

```
POST {ENGAGE_API_BASE_URL}/v1/inbound/finance/payment-events          (production: https://engage.aicountly.org/api/…)
X-AIC-Client: finance   X-AIC-Key-Id: {FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID}   X-AIC-Environment: {FINANCE_S2S_ENVIRONMENT}
X-AIC-Timestamp / X-AIC-Nonce / X-AIC-Signature: v1=HMAC-SHA256({FINANCE_OUTBOUND_ENGAGE_S2S_SECRET}, canonical)
Idempotency-Key: {event_id}
```

The AIC-S2S scheme is reused rather than the payouts' `X-Finance-Key` token: it signs the body, has replay
protection, and Engage's `AicS2sFilter` already serves any client by argument. Engage adds
`['filter' => 'aic-s2s:finance']` with `ENGAGE_INBOUND_FINANCE_KEY_IDS` / `ENGAGE_INBOUND_FINANCE_SECRET_<KEYID>` /
`ENGAGE_INBOUND_FINANCE_SCOPE_<KEYID>=issue`. These names do not collide with the payouts' `ENGAGE_INBOUND_FINANCE_KEY`.

```json
{
  "event_id":     "fin_evt_01JB…",              // stable across retries (also the Idempotency-Key)
  "event_type":   "payment.succeeded",          // | payment.refunded
  "occurred_at":  "2026-10-26T10:15:00+05:30",
  "lead_code":    "LD-2026-000123",             // the source_ref Engage sent when it asked for the link
  "payment_ref":  "pay_N3xY…",                  // the RAW gateway payment id, exactly as staff type it — Razorpay pay_… (never plink_…);
                                                //   Cashfree the bare cf_payment_id as a string, e.g. "5114910393"
  "provider":     "razorpay",                   // razorpay | cashfree
  "product_code": "edge",                       // edge | aspire
  "amount":       "11800.00",                   // INR, two decimals, no grouping
  "amount_paise": 1180000,
  "currency":     "INR",
  "paid_at":      "2026-10-26T10:14:52+05:30",  // ISO 8601
  "method":       "upi",
  "mode":         "live",
  "stream":       "edge",
  "academy_ref":  "EDGE-ADM-0042-F1",
  "seller_code":  "SLR-000123",
  "link_id":      "fin_pl_01JB…",
  "finance_payment_id": "fin_pay_01JB…",
  "fee":    {"taxable_paise": 1000000, "cgst_paise": 90000, "sgst_paise": 90000, "igst_paise": 0, "sac": "999293", "gst_rate": "18.00", "price_basis": "inclusive"},
  "refund": null                                // payment.refunded: {provider_refund_id, amount, amount_paise, refunded_at, total_refunded_paise, fully_refunded}
}
```

Engage de-duplicates on `(lead, payment_ref)`. Because `payment_ref` is the dashboard id, a payment that staff
already confirmed by hand on launch day is not recorded twice. Engage maps the event to
`POST v1/leads/{id}/payments {amount, payment_ref, product_code, paid_at}`, which accrues the seller commission, and
then sends its own `fee.paid` to Academy. No payer data is sent, because Engage already has the lead.

Delivered only on 2xx **with Engage's receipt**: `{"success":true,"data":{"event_id":"<same>"}}`, or
`{"ok":true,"data":{"event_id"}}`. A 2xx without it (e.g. a base URL without `/api` hitting the SPA) counts as a failure.
`400 / 404 / 409 / 422` mean Engage refused the event, which goes `dead` at once; fix the cause, then a finance admin
re-queues it. Anything else is retried after 2, 4, 8 … minutes (at most 6 h), and the event goes `dead` after 12
attempts. Links without a lead code (staff links raised without one) produce no events.

## Webhooks

| Provider | URL (register in the dashboard) | Signature | Events Finance uses |
| --- | --- | --- | --- |
| Razorpay | `https://finance.aicountly.org/api/v1/webhooks/razorpay` | `X-Razorpay-Signature` = hex HMAC-SHA256(raw body, **webhook secret**); `X-Razorpay-Event-Id` for replay safety | `payment_link.paid`, `payment_link.partially_paid`, `payment_link.expired`, `payment_link.cancelled`, `refund.processed` (`payment.captured` only if it carries `notes.fin_link_id`) |
| Cashfree | `https://finance.aicountly.org/api/v1/webhooks/cashfree` (Finance also sends it as each link's `link_meta.notify_url`) | `x-webhook-signature` = base64(HMAC-SHA256(`x-webhook-timestamp` + raw body, **client secret**), or the saved webhook secret) | `PAYMENT_LINK_EVENT`; `PAYMENT_SUCCESS_WEBHOOK` (only with `order_tags.link_id`); `REFUND_STATUS_WEBHOOK` |

Processing (`App\Services\AcademyFees\WebhookService`):

1. The signature is checked against each stored secret of that provider, test and live.
   - None matches: `401`, logged as rejected.
   - No secret is saved: `503`.
2. The delivery is stored once per fingerprint. A replayed delivery answers `200 {"duplicate":true}` and changes
   nothing.
3. The event is applied to the link it names (our `fin_pl_…` id, or the provider's link id):
   - Payment: recorded as pending, then confirmed by re-reading the provider.
     - Razorpay: `GET /v1/payment_links/{id}` lists the payment as captured. When that answer omits the payments,
       Finance reads `GET /v1/payments/{id}` and ties the payment to the link's order.
     - Cashfree: `GET /pg/links/{id}/orders` shows the order `PAID`, then `GET /pg/orders/{order_id}/payments`
       gives the `cf_payment_id`.
   - Then, in one transaction: payment verified → link paid → `payment.succeeded` queued.
   - The invoice is issued right after the commit.
   - Links Finance did not create (e.g. made by hand in a dashboard) are logged as `ignored`.
4. `200` once the delivery is stored. `500` only when Finance itself failed, so that the provider retries.

The re-read uses a 4-second timeout. If it fails, the payment stays `pending` and the cron finishes it. A payment the
provider still does not confirm after 48 attempts becomes `mismatch`, and staff look at it. A second payment on a link
that is already paid is verified, because the money did arrive, but it is **never invoiced or sent to Engage**. It is
shown as "Duplicate payment … refund it".

## GST invoice for a student (B2C)

`GatewayPaymentInvoiceSource` feeds `InvoiceService::issueForPayment()`:

- **Buyer:** `buyer_kind = academy_student`, `cmp_id = NULL`, no GSTIN, with the student's name, state, email and the
  address / PIN when given. Below ₹50,000 taxable, an empty address prints as "<State>, India" (Rule 46(f)); from
  ₹50,000 the link already required them.
- **Place of supply and tax:** place of supply is the student's state. Intra-state supplies carry CGST + SGST and
  inter-state supplies carry IGST, against the seller profile's state.
- **Line:** one line, `item_code` = product code, `item_kind = academy_fee`, the link's description, SAC, rate and
  basis. The amount equals the amount paid, or the payment shows `blocked_data`.
- **Payment block:** "Online payment" via Razorpay / Cashfree, with the provider payment id as the reference.
- **Numbering and PDF:** the same gapless series and the same PDF as subscription invoices. GSTR-1 lists the invoice
  as B2CS, or B2CL above ₹1,00,000 inter-state.
- **Blocked invoices:** if the seller profile or the series is missing, the payment waits as `blocked_config` and the
  cron issues it once they exist. Staff can also press **Retry invoice** (approve gate).
- **Credit notes:** for refunds they are raised by staff on the invoice, using the existing four-eyes flow. Student
  credit notes carry no `cmp_id` either.

## Staff screens (Finance roles)

| Screen | Gate | What |
| --- | --- | --- |
| Academy fees → Payment links | view | List (awaiting approval first) and filters. The detail shows the shareable URL, payments, invoices, webhook deliveries and Engage callbacks. |
| Raise payment link (maker) | edit (accountant, approver, admin) | Course, Academy ref, optional Engage lead code, description, amount + basis + SAC + rate, student (name, mobile, email, state, address / PIN), gateway and expiry. **Compute GST** previews the split. Saved as *awaiting approval*. |
| Approve / reject | approve (approver, admin) | **Four-eyes:** the maker cannot approve their own link (a database constraint as well). Approving creates the link at the provider. |
| Cancel · Retry (failed create) | approve | Cancels at the provider too. |
| Re-check with provider | edit | Re-reads the link and its payments (the safety net for missed webhooks). |
| Payments | view (retry invoice: approve) | Verification state, invoice, refunds. |
| Webhook log | view | Outcome, detail, delivery count, scrubbed payload. |
| Engage callbacks | view (re-queue dead: settings = finance_admin) | |
| Settings → Payment gateways | settings (finance_admin) | Add / edit (secrets write-only: "set (…last4)"), **Test connection** (a read-only authenticated call: Razorpay `GET /v1/payments?count=1`, Cashfree `GET /pg/links/<unknown>`, where 404 = credentials OK), enable / disable, default. The screen also shows the webhook URLs to register. |
| Invoices | view | New *Buyer* filter: Aicountly Academy students. |

## Environment variables (server-php `.env`)

| Variable | Purpose |
| --- | --- |
| `FINANCE_INBOUND_ENGAGE_SECRET_<KEYID>` | AIC-S2S secret Engage signs payment-link requests with (≥ 32 bytes). |
| `FINANCE_INBOUND_ENGAGE_SCOPE_<KEYID>` | Must be `issue`. |
| `FINANCE_OUTBOUND_ENGAGE_S2S_KEY_ID` / `FINANCE_OUTBOUND_ENGAGE_S2S_SECRET` | The key id and secret (≥ 32 bytes) Finance signs payment events to Engage with (client `finance`). |
| `ENGAGE_API_BASE_URL` | Existing (payouts): `https://engage.aicountly.org/api`, with `/api`. |
| `FINANCE_S2S_ENVIRONMENT` | Existing: `production` / `sandbox` / `local`; sent as `X-AIC-Environment`, so it must equal Engage's `ENGAGE_S2S_ENVIRONMENT`. |
| `BASE_URL` | Existing: the public API URL (`https://finance.aicountly.org/api`). Used for Cashfree's `notify_url` and for the URLs shown in Settings. |
| `FINANCE_DATA_KEY` | Existing: encrypts the gateway secrets. Losing it means re-entering the keys. |
| `FINANCE_ACADEMY_RETURN_URL` | Optional: where the student lands after paying (https). |
| `FINANCE_CASHFREE_API_VERSION` | Optional: default `2023-08-01`. |
| `FINANCE_RAZORPAY_API_BASE` / `FINANCE_CASHFREE_API_BASE` | **Tests only.** Leave them unset everywhere else. |

The Razorpay / Cashfree keys are **not** environment variables. They are entered in Settings → Payment gateways and
stored encrypted, the same practice as the company bank account numbers. No API answer, audit row or log line
contains them.

## Provider dashboard setup

**Razorpay** (Dashboard → Account & Settings):

1. API Keys: generate a key in Test mode, and later in Live mode. Paste the Key Id + Key Secret into Settings →
   Payment gateways (mode `test` / `live`).
2. Webhooks: add `https://finance.aicountly.org/api/v1/webhooks/razorpay` with a strong secret, and paste the same
   secret as the webhook secret.
3. Subscribe to `payment_link.paid`, `payment_link.partially_paid`, `payment_link.expired`, `payment_link.cancelled`
   and `refund.processed`. `payment.captured` is optional, and is ignored unless it is for a Finance link.
4. Payment Links must be enabled on the account. Keep "partial payments" off: Finance always sends `accept_partial: false`.

**Cashfree** (Merchant Dashboard → Developers):

1. API Keys: App ID + Secret Key for Sandbox, and later for Production. Paste them into Settings (mode `test` / `live`).
2. Webhooks: add `https://finance.aicountly.org/api/v1/webhooks/cashfree` for **Payment Links** events, and for
   Payment Gateway success / refund events if you want those too. Finance also sets it per link as
   `link_meta.notify_url`.
3. Webhook version: select the version that matches `x-api-version` 2023-08-01 or later.
4. Payment Links must be enabled for the account.

## Health, cron, runbook

`GET /api/health` → `academy_fees` (booleans only; never changes the top-level status):
`status` dormant / incomplete / ready, with these `checks`:

- `schema`, `gateway_connection`, `webhook_secret`, `live_mode`, `engage_inbound`, `engage_events`;
- alerts: `verification_pending` (older than 15 min, or a mismatch), `invoice_blocked`, `engage_events_dead`.

Cron (every 5 minutes; safe before configuration):

```cron
*/5 * * * * php /home/<user>/public_html/api/bin/academy-fees-cron.php >> /home/<user>/logs/finance-academy-fees.log 2>&1
```

Each run re-verifies pending payments and refunds, issues waiting invoices, resumes link creations interrupted
mid-way (looking the link up at the provider first), re-reads open links past their expiry, and delivers Engage events.

The runbook:

- **`verification_pending`:** open Academy fees → Payments → *Pending / Mismatch*. Press **Re-check with provider** on
  the link. A mismatch (amount differs, or the provider does not show the payment) needs a look in the provider
  dashboard.
- **`invoice_blocked`:** if the cause is setup, fix it in Settings → Invoicing and the cron issues the invoice. A
  *Duplicate payment* row means: refund it in the dashboard; it is never invoiced.
- **`engage_events_dead`:** the last error says why (e.g. an unknown lead in Engage). Fix it in Engage, then a finance
  admin presses **Retry**.
- **Refund made in a dashboard:** Finance records it from the webhook and sends `payment.refunded`. Raise the credit
  note on the invoice (Invoices → the invoice → Request credit note); someone else approves it.
- **Rotating keys:** edit the connection with the new secret (a blank field keeps the old one), then **Test
  connection**. For Razorpay webhooks, change the secret in the dashboard and in Finance together.

## Go-live checklist

1. Owner review of this branch; the CA confirms the [open questions](#open-questions) (SAC, rate, B2C invoice content).
2. Verify every item in [Provider details to verify](#provider-details-to-verify) against the provider docs, using
   Test mode end to end.
3. Deploy (migration 005 is applied). Invoicing must be active: a seller profile and the FY's series.
4. Engage: implement the "Collect fee" call, `POST v1/inbound/finance/payment-events` behind `aic-s2s:finance`
   (receipt `{success:true, data:{event_id}}`), and agree both key pairs. Set the env variables above on both sides,
   with the same environment value.
5. Settings → Payment gateways: add the **test** connections, test them, enable them, and register the webhooks.
6. Run a test-mode sale for each provider and each course:
   1. Engage creates the link.
   2. Pay with the provider's test instruments.
   3. Check: link paid, payment verified, invoice issued (intra- and inter-state), Engage lead payment confirmed
      exactly once, Academy fee marked paid.
   4. Refund one payment and raise its credit note.
7. Replace the test connections with **live** ones, make the live one the default, and disable the test ones.
8. Add the cron line. Health `academy_fees.status` should be `ready`.
9. First live sale: watch the webhook log and the Engage callbacks.

## Provider details to verify

The adapters (`app/Services/AcademyFees/RazorpayAdapter.php`, `CashfreeAdapter.php`) are isolated and tested against
fixtures in the shapes below. **The fixtures and the stub server prove Finance's side, not the providers' real
behaviour.** Check these against the current provider documentation before go-live:

**Razorpay**

- `POST /v1/payment_links`: field names, and that `reference_id` (≤ 40 characters; ours are 33) is unique per
  account, so a duplicate is refused. Also the `expire_by` minimum (we enforce ≥ 15 minutes and ≤ 180 days) and the
  maximum number of `notes` (we send ≤ 4).
- `GET /v1/payment_links?reference_id=` returns `{payment_links:[…]}`. The adapter also accepts `items`.
- `GET /v1/payment_links/{id}` includes `payments: [{payment_id, amount, status, method, created_at}]` once paid.
  If it does not, the adapter falls back to `GET /v1/payments/{id}`, tied by `order_id` or `notes.fin_link_id`.
  **Check whether link notes are copied to the payment**, and whether the link exposes `order_id`.
- Webhook payload paths `payload.payment_link.entity` / `payload.payment.entity` / `payload.refund.entity`, the event
  names above, and the `X-Razorpay-Event-Id` header.
- The `GET /v1/payments?count=1` auth check (and its 401 on bad keys).
- `GET /v1/refunds/{id}` (vs. `GET /v1/payments/{pay}/refunds/{rfnd}`).

**Cashfree**

- `POST /pg/links`:
  - `link_amount` is in **rupees**, and Finance sends a number with two decimals.
  - `customer_phone` takes 10 digits.
  - `link_expiry_time` is ISO 8601 with offset.
  - `link_meta.notify_url` must be https.
  - The `link_notes` limits.
  - Which `x-api-version` values are current (default `2023-08-01`).
- Link statuses: `ACTIVE`, `PAID`, `PARTIALLY_PAID`, `EXPIRED`, `CANCELLED`. Older docs also show `TERMINATED` /
  `TERMINATION_REQUESTED`, and the adapter maps those too. Also check the shape of the `POST /pg/links/{id}/cancel`
  answer.
- That `GET /pg/links/{id}/orders` returns an array of `{order_id, order_amount, order_status}`, and that
  `GET /pg/orders/{order_id}/payments` returns `{cf_payment_id, payment_status, payment_amount, payment_time,
  payment_group}`. **`cf_payment_id` is what the dashboard shows as the payment id**; please confirm this.
- Webhook signature:
  - Is it the **client secret**, or a separate webhook secret in your account? Finance supports both.
  - Is `x-webhook-timestamp` in milliseconds?
  - Do retries resend the same body? Deduplication relies on the body hash. Finance does **not** reject old
    timestamps (processing is idempotent and verified by re-reading), so check whether you want a window.
- `PAYMENT_LINK_EVENT` payload: `data.link_id`, `data.link_status`, `data.order.{order_id, transaction_status}`.
  Also whether `PAYMENT_SUCCESS_WEBHOOK` carries `order_tags.link_id` for link payments.
- Whether the unknown-link `GET /pg/links/{id}` answers 404 with valid credentials and 401 with invalid ones (the
  test-connection logic).
- Refund lookup `GET /pg/orders/{order_id}/refunds/{refund_id}`, for refunds made in the dashboard (`refund_id` vs
  `cf_refund_id`).

## Open questions

1. **SAC and GST rate** of Edge / Aspire fees (the tests use 999293 "commercial training and coaching", 18 %).
   Is any course exempt (e.g. an approved vocational course), and inclusive or exclusive pricing? Academy defines this
   and Finance only checks the arithmetic. Confirm with the CA.
2. **B2C invoice content**: is "<State>, India" acceptable as the address below ₹50,000 (Rule 46(f))? Should
   Academy always send the full address?
3. **TDS**: students do not deduct TDS. If a college or company pays for a batch (B2B, with a GSTIN and possibly TDS
   under 194J), this flow does not fit: that is a `company` buyer through the existing invoicing, or Phase 2.
4. **Refunds**: Phase 2. Finance only *records* refunds made in the provider dashboard and tells Engage; it does not
   initiate them, and the credit note is manual (four-eyes). Initiating refunds and creating the credit note
   automatically are Phase 2.
5. **Instalments / partial payments**: one link per instalment (`academy_ref` per instalment), always
   `accept_partial: false`.
6. **Gateway charges / settlements**: provider fees and settlement reconciliation are not recorded (Phase 2: a
   settlement import against the bank statement).
7. **Four-eyes on gateway credentials**: a single finance admin can change a connection (as with company bank
   accounts). Owner decision: should a second admin approve a key change, given that wrong keys could send fees to
   another account?
8. **Launch-day payments** (dashboard links) cannot be invoiced through the existing *Offline payments* screen,
   because it still requires a `cmp_id`. Options: extend offline payments to `academy_student` (small change), or
   issue those invoices by hand outside Finance. Decide before the first GSTR-1 that includes them.

## Launch day (manual links)

Until this branch is live:

1. Staff create each link in the Razorpay / Cashfree dashboard, with reference = the Academy admission / fee ref.
2. When it is paid, Engage staff confirm it on the lead (`POST v1/leads/{id}/payments`), with `payment_ref` = the
   **dashboard payment id** (Razorpay `pay_…`; Cashfree the bare `cf_payment_id`). This is the same value Finance's
   events will carry later, so nothing is recorded twice once automation starts.
3. Academy staff mark the fee paid in Edge / Aspire.

Finance ignores webhooks for these dashboard links if they arrive, logging them as `ignored`, because Finance did
not create the links.

## Tests

`php server-php/tests/run.php`:

- `tests/academy-unit.php`: adapters against request / response fixtures; webhook signatures with known-answer
  vectors computed by `openssl`; event parsing; scrubbing; the GST split and payer rules; the Engage event body.
- `tests/academy-db.php`: PostgreSQL + the API under `php -S`, with stub Razorpay / Cashfree (`tests/fake-gateway.php`),
  stub Engage and stub Gotenberg. It covers:
  - dormant mode and connection settings (secrets never in an answer, a row or the audit log);
  - Engage S2S auth, idempotency and conflicts, and provider failure + retry without duplicates;
  - webhooks: bad signature, tampered body, replay;
  - verification by re-read, pending → cron;
  - B2C invoices (intra and inter-state) and their immutability;
  - the Engage outbox (signature verifiable with the shared secret, back-off, dead, re-queue);
  - maker-checker, cancel, refunds and credit notes, duplicate payments, and health.
