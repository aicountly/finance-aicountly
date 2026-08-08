# finance-aicountly

Finance front end for Aicountly — a React single-page app built with Vite and
TypeScript. The API backend is **server-php**, running on the live cPanel
portal.

| Environment | URL |
| --- | --- |
| Production | https://finance.aicountly.org |

## Getting started

Requires Node.js 22 or newer.

```bash
npm install
cp .env.example .env    # then set VITE_API_BASE_URL to your server-php endpoint
npm run dev
```

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
| `PROD_API_BASE_URL` | server-php API base URL for production |
| `VITE_API_BASE_URL` | The endpoint this build actually uses |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local` or `production` |

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

The run installs dependencies, builds, and `rsync`s only the built `dist/` to
the cPanel document root over SSH. Source, `node_modules`, and `.env` never
reach the server.

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

- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

`public/.htaccess` ships with the build and provides the SPA history fallback
so client-side routes survive a refresh, plus cache headers (`index.html`
uncached, hashed assets cached for a year).
