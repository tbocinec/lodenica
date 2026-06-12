# Deploy to rezervacie.lodenicakvs.sk (SFTP-only Websupport)

Production target:
- **Domain**: `rezervacie.lodenicakvs.sk` — serves the SPA and `/api/v1/*`
- **Postgres 14**: `lodenicakvs.sk:5432`, DB `kvsrez`, user `pgrez`
- **PHP**: 8.2+ (Websupport selectable in the admin panel)
- **No SSH** — only SFTP on port 22
- **VPN catch**: corporate VPNs typically block port 22 outbound; **run
  the deploy with VPN disabled**.

Everything below is driven by [scripts/deploy-rezervacie.sh](../../scripts/deploy-rezervacie.sh)
which reads credentials from the gitignored `.deploy-secrets` at the repo
root.

## One-time setup

1. **Install lftp** on the deploy machine (Ubuntu/WSL):
   ```bash
   sudo apt-get update && sudo apt-get install -y lftp
   ```

2. **Populate `.deploy-secrets`** at the repo root from `.deploy-secrets.example`:
   ```bash
   cp .deploy-secrets.example .deploy-secrets
   chmod 600 .deploy-secrets
   # then $EDITOR .deploy-secrets — fill in SFTP creds, DB creds, domain
   ```

3. **Discover the remote layout** so you can fill the two `*_REMOTE` paths:
   ```bash
   # Disable VPN first.
   scripts/deploy-rezervacie.sh --explore
   ```

   For this hosting the SFTP root **is** the subdomain docroot — there's
   no level above it. The deploy uses an "all-in-docroot" layout:
   ```
   DEPLOY_DOCROOT_REMOTE='/'         # SFTP root = web docroot
   DEPLOY_LARAVEL_APP_REMOTE='/laravel'  # Laravel inside, web-denied
   ```

   The Laravel app at `/laravel/` is denied two ways:
   - the root `.htaccess` has `RewriteRule ^laravel(/|$) - [F,L]`
   - the `/laravel/.htaccess` file has `Require all denied`

   Both `.env`, `vendor/`, `storage/` and the rest never reach a client.

4. **Webserver routing on nginx (OpenResty)** — Websupport sub-hostings
   actually serve PHP via Apache underneath (the openresty header you see
   on the bare domain is just the edge proxy). The `.htaccess` rules
   shipped in `deploy/websupport-docroot/.htaccess` are loaded and handle
   API ↔ SPA routing the same way as the SSH-enabled `tomas.gart.sk`
   deploy. If a future hosting turns out to be pure nginx without
   `mod_php`/`mod_rewrite`, the script's output will still upload cleanly
   and `index.php` plus the SPA will be reachable directly; you'd then
   need a small nginx snippet from the admin panel:
   ```nginx
   location / {
       try_files $uri $uri/ /index.php?$query_string;
   }
   ```

## Deploying

Pick whichever path is more convenient.

### Option A — GitHub Actions (recommended for updates)

Manual-only workflow that runs the exact same script on a GitHub-hosted
runner (no VPN headaches):

1. **Settings → Actions → Deploy rezervacie.lodenicakvs.sk → Run workflow**
2. Pick the branch and tick `import_sheet` only if you want the destructive
   re-import.
3. Watch the live logs in the Actions tab.

One-time setup (see comments at the top of
[.github/workflows/deploy-rezervacie.yml](../../.github/workflows/deploy-rezervacie.yml)):
add the listed `REZERVACIE_*` secrets and variables to the repo. The
APP_KEY is generated once locally and stored as a secret so signed
cookies survive every deploy.

### Option B — Local script (when GitHub Actions is unavailable)

```bash
# 1. Disable VPN (port 22 outbound is usually blocked by corp VPNs).
# 2. From the repo root:

# First install — populates the DB from the Google Sheet:
scripts/deploy-rezervacie.sh --import-sheet

# Every subsequent update (code-only, KEEPS the live data):
scripts/deploy-rezervacie.sh

# 3. Re-enable VPN when the script reports "Deploy finished".
```

What it runs, in order:

| Stage | Action |
| ----- | ------ |
| Build | `composer install --no-dev --optimize-autoloader` in a clean stage |
|       | `pnpm build` with `VITE_API_BASE_URL=https://rezervacie.lodenicakvs.sk/api/v1` |
|       | Generates `.env` from `.deploy-secrets` (re-uses `DEPLOY_APP_KEY`) |
|       | Substitutes a fresh random token + the `__LARAVEL_PATH__` into `install.php` |
| Upload | `lftp mirror -R` Laravel app → `$DEPLOY_LARAVEL_APP_REMOTE` |
|        | `lftp mirror -R` docroot → `$DEPLOY_DOCROOT_REMOTE`, protecting `laravel/`, `install.php`, `logo.jpg` from `--delete` |
| Install | `curl` hits `https://$PROD_DOMAIN/install.php?token=...&import=0|1` |
|         | install.php runs `migrate --force` (additive — only new migrations) |
|         | if `--import-sheet`: also runs `lodenica:import-sheet --force` (DESTRUCTIVE) |
|         | then `config:cache + route:cache + event:cache` |
|         | install.php self-deletes after success so a leaked token can't be replayed |
| Smoke | `curl /health`, `curl /api/v1/resources`, `curl /` |

### What's safe to repeat on a live deployment

| Action | Update-safe? | Why |
| --- | --- | --- |
| Composer / vendor re-upload | ✓ | `lftp mirror --delete` overwrites changed files; PHP-FPM picks up new code on the next request. |
| `.env` overwrite | ✓ (intentional) | The script regenerates `.env` from `.deploy-secrets` every time. `DEPLOY_APP_KEY` is persisted there so session cookies survive. If you want server-side `.env` edits to persist, put them in `.deploy-secrets` instead. |
| Frontend re-upload | ✓ | Vite produces hashed filenames; orphans get cleaned by `--delete`. `logo.jpg` and `laravel/` are explicitly protected. |
| `php artisan migrate --force` | ✓ | Laravel only runs migrations whose name isn't already in the `migrations` table. |
| `config:cache` rebuild | ✓ | Old cache is cleared first; new one is written atomically. |
| `lodenica:import-sheet --force` | ✗ | **DESTRUCTIVE** — wipes damages, reservations, events and resources before re-importing. Off by default; requires `--import-sheet`. |
| install token rotation | ✓ | Each deploy generates a new token; the previous deploy's token is invalidated by the upload overwriting `install.php`. |
| storage/logs preservation | ✓ | `--exclude-glob 'storage/logs/*'` on the Laravel mirror keeps server-side log history. |

### Disaster recovery — re-importing the inventory deliberately

If the production DB ever gets out of sync with the master Google Sheet
and you actually *do* want to reset the inventory:

```bash
scripts/deploy-rezervacie.sh --import-sheet
```

The script prints a 5-second `Ctrl-C` window before triggering the
destructive importer. After import, the boathouse spaces and 7 sample
reservations are recreated; existing user-entered reservations are
**lost**.

## Useful flags

```bash
# Just rebuild the stage locally, don't touch the server.
scripts/deploy-rezervacie.sh --no-upload --no-install --no-smoke

# Upload a previously-built stage (skip composer + pnpm).
scripts/deploy-rezervacie.sh --no-build

# Push static assets only; don't run migrations or rebuild caches.
scripts/deploy-rezervacie.sh --no-install
```

If `install.php` fails partway through (say, the sheet import errors out
because the spreadsheet is being edited), the script leaves the file in
place. You can re-trigger a single phase by hand:

```
https://rezervacie.lodenicakvs.sk/install.php?token=<token>&step=migrate
https://rezervacie.lodenicakvs.sk/install.php?token=<token>&step=cache
```

When everything is fine, hit the URL without `step=` to run all phases
and self-delete the file.

## Rotating credentials

The initial SFTP and DB passwords for this hosting were shared over chat
during setup. Rotate both in the Websupport admin once you've verified
the site works end-to-end, then update `.deploy-secrets` and re-run
`scripts/deploy-rezervacie.sh` to push the new `.env`.
