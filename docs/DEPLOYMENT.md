# Deploying Finance

## Layout

```
web/          React app (Vite). Builds to web/dist.
server-php/   PHP API. Deployed as-is.
docs/         this file
```

## What lands where on cPanel

| Source | Destination | Reachable at |
| --- | --- | --- |
| `web/dist/` | `<remote root>/` | https://finance.aicountly.org |
| `server-php/` | `<remote root>/api/` | https://finance.aicountly.org/api |

`<remote root>` is the `*_SSH_REMOTE_ROOT` secret, normally `public_html`.

Deployment is manual only — **Actions → Deploy to cPanel Production → Run
workflow**. Nothing deploys on push or merge.

### Why the api folder survives a web deploy

The web deploy runs `rsync --delete` against the document root, which would
otherwise remove everything not in the build — including `api/`, since the API
lives inside the document root. The web step therefore excludes `api/`
explicitly, and the API is deployed by its own step. Removing that exclude
would delete the entire backend on the next web deploy.

## Configuration: two different mechanisms

This is the part worth reading carefully, because the frontend and the backend
behave in opposite ways.

### React (web/) — build time

Vite inlines every `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files that **never read a `.env`
from disk**. Putting a `.env` in the document root has no effect.

To change a frontend value: update the repository *variable*
`PROD_API_BASE_URL`, then re-run the workflow. The rebuild applies it.

`PROD_API_BASE_URL` is optional and normally unset. The API is deployed into
this same document root at `/api`, so the build defaults to the relative
`/api` — which needs no CORS grant and keeps the shared `.aicountly.org` SSO
cookie first-party. Set the variable only if the API is ever moved to a
different host, and add that host to Console's `CORS_ORIGINS` if you do.

Never put a secret in a `VITE_` variable — anything inlined into the bundle is
public to anyone who views the page source.

### server-php — runtime

PHP reads its `.env` on **every request**. So the API's `.env` belongs on the
server, and only on the server.

Users and roles are not stored here — `console.aicountly.org` is the sole
identity provider (see [Sign-in (Console SSO)](../README.md#sign-in-console-sso)).
The API's PostgreSQL database holds the weekly seller payout ledger only
(see [Weekly seller payouts](#weekly-seller-payouts) below).

Create the `.env` once by hand — cPanel File Manager or SSH — at
`<remote root>/api/.env`:

```
APP_ENV=production
BASE_URL=https://finance.aicountly.org/api
FRONTEND_URL=https://finance.aicountly.org

CONSOLE_API_URL=https://console.aicountly.org/api
CONTROLLER_APP_CODE=finance
CONSOLE_API_TOKEN=<Console service token with capability staff.roles>

JWT_SECRET=<long random value, different from Console's JWT_SECRET>
TOKEN_TTL_HOURS=8

CORS_ORIGINS=https://finance.aicountly.org

FINANCE_DB_HOST=localhost
FINANCE_DB_PORT=5432
FINANCE_DB_NAME=<cpanel_prefix>_finance
FINANCE_DB_USER=<cpanel_prefix>_finance
FINANCE_DB_PASSWORD=<database password>

FINANCE_DATA_KEY=<64 hex characters: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'>

FINANCE_INBOUND_ENGAGE_KEY=<same value as FINANCE_INBOUND_ENGAGE_KEY in Engage's .env, ≥ 32 chars>
ENGAGE_API_BASE_URL=https://engage.aicountly.org/api
ENGAGE_FINANCE_KEY=<same value as ENGAGE_INBOUND_FINANCE_KEY in Engage's .env, ≥ 32 chars>
```

**`CONSOLE_API_TOKEN` is required in production.** Finance has no refresh
endpoint, so every authenticated request re-checks the user's Finance roles
with Console (`GET {CONSOLE_API_URL}/portal/controller-apps/user-access`),
cached for 60 s. Without the token a role removed in Console keeps working
until the Finance JWT expires (`TOKEN_TTL_HOURS`), and the API logs a
warning on every request. See
[Roles](../README.md#roles-assigned-in-console).

The 60-second cache uses APCu when it is enabled, otherwise one small file per
user under the system temp directory; `FINANCE_AUTHZ_CACHE_DIR=<path>`
(optional) moves it, e.g. when the host's temp directory is shared or not
writable.

It is never committed and never uploaded: `.gitignore` excludes it, and both
rsync steps pass `--exclude='.env'` so a `--delete` deploy leaves the
server-side file untouched. `server-php/tests/` is excluded too (it is the
local test harness, `php server-php/tests/run.php`).

Console must also list `https://finance.aicountly.org` in its own
`CORS_ORIGINS` (server-php/.env on the Console host) for the direct-visit
session check (`POST /v1/auth/console-session`) to succeed from the browser.

### api/.htaccess — the file the whole API depends on

`server-php/.htaccess` is rsynced to `<remote root>/api/.htaccess`, and it is
what routes `/api/v1/...` to `public/index.php`. Those paths match no file on
disk, and mod_rewrite's per-directory rules are **not** inherited from the
document root, so without this file every API request falls through to the
SPA's history fallback and is answered with `index.html`. That is invisible
for a `fetch()` (it just fails to parse) and very visible for Console's SSO
callback: the browser stops on
`https://finance.aicountly.org/api/v1/auth/sso-callback` showing the Finance
sign-in page, and nobody can log in. The deploy workflow refuses to run if
the file is missing.

It also re-exposes the `Authorization` header, which Apache drops when it
rewrites to a front controller — without that, every `Bearer` request
(`GET /api/v1/auth/me`) would 401.

Finally, because `api/` sits inside the document root, `.env` would be
fetchable at `https://finance.aicountly.org/api/.env` unless Apache is told
otherwise, so the same file denies dotfiles:

```apache
RedirectMatch 404 /\\.(?!well-known)
```

It also answers 404 for `api/bin/` (migrate.php, outbox-deliver.php — CLI only,
and they refuse a web request themselves), `api/database/` (migration SQL),
`api/app/` and `api/tests/`: only `public/index.php` is an entry point.

The web build already does this for the document root via `web/public/.htaccess`,
but that file does not apply inside `api/` once the API's own rules take over.

## Required secrets

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT`.

Optional repository variable `PROD_PHP_BIN` (default `php`): the PHP 8.1+ CLI
the migration step runs on the server, for hosts where plain `php` is something
else (e.g. `/usr/local/bin/ea-php83`).

The workflow validates all five before building, and verifies SSH
authentication before writing anything to the server.

## Weekly seller payouts

Engage (engage.aicountly.org) builds a payout run every Monday and sends it to
Finance. Finance never moves money:

1. **Draft** — `POST /api/v1/inbound/engage/payout-batches` (header
   `X-Engage-Key`) stores the batch as a draft and computes GST, TDS (194H) and
   net per item. Idempotent on `batch_ref` (same body again → 200 with the same
   `batch_id`; different body → 409). Payee PAN, GSTIN and bank account are
   encrypted with `FINANCE_DATA_KEY` and shown masked everywhere.
2. **Review** — an accountant (or approver / admin) holds or releases items
   (reason required), adds signed adjustments (reason required), recalculates,
   and submits the batch for review.
3. **Approve** — a payout approver or finance admin approves. Four-eyes (setting
   `four_eyes_enabled`, on by default): the approver may not be the person who
   submitted the batch or who last changed it. Engage is told each item is
   `approved` (held items: `on_hold`).
4. **Bank file** — the approver generates the bulk-upload CSV (generic NEFT/IMPS,
   or the ICICI/HDFC-style layout — **confirm that format with your bank before
   the first upload**), downloads it (checksum shown and audited) and uploads it
   to the bank portal by hand. Requires the debit account in Settings.
5. **Payment results** — the approver records a UTR per item, or uploads the
   bank's response CSV (preview first, then apply). Only then is an item `paid`;
   nothing is ever marked paid without a UTR (also a database constraint).
   Rejections are `failed`, returned credits `reversed`; held items can be
   released back to Engage. Each result goes to Engage through the outbox.

Every change is in the append-only audit log (Finance → Audit log).

### Database

Create a PostgreSQL database and user in cPanel (PostgreSQL Databases), put the
credentials in `api/.env` (`FINANCE_DB_*`), then deploy: the workflow runs
`php api/bin/migrate.php` on the server after every upload. By hand:

```bash
cd ~/public_html && php api/bin/migrate.php            # apply pending migrations
php api/bin/migrate.php --status                       # applied / pending
```

Migrations live in `server-php/database/migrations/NNN_name.sql`, are applied in
order (each in a transaction) and recorded in `schema_migrations` with a
checksum; an applied migration whose file changed is refused — add a new file.
Current: `001_payouts` (settings, payees, payout_batches, payout_items,
payout_adjustments, tds_ledger, bank_files, audit_log, outbox).

Back up the database **and** `FINANCE_DATA_KEY` together: without the key the
stored PANs, GSTINs and account numbers cannot be decrypted.

### Cron

One job, every 5 minutes (cPanel → Cron Jobs), delivering status callbacks to
Engage:

```cron
*/5 * * * * php /home/<user>/public_html/api/bin/outbox-deliver.php >> /home/<user>/logs/finance-outbox.log 2>&1
```

It sends each queued update with `X-Finance-Key` and an `Idempotency-Key`,
retries failures with back-off (2, 4, 8 … minutes, at most 6 h apart) and marks
an update `dead` after 12 attempts (Settings → Engage status callbacks → Retry,
finance admins). Updates for one item go out in order. Without
`ENGAGE_API_BASE_URL` / `ENGAGE_FINANCE_KEY` it sends nothing and marks nothing
delivered.

### Readiness

`GET /api/health` adds booleans `database` (reachable and migrated),
`data_key`, `engage_inbound` and `engage_callbacks`; `status` is `ok` only when
every check is true.

### Operator steps after the first deploy

1. Create the PostgreSQL database + user; add `FINANCE_DB_*` to `api/.env`.
2. Generate `FINANCE_DATA_KEY` and store a copy with the database credentials.
3. Agree two ≥ 32-character keys with the Engage operator:
   `FINANCE_INBOUND_ENGAGE_KEY` (same name on both sides) and
   `ENGAGE_FINANCE_KEY` here = `ENGAGE_INBOUND_FINANCE_KEY` in Engage; set
   `ENGAGE_API_BASE_URL`.
4. Re-run the deploy (or `php api/bin/migrate.php`); check `/api/health`.
5. Add the cron line above.
6. In Console, assign Finance roles: `payout_approver` (approve, bank file,
   payment results), `accountant` (review drafts, TDS export), `finance_admin`
   (everything + Settings), `finance_viewer` (read-only). Four-eyes needs at
   least two people who can approve or prepare.
7. As a finance admin, open Settings: set the **debit account number**, check
   the bank file format and payment mode, and confirm the tax settings below
   with your CA.
8. Before the first real upload, send a one-line test file to your bank (or
   compare against your bank's own template) to confirm the column layout.

### Tax assumptions — confirm with your CA

The defaults are a starting point, not advice:

- **TDS section 194H** (commission or brokerage) at **2%** (rate from 1 Oct
  2024) with an annual threshold of **₹20,000 per payee per FY** (from 1 Apr
  2025). Finance deducts nothing until a PAN's FY total exceeds the threshold;
  the payment that crosses it bears TDS on the whole FY total, after that each
  payment bears the rate. TDS is computed on the commission excluding GST. FY
  is taken from the batch's period end; the register's quarter from the
  payment date.
- PAN is required for every payee (Engage KYC). Section 206AA (no / inoperative
  PAN, 20%), 206AB, lower-deduction certificates (197) and non-resident payees
  are **not** handled — set such a payee aside (hold) and treat it manually.
- **GST on commission**: by default, a payee with a GSTIN is paid GST at 18% on
  top of the commission (`exclusive`); payees without a GSTIN get no GST. Modes
  `inclusive` (Engage's amount already includes GST) and `none` exist. Reverse
  charge, ITC matching and GST invoices from sellers are outside Finance.
- Adjustments change only the amount paid, not the TDS/GST base.
- A failed, cancelled or reversed payout voids its TDS ledger row (so a
  re-payment is not taxed twice). If TDS for it was already deposited, handle
  it in your TDS return / correction.
- Form 26Q: the TDS register gives deductee PAN, name, deductee code (01
  company / 02 other), section, amount paid, rate, TDS and date per payment;
  challan details are added in your filing software.
