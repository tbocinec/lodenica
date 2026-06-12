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
   The script logs into SFTP, prints the root + any nested `sub/` listings,
   and tells you what to put into `.deploy-secrets`:
   ```
   DEPLOY_LARAVEL_APP_REMOTE='<...>/lodenica-app'
   DEPLOY_DOCROOT_REMOTE='<...>/sub/rezervacie'
   ```
   A typical Websupport layout has `~/lodenicakvs.sk/sub/rezervacie/` as the
   subdomain docroot and `~/lodenica-app/` (created during deploy) outside.

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

```bash
# 1. Disable VPN (port 22 outbound is usually blocked by corp VPNs).
# 2. From the repo root:
scripts/deploy-rezervacie.sh
# 3. Re-enable VPN when the script reports "Deploy finished".
```

What it runs, in order:

| Stage | Action |
| ----- | ------ |
| Build | `composer install --no-dev --optimize-autoloader` in a clean stage |
|       | `pnpm build` with `VITE_API_BASE_URL=https://rezervacie.lodenicakvs.sk/api/v1` |
|       | Generates `.env` from `.deploy-secrets` (re-uses `DEPLOY_APP_KEY`) |
|       | Substitutes a fresh random token into `install.php` |
| Upload | `lftp mirror -R` Laravel app → `$DEPLOY_LARAVEL_APP_REMOTE` |
|        | `lftp mirror -R` docroot (SPA + `index.php` + `.htaccess` + `install.php`) → `$DEPLOY_DOCROOT_REMOTE` |
| Install | `curl` hits `https://$PROD_DOMAIN/install.php?token=...` |
|         | install.php runs `migrate --force`, `lodenica:import-sheet --force`, then `config:cache + route:cache + event:cache` |
|         | install.php self-deletes after success so a leaked token can't be replayed |
| Smoke | `curl /health`, `curl /api/v1/resources`, `curl /` |

Re-running the script after a code change just repeats every stage; the
Laravel app's `.env` and `storage/logs/` on the server are protected from
`mirror --delete` so locally-generated state survives.

## Useful flags

```bash
# Just rebuild the stage locally, don't touch the server.
scripts/deploy-rezervacie.sh --no-upload --no-install --no-smoke

# Upload a previously-built stage (skip composer + pnpm).
scripts/deploy-rezervacie.sh --no-build

# Deploy code only, don't re-run migrations / re-import the sheet.
scripts/deploy-rezervacie.sh --no-install

# Code + cache rebuild + verify, but skip the destructive sheet importer.
scripts/deploy-rezervacie.sh --no-import
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
