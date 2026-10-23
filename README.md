# finance-aicountly

Finance for Aicountly — a React single-page app (Vite, TypeScript, react-router)
and a plain-PHP API (**server-php**, PDO PostgreSQL) on the live cPanel portal.
Its first module is **weekly seller payouts**: Engage sends each week's payout
run as a draft; Finance staff review it, approve it (four-eyes), produce the bank
bulk-upload file, and record the bank's UTRs. Finance never moves money itself.
The second is **subscription invoicing** (dormant until configured): GST tax
invoices and credit notes for AICOUNTLY subscriptions, read by Manage server to
server — see [docs/INVOICING.md](docs/INVOICING.md).
The third is **Aicountly Academy fees** (dormant until configured): payment links for
Aicountly Edge / Aicountly Aspire fees on Aicountly's own Razorpay / Cashfree accounts,
requested by Engage server to server or raised by staff, verified with the provider,
invoiced to the student (B2C) and reported back to Engage — see
[docs/ACADEMY_PAYMENTS.md](docs/ACADEMY_PAYMENTS.md).

| Environment | URL |
| --- | --- |
| Production | https://finance.aicountly.org |

## Getting started

Requires Node.js 22 or newer.

This repository holds both halves of the app:

```
web/          React app (Vite). Builds to web/dist, deployed to the document root.
server-php/   PHP API. Deployed to the api/ folder inside the document root.
docs/         deployment and configuration notes
```

```bash
cd web
npm install
cp ../.env.example ../.env   # then set VITE_API_BASE_URL
npm run dev
```

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for how the API's `.env` works —
it behaves differently from the React variables.

## Sign-in (Console SSO)

Finance has no login form of its own. `console.aicountly.org` is the identity
provider for all Top Controller Apps. There are two ways in, and both end on
the Finance Dashboard:

- **From Console's App Launcher.** Console redirects to `server-php`'s
  `/api/v1/auth/sso-callback?token=…`, which exchanges the one-time key for a
  Finance JWT and hands the browser back to the SPA already signed in.
- **Straight to `finance.aicountly.org`.** The SPA asks its own API to trade
  the shared `.aicountly.org` Console cookie for a Finance JWT
  (`POST /api/v1/auth/console-session`). If there is no Console session, the
  browser is sent to Console's login form with `?return=` pointing back here,
  so finishing the login lands on the Finance Dashboard.

Anything else stops on an explanatory prompt rather than redirecting: an
account without a Finance grant would otherwise bounce between the two
domains forever, since Console considers it signed in.

See `console-react-app`'s `AUTH_INTEGRATION.md` for the full contract and
`server-php/.env.example` for the required `CONSOLE_API_URL` /
`CONTROLLER_APP_CODE` / `CONSOLE_API_TOKEN` / `JWT_SECRET` settings.

### Roles (assigned in Console)

A Console grant alone does not let anyone in: a Console superadmin assigns
Finance roles per user (app_code `finance`):

| Role | Label | Can |
| --- | --- | --- |
| `finance_admin` | Finance Administrator | everything, plus Settings and re-queueing dead Engage callbacks |
| `payout_approver` | Payout Approver | approve / cancel batches, generate + download bank files, record UTRs, failures, reversals |
| `accountant` | Accountant | review drafts: hold / release, adjust, recalculate, submit for review; export the TDS register (full PAN) |
| `finance_viewer` | Finance Viewer | read-only: dashboard, batches, payees (masked), TDS register, audit log |

Each API route carries a gate (`view` / `edit` / `approve` / `settings`,
`FinanceAccessPolicy::GATES`, checked after the JWT); the test suite fails if an
authenticated route has none.

- **Sign-in** (SSO callback or console-session): Console's `app_roles` must be
  non-empty, or the user must be `app_superadmin` (who holds both roles).
  Otherwise sign-in stops with *"Access not assigned. Ask a Console superadmin
  to assign you a role."* (`sso_error=role_not_assigned` /
  `reason: role_not_assigned`). A Console that predates per-app roles (no
  `app_roles` in its answer) admits only a Console global superadmin.
- **The Finance JWT** keeps its existing claims (`sub`, `email`, `name`,
  `role` = Console role, `gsa`) and adds `roles` (Finance roles),
  `app_superadmin`, `console_user_id` and `authz_version`.
  `GET /api/v1/auth/me` returns `roles`.
- **Every authenticated request** re-checks the roles with Console's
  user-access endpoint (service token `CONSOLE_API_TOKEN`, 60 s cache):
  revoked → 403 with the message above; changed → the request runs with the
  new roles. If Console cannot be reached the token's roles are used only
  while the token is less than 12 h old (else 503). If `CONSOLE_API_TOKEN` is
  not set, the token's roles are trusted and a warning is logged —
  **production must set it.** Tokens issued before roles existed (no `roles`
  claim) get a 401 and the SPA silently signs in again.
- **Web:** `useFinanceAccess()` (`web/src/lib/financeRoles.ts`) gives any
  screen `roles`, `hasRole()`, `can(gate)` and `isAdmin`. Screens show only the
  actions the server would accept (the batch detail also returns a `can` map,
  including the four-eyes verdict for the current user).

## Weekly seller payouts

| Screen | What it does |
| --- | --- |
| Dashboard | awaiting review / payment, on hold, paid this FY (net, TDS, GST), Engage callback health, open batches |
| Payouts | batches → items: hold / release with reason, adjust with reason, recalculate, submit, approve, cancel, generate bank file from a chosen company bank account (default preselected; generic NEFT/IMPS CSV or ICICI/HDFC-style — confirm with your bank), record UTRs one by one or from the bank's response CSV, mark failed / reversed, release held items back to Engage |
| Payees | synced from Engage batches; PAN, GSTIN, bank account masked; FY totals; payout history; "bank changed" flags |
| TDS register | section 194H per quarter — deductee PAN, name, code, amount, rate, TDS, date, UTR (Form 26Q data); CSV export with full PAN (audited) |
| Settings | TDS rate / threshold, GST mode / rate, minimum payout, four-eyes, default bank file format, payment mode; **Bank accounts** — the company accounts payouts are debited from (add / edit / default / deactivate, finance admins; numbers masked for everyone); Engage callback outbox |
| Audit log | every change: who, what, when, before, after (append-only) |

Contract with Engage (SELLER-CONTRACTS "Weekly payouts"):
`POST /api/v1/inbound/engage/payout-batches` (`X-Engage-Key` =
`FINANCE_INBOUND_ENGAGE_KEY`) → `201 {batch_id, status:"received"}`; per-item
status back to `POST {ENGAGE_API_BASE_URL}/v1/inbound/finance/payout-status`
(`X-Finance-Key` = `ENGAGE_FINANCE_KEY`) through the outbox and
`bin/outbox-deliver.php`. Database, cron, env and the tax assumptions to confirm
with your CA: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md#weekly-seller-payouts).

## Aicountly Academy fees

| Screen | What it does |
| --- | --- |
| Academy fees | payment links (from Engage or raised in Finance), payments with their verification and invoice, webhook log, Engage callbacks (re-queue dead ones: finance admins) |
| Raise payment link | maker (accountant and up) enters the fee, the student and the gateway; someone else approves it (four-eyes), then it is created at Razorpay / Cashfree and its URL shared |
| Settings → Payment gateways | Razorpay / Cashfree connections per mode (finance admins): key id, write-only encrypted secrets, test connection, enable, default; the webhook URLs to register |

Engage calls `POST /api/v1/inbound/engage/payment-links` (AIC-S2S v1, client `engage`, issue key, `Idempotency-Key`);
the providers call `POST /api/v1/webhooks/razorpay` / `…/cashfree` (signature over the raw body). Finance re-reads every
payment from the provider before it counts, issues the GST invoice (`buyer_kind = academy_student`, no `cmp_id`) and
sends `payment.succeeded` / `payment.refunded` to Engage (`bin/academy-fees-cron.php`). Contract, provider setup,
go-live checklist and the provider details still to verify: [docs/ACADEMY_PAYMENTS.md](docs/ACADEMY_PAYMENTS.md).

## Subscription invoicing

| Screen | What it does |
| --- | --- |
| Invoices | register of tax invoices and credit notes (FY, type, status, company, search), GSTR-1 CSV export; invoice detail with the stored PDF; request a credit note (full or partial) |
| Credit note | four-eyes approval (someone other than the requester numbers and issues it) or rejection |
| Offline payments | record a bank / UPI / cheque receipt with its UTR (maker); someone else verifies it against the statement and the tax invoice is issued |
| Settings → Invoicing | seller profile (GSTIN, address, signatory) and the gapless document series per FY (finance admins) |

Invoices are immutable (database triggers), numbered without gaps per FY, rendered
to PDF through Gotenberg and stored at issue. Manage reads them with AIC-S2S v1
signed requests (`/api/v1/inbound/manage/documents…`, filtered by `cmp_id`);
customers never sign in to Finance. Data model, tax rules, the signature scheme
with a worked example, environment variables, health flags and the runbook:
[docs/INVOICING.md](docs/INVOICING.md).

Tests: `php server-php/tests/run.php` — policy and payout unit tests (TDS
threshold, GST modes, state machine, four-eyes, encryption, bank files), the
real API under `php -S` against a stub Console, and the payout flow end to end
against PostgreSQL (own database `finance_test` — `FINANCE_TEST_DB_NAME` overrides —
plus `<name>_legacy` for the migration-002 test, both dropped afterwards; stub Engage
for the outbox; invoicing against the same database with a stub Gotenberg and
two concurrent issuing processes; Academy fees against the same database with a stub Razorpay / Cashfree,
stub Engage and the cron). Defaults to `127.0.0.1:5432` `postgres/postgres`
(`FINANCE_TEST_DB_*` override); the PostgreSQL part is skipped with a warning
when it is unreachable unless `FINANCE_TESTS_REQUIRE_DB=1`.

API scripts (CLI only): `php server-php/bin/migrate.php [--status]`,
`php server-php/bin/outbox-deliver.php`, `php server-php/bin/invoicing-cron.php` and
`php server-php/bin/academy-fees-cron.php` (all cron, every 5 min).

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `dist/` |
| `npm run typecheck` | Type-check only |
| `npm run lint` | ESLint (TypeScript + React hooks rules) |
| `npm run preview` | Serve the production build locally |

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. Copy it and fill in real values locally.

| Variable | Description |
| --- | --- |
| `PROD_API_BASE_URL` | Optional production override; unset means the same-origin `/api` |
| `VITE_API_BASE_URL` | The endpoint this build actually uses (defaults to `/api`) |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local` or `production` |
| `VITE_CONSOLE_URL` | Console web app (defaults to `https://console.aicountly.org`) |
| `VITE_CONSOLE_API_URL` | Console API (defaults to `https://console.aicountly.org/api`) |

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token, or password in a `VITE_` variable.

### These are build-time values, not runtime values

This matters for how you change an endpoint in production.

Vite substitutes each `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files — **the app never reads a
`.env` from disk at runtime**, so placing a `.env` next to it in the cPanel
document root has no effect. Changing an endpoint means rebuilding and
redeploying.

This is the opposite of server-php, which is PHP and does read its own `.env`
on every request.

So to change the API URL for the deployed app: update the repository variable
`PROD_API_BASE_URL`, then re-run the deploy workflow. The rebuild is what
applies the change.

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — the workflow
triggers exclusively via `workflow_dispatch`.

To deploy: **Actions** → *Deploy to cPanel Production* → **Run workflow** →
pick a branch → **Run**.

Each run builds `web/` and `rsync`s the result over SSH:

| Source | Destination |
| --- | --- |
| `web/dist/` | the document root (`public_html`) |
| `server-php/` | `api/` inside the document root |

Source, `node_modules`, and `.env` never reach the server. The `server-php`
step is skipped when that directory is not present in the repository.

Before deploying, the workflow checks that every required SSH secret is set and
that the remote root is a safe path, then verifies SSH authentication — so a
misconfigured repository fails in seconds with a named cause instead of part-way
through a deploy.

### Configuration

These repository **secrets** must be set (Settings → Secrets and variables →
Actions → Secrets):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT`.

`PROD_SSH_REMOTE_ROOT` is the document root to deploy into. It may be relative,
which is the usual cPanel form — `public_html` resolves against the SSH user's
home directory, giving `/home/<user>/public_html`. An absolute path such as
`/home/financeaic/public_html` works too. Because the deploy runs with
`--delete`, the workflow refuses a value that would resolve to the home
directory itself (`.`, `~`, empty), a system directory, or anything containing
`..`.

This repository **variable** sets the API endpoint (Settings → Secrets and
variables → Actions → Variables):

| Variable | Used by |
| --- | --- |
| `PROD_API_BASE_URL` | Production deploy |

If it is missing the build still succeeds but logs a warning and uses a
placeholder URL, so the app will visibly fail to reach the API rather than
quietly calling the wrong backend.

### Notes on the rsync step

`--delete` keeps the document root in sync with the build, but these paths are
excluded so a deploy cannot destroy them:

- `api/` — the PHP backend lives inside the document root and is deployed by
  its own step. **Without this exclude every web deploy would delete the entire
  API.**
- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

The API step excludes `.env` for the same reason: the API's `.env` is created
once on the server and read at runtime, so it must survive every deploy. See
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

`web/public/.htaccess` ships with the build and provides the SPA history
fallback so client-side routes survive a refresh, plus cache headers
(`index.html` uncached, hashed assets cached for a year).
