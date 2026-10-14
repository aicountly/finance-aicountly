# finance-aicountly

Finance front end for Aicountly — a React single-page app built with Vite and
TypeScript. The API backend is **server-php**, running on the live cPanel
portal.

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

| Role | Label |
| --- | --- |
| `finance_admin` | Finance Administrator |
| `finance_viewer` | Finance Viewer (read-only) |

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
  screen `roles`, `hasRole()` and `isAdmin`; admin-only controls must render
  only for `finance_admin`. No current screen differs by role; the dashboard
  shows the assigned role.

Tests: `php server-php/tests/run.php` (policy unit tests plus the real API
under `php -S` against a stub Console).

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `dist/` |
| `npm run typecheck` | Type-check only |
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
