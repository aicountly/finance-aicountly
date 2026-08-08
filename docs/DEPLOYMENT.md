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

Create it once by hand — cPanel File Manager or SSH — at `<remote root>/api/.env`:

```
DB_HOST=localhost
DB_NAME=<cpaneluser>_<dbname>
DB_USER=<cpaneluser>_<dbuser>
DB_PASS=<password>
APP_ENV=production
```

It is never committed and never uploaded: `.gitignore` excludes it, and both
rsync steps pass `--exclude='.env'` so a `--delete` deploy leaves the
server-side file untouched.

### Database on cPanel

Create the database and user under **MySQL Databases**. cPanel prefixes both
with the account name, so a database entered as `app` becomes
`<cpaneluser>_app` — use the full prefixed names in `.env`.

After creating the user, **add it to the database and grant ALL PRIVILEGES**. A
user that exists but was never attached to the database is a common cause of a
connection failing with no obvious reason.

`DB_HOST` is `localhost` on cPanel; the database is on the same machine.

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
