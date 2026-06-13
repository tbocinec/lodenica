#!/usr/bin/env bash
#
# Deploy the Lodenica PHP backend + Vue SPA to a Websupport hosting that
# only exposes SFTP (no SSH, no Composer on the box). Run it with your VPN
# disabled — port 22 outbound through a corporate VPN is typically blocked.
#
# Reads connection details and DB credentials from `.deploy-secrets` at
# the repo root (gitignored). What it does, in order:
#
#   1. composer install --no-dev --optimize-autoloader   (locally)
#   2. pnpm build with VITE_API_BASE_URL = https://$PROD_DOMAIN/api/v1
#   3. Generates / re-uses APP_KEY, writes `.env` for the bundle
#   4. Substitutes a fresh random token into `install.php`
#   5. Mirrors `lodenica-app/` and the docroot over SFTP via lftp
#   6. Hits https://$PROD_DOMAIN/install.php?token=... to run migrations,
#      the sheet importer and the artisan caches — then self-deletes
#   7. Smoke-tests /health, /api/v1/resources and the SPA shell
#
# Idempotent on re-run; safe to invoke after every code change.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS="$REPO_ROOT/.deploy-secrets"
STAGE="${LODENICA_DEPLOY_STAGE:-/tmp/lodenica-rezervacie-deploy}"

#────────────────────────────────────────────────────────────────────────────
# Flags
#────────────────────────────────────────────────────────────────────────────
DO_EXPLORE=0
DO_BUILD=1
DO_UPLOAD=1
DO_INSTALL=1
DO_IMPORT=0        # default OFF — destructive; only opt-in for first install / refresh
DO_SMOKE=1

usage() {
    cat <<'USAGE'
Usage: scripts/deploy-rezervacie.sh [options]

Safe for both first install AND repeated code updates by default:
  - migrations run (only NEW ones — additive)
  - artisan config:cache, route:cache, event:cache are rebuilt
  - the Google Sheet importer DOES NOT run unless --import-sheet is passed
    (it wipes damages, reservations and events — destructive on a live DB)

  --explore             SFTP-login, print the remote directory layout and exit.
  --import-sheet        Run `lodenica:import-sheet --force` inside install.php.
                        DESTRUCTIVE — wipes the live DB of resources,
                        reservations, events and damages. Use ONLY for the
                        first install or a deliberate inventory refresh.
  --no-build            Skip composer install + pnpm build (reuse last stage).
  --no-upload           Skip the SFTP upload (build only).
  --no-install          Skip the HTTPS install.php trigger (no migrate / no cache).
  --no-smoke            Skip the post-deploy curl smoke tests.
  -h, --help

Required env in .deploy-secrets:
  DEPLOY_SFTP_HOST DEPLOY_SFTP_PORT DEPLOY_SFTP_USER DEPLOY_SFTP_PASSWORD
  DEPLOY_LARAVEL_APP_REMOTE   (e.g. /laravel)
  DEPLOY_DOCROOT_REMOTE       (e.g. /  or  /sub/rezervacie)
  PROD_DB_HOST PROD_DB_PORT PROD_DB_NAME PROD_DB_USER PROD_DB_PASSWORD
  PROD_DOMAIN                 (e.g. rezervacie.lodenicakvs.sk)

Optional:
  DEPLOY_APP_KEY              persist a base64: key here after first deploy
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --explore)         DO_EXPLORE=1 ;;
        --import-sheet|--import) DO_IMPORT=1 ;;
        --no-build)        DO_BUILD=0 ;;
        --no-upload)       DO_UPLOAD=0 ;;
        --no-install)      DO_INSTALL=0 ;;
        --no-smoke)        DO_SMOKE=0 ;;
        -h|--help)         usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
    esac
    shift
done

#────────────────────────────────────────────────────────────────────────────
# Preflight
#────────────────────────────────────────────────────────────────────────────
log() { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

[[ -f "$SECRETS" ]] || die ".deploy-secrets not found at $SECRETS. Copy .deploy-secrets.example and fill in."

# Source the secrets file. shellcheck source=/dev/null
set -a; . "$SECRETS"; set +a

require_var() {
    for v in "$@"; do
        [[ -n "${!v:-}" ]] || die "$v not set in .deploy-secrets"
    done
}
require_var DEPLOY_SFTP_HOST DEPLOY_SFTP_USER DEPLOY_SFTP_PASSWORD \
            PROD_DB_HOST PROD_DB_NAME PROD_DB_USER PROD_DB_PASSWORD \
            PROD_DOMAIN
DEPLOY_SFTP_PORT="${DEPLOY_SFTP_PORT:-22}"
PROD_DB_PORT="${PROD_DB_PORT:-5432}"

require_cmd() {
    for c in "$@"; do
        command -v "$c" >/dev/null 2>&1 || die "Missing required command: $c"
    done
}
need_lftp_hint() {
    cat >&2 <<'HINT'

Install lftp once on this machine (Ubuntu/WSL):

  sudo apt-get update && sudo apt-get install -y lftp

lftp drives the SFTP upload (parallel mirror — much faster and more
robust than scripting OpenSSH sftp by hand).
HINT
    exit 1
}
# Core tooling
require_cmd php composer rsync sed
# Frontend build needs pnpm
(( DO_BUILD )) && require_cmd pnpm
# SFTP work needs lftp; print a friendly install hint instead of a bare error
if (( DO_EXPLORE || DO_UPLOAD )); then
    command -v lftp >/dev/null 2>&1 || { warn "lftp is missing."; need_lftp_hint; }
fi
# HTTP probes need curl
if (( DO_INSTALL || DO_SMOKE )); then
    require_cmd curl
fi

#────────────────────────────────────────────────────────────────────────────
# Explore mode — just print the remote layout and exit
#────────────────────────────────────────────────────────────────────────────
if (( DO_EXPLORE )); then
    log "Listing remote SFTP root for $DEPLOY_SFTP_USER@$DEPLOY_SFTP_HOST:$DEPLOY_SFTP_PORT…"
    lftp -u "$DEPLOY_SFTP_USER,$DEPLOY_SFTP_PASSWORD" -p "$DEPLOY_SFTP_PORT" \
        "sftp://$DEPLOY_SFTP_HOST" <<'LFTP'
set sftp:auto-confirm yes
pwd
cls -la
bye
LFTP
    cat <<EXPLAIN

The Lodenica hosting at $DEPLOY_SFTP_HOST puts the SFTP root *inside* the
subdomain docroot (everything you see above is web-accessible). Use the
all-in-docroot layout:

  DEPLOY_DOCROOT_REMOTE='/'         # SFTP root = web docroot
  DEPLOY_LARAVEL_APP_REMOTE='/laravel'  # Laravel app at /laravel — denied by .htaccess

For hostings that expose a higher SFTP root (Laravel app can live in a
sibling directory like ~/lodenica-app/ outside the subdomain docroot),
set DEPLOY_LARAVEL_APP_REMOTE to that absolute path and adjust
DEPLOY_DOCROOT_TEMPLATE in this script.
EXPLAIN
    exit 0
fi

require_var DEPLOY_LARAVEL_APP_REMOTE DEPLOY_DOCROOT_REMOTE

#────────────────────────────────────────────────────────────────────────────
# Stage 1 — build the bundle locally
#────────────────────────────────────────────────────────────────────────────
LARAVEL_STAGE="$STAGE/lodenica-app"
DOCROOT_STAGE="$STAGE/docroot"

if (( DO_BUILD )); then
    log "Cleaning stage: $STAGE"
    rm -rf "$STAGE"
    mkdir -p "$LARAVEL_STAGE" "$DOCROOT_STAGE"

    log "Copying backend-php → stage (excluding vendor, .env, logs, tests)…"
    rsync -a \
        --exclude='.env' \
        --exclude='vendor/' \
        --exclude='node_modules/' \
        --exclude='.git/' \
        --exclude='storage/logs/*' \
        --exclude='storage/framework/cache/data/*' \
        --exclude='storage/framework/sessions/*' \
        --exclude='storage/framework/views/*' \
        --exclude='bootstrap/cache/*.php' \
        --exclude='tests/' \
        --exclude='.phpunit*' \
        --exclude='deploy/' \
        "$REPO_ROOT/backend-php/" "$LARAVEL_STAGE/"

    log "composer install --no-dev --optimize-autoloader…"
    ( cd "$LARAVEL_STAGE" && \
      composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist 2>&1 \
      | tail -8 )

    log "Resolving APP_KEY…"
    APP_KEY="${DEPLOY_APP_KEY:-}"
    if [[ -z "$APP_KEY" ]]; then
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        warn "Generated a NEW APP_KEY. To re-use it on future deploys, append this line"
        warn "to .deploy-secrets so sessions/encrypted cookies survive:"
        warn "  DEPLOY_APP_KEY='$APP_KEY'"
    else
        log "Re-using DEPLOY_APP_KEY from .deploy-secrets."
    fi

    log "Writing .env (production values from .deploy-secrets)…"
    # printf is literal — special chars (`+`, `]`, `|`, `\`) in passwords
    # survive unescaped, which is what Laravel's dotenv parser wants when
    # the value has no surrounding double quotes.
    {
        printf 'APP_NAME=Lodenica\n'
        printf 'APP_ENV=production\n'
        printf 'APP_KEY=%s\n' "$APP_KEY"
        printf 'APP_DEBUG=false\n'
        printf 'APP_TIMEZONE=UTC\n'
        printf 'APP_URL=https://%s\n' "$PROD_DOMAIN"
        printf '\n'
        printf 'APP_LOCALE=sk\n'
        printf 'APP_FALLBACK_LOCALE=en\n'
        printf 'APP_FAKER_LOCALE=sk_SK\n'
        printf '\n'
        printf 'LOG_CHANNEL=stack\n'
        printf 'LOG_STACK=single\n'
        printf 'LOG_LEVEL=warning\n'
        printf '\n'
        printf 'DB_CONNECTION=pgsql\n'
        printf 'DB_HOST=%s\n' "$PROD_DB_HOST"
        printf 'DB_PORT=%s\n' "$PROD_DB_PORT"
        printf 'DB_DATABASE=%s\n' "$PROD_DB_NAME"
        printf 'DB_USERNAME=%s\n' "$PROD_DB_USER"
        printf 'DB_PASSWORD=%s\n' "$PROD_DB_PASSWORD"
        printf 'DB_SSLMODE=prefer\n'
        printf '\n'
        printf 'CORS_ALLOWED_ORIGINS=https://%s\n' "$PROD_DOMAIN"
        printf '\n'
        printf 'SESSION_DRIVER=array\n'
        printf 'QUEUE_CONNECTION=sync\n'
        printf 'CACHE_STORE=file\n'
        printf 'BROADCAST_CONNECTION=log\n'
        printf 'FILESYSTEM_DISK=local\n'
    } > "$LARAVEL_STAGE/.env"
    chmod 600 "$LARAVEL_STAGE/.env"

    log "Building Vue SPA with VITE_API_BASE_URL=https://$PROD_DOMAIN/api/v1…"
    ( cd "$REPO_ROOT/frontend" && \
      VITE_API_BASE_URL="https://$PROD_DOMAIN/api/v1" pnpm build 2>&1 | tail -5 )

    log "Staging docroot (SPA + Laravel bootstrap + install.php)…"
    cp -r "$REPO_ROOT/frontend/dist/." "$DOCROOT_STAGE/"
    # All-in-docroot layout: Laravel app lives at ./laravel (inside the
    # docroot, web-denied via .htaccess). For SSH deploys with Laravel in
    # a sibling directory, use deploy/websupport-docroot/ instead.
    cp "$REPO_ROOT/backend-php/deploy/rezervacie-docroot/index.php"  "$DOCROOT_STAGE/"
    cp "$REPO_ROOT/backend-php/deploy/rezervacie-docroot/.htaccess"  "$DOCROOT_STAGE/"
    # Second-line-of-defence .htaccess inside the Laravel app — uploaded
    # as part of the LARAVEL_STAGE so `mirror -R` puts it at /laravel/.htaccess.
    cp "$REPO_ROOT/backend-php/deploy/rezervacie-docroot/laravel-deny.htaccess" \
       "$LARAVEL_STAGE/.htaccess"

    # Generate a fresh install token each deploy. Persisted to the stage so
    # both the install.php and the curl trigger see the same value.
    INSTALL_TOKEN="$(php -r 'echo bin2hex(random_bytes(16));')"
    echo "$INSTALL_TOKEN" > "$STAGE/.install-token"
    # Substitute the install-token AND the relative path the Laravel app
    # lives at (relative to the docroot where install.php is uploaded).
    # All-in-docroot layout puts Laravel at ./laravel.
    sed -e "s|__TOKEN__|$INSTALL_TOKEN|g" \
        -e "s|__LARAVEL_PATH__|/laravel|g" \
        "$REPO_ROOT/backend-php/deploy/install.php.template" \
        > "$DOCROOT_STAGE/install.php"

    log "Stage ready:"
    log "  Laravel app  → $LARAVEL_STAGE  ($(du -sh "$LARAVEL_STAGE" | cut -f1))"
    log "  docroot      → $DOCROOT_STAGE  ($(du -sh "$DOCROOT_STAGE" | cut -f1))"
    log "  install URL  → https://$PROD_DOMAIN/install.php?token=$INSTALL_TOKEN"
else
    log "Skipping build stage (--no-build); reusing existing $STAGE"
    [[ -d "$LARAVEL_STAGE" && -d "$DOCROOT_STAGE" ]] || die "Stage missing; rerun without --no-build."
fi

INSTALL_TOKEN="$(cat "$STAGE/.install-token" 2>/dev/null || true)"

#────────────────────────────────────────────────────────────────────────────
# Stage 2 — upload via SFTP (lftp mirror)
#────────────────────────────────────────────────────────────────────────────
if (( DO_UPLOAD )); then
    log "Uploading Laravel app  → sftp://$DEPLOY_SFTP_HOST:$DEPLOY_SFTP_PORT$DEPLOY_LARAVEL_APP_REMOTE"
    log "Uploading docroot      → sftp://$DEPLOY_SFTP_HOST:$DEPLOY_SFTP_PORT$DEPLOY_DOCROOT_REMOTE"

    # `mirror -R --delete --parallel` is rsync-like for SFTP. Settings:
    #   set sftp:auto-confirm yes      — accept host key on first connect
    #   set ssl:verify-certificate no  — defensive; ssh has its own keys
    #   --exclude-glob 'storage/logs/*' — leave server-side logs alone
    # Errors abort the whole script via `exit 1` in lftp.
    lftp -u "$DEPLOY_SFTP_USER,$DEPLOY_SFTP_PASSWORD" -p "$DEPLOY_SFTP_PORT" \
        "sftp://$DEPLOY_SFTP_HOST" <<LFTP
set sftp:auto-confirm yes
set net:max-retries 3
set net:timeout 30
set net:reconnect-interval-base 5
set xfer:clobber yes
set mirror:parallel-transfer-count 4

# 1. Laravel app — uploaded to /laravel/ (inside docroot, denied via .htaccess).
# Protects server-side .env, storage/* and bootstrap cache from --delete.
mkdir -fp $DEPLOY_LARAVEL_APP_REMOTE
mirror -R --delete --verbose=1 \
    --exclude-glob '.env' \
    --exclude-glob 'storage/logs/*' \
    --exclude-glob 'storage/framework/cache/data/*' \
    --exclude-glob 'storage/framework/sessions/*' \
    --exclude-glob 'storage/framework/views/*' \
    --exclude-glob 'storage/app/damages/*' \
    --exclude-glob 'storage/app/public/*' \
    --exclude-glob 'bootstrap/cache/*.php' \
    $LARAVEL_STAGE/ $DEPLOY_LARAVEL_APP_REMOTE/

# Upload .env separately (mirror's --exclude protects the live one from --delete
# AND from being overwritten, so we explicitly push the regenerated one here).
put -O $DEPLOY_LARAVEL_APP_REMOTE/ $LARAVEL_STAGE/.env

# 2. Docroot — frontend dist + Laravel bootstrap + install.php.
# --delete cleans stale Vue chunks but we explicitly preserve:
#   • the Laravel app dir (already uploaded under /laravel/)
#   • install.php (token gets a fresh value each deploy; pushed below)
#   • logo.jpg or any other static files the hosting put there manually
mkdir -fp $DEPLOY_DOCROOT_REMOTE
mirror -R --delete --verbose=1 \
    --exclude-glob 'laravel' \
    --exclude-glob 'laravel/*' \
    --exclude-glob 'install.php' \
    --exclude-glob 'logo.jpg' \
    $DOCROOT_STAGE/ $DEPLOY_DOCROOT_REMOTE/
put -O $DEPLOY_DOCROOT_REMOTE/ $DOCROOT_STAGE/install.php

bye
LFTP
    log "Upload complete."
else
    log "Skipping upload (--no-upload)."
fi

#────────────────────────────────────────────────────────────────────────────
# Stage 3 — trigger install.php over HTTPS
#────────────────────────────────────────────────────────────────────────────
if (( DO_INSTALL )); then
    [[ -n "$INSTALL_TOKEN" ]] || die "No install token recorded; run with --no-upload and at least --no-build off."
    URL="https://$PROD_DOMAIN/install.php?token=$INSTALL_TOKEN&import=$DO_IMPORT"
    if (( DO_IMPORT )); then
        warn "--import-sheet is set. This will WIPE damages, reservations, events"
        warn "and resources on the live DB, then re-import from the Google Sheet."
        warn "Ctrl-C in the next 5 seconds to abort."
        sleep 5
    fi
    log "Triggering installer: $URL"
    # 90s budget: lodenica:import-sheet fetches the sheet + inserts ~76 rows.
    if ! curl -sk --max-time 120 --fail-with-body "$URL"; then
        die "install.php returned non-2xx. Re-run with --no-build --no-upload after fixing."
    fi
    log "Installer ran cleanly + self-deleted."
fi

#────────────────────────────────────────────────────────────────────────────
# Stage 4 — smoke tests
#────────────────────────────────────────────────────────────────────────────
if (( DO_SMOKE )); then
    log "Smoke tests…"
    curl -sk -o /tmp/lodenica-smoke.json -w '  /health                 → HTTP %{http_code}\n' "https://$PROD_DOMAIN/health"
    curl -sk -o /tmp/lodenica-smoke.json -w '  /api/v1/resources       → HTTP %{http_code}\n' "https://$PROD_DOMAIN/api/v1/resources?pageSize=1"
    if php -r "exit(json_decode(file_get_contents('/tmp/lodenica-smoke.json'))->total > 0 ? 0 : 1);" 2>/dev/null; then
        TOTAL=$(php -r "echo json_decode(file_get_contents('/tmp/lodenica-smoke.json'))->total;")
        log "  → $TOTAL resources reachable."
    fi
    curl -sk -o /dev/null -w '  / (SPA)                  → HTTP %{http_code}\n' "https://$PROD_DOMAIN/"
fi

log "Deploy finished. Open https://$PROD_DOMAIN/ to verify."
