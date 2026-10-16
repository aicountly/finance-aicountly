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

Finance keeps no database of its own — `console.aicountly.org` is the sole
identity provider (see [Sign-in (Console SSO)](../README.md#sign-in-console-sso)),
so there is no `DB_*` block to configure here, unlike a typical portal.

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

The web build already does this for the document root via `web/public/.htaccess`,
but that file does not apply inside `api/` once the API's own rules take over.

## Required secrets

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT`.

The workflow validates all five before building, and verifies SSH
authentication before writing anything to the server.
