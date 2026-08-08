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

JWT_SECRET=<long random value, different from Console's JWT_SECRET>
TOKEN_TTL_HOURS=8

CORS_ORIGINS=https://finance.aicountly.org
```

It is never committed and never uploaded: `.gitignore` excludes it, and both
rsync steps pass `--exclude='.env'` so a `--delete` deploy leaves the
server-side file untouched.

Console must also list `https://finance.aicountly.org` in its own
`CORS_ORIGINS` (server-php/.env on the Console host) for the direct-visit
session check (`POST /v1/auth/console-session`) to succeed from the browser.

### Protecting the API's .env over HTTP

Because `api/` sits inside the document root, `.env` would be fetchable at
`https://finance.aicountly.org/api/.env` unless Apache is told otherwise. `server-php`
should ship an `.htaccess` denying dotfiles:

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
