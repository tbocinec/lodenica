#!/usr/bin/env bash
#
# One-shot redeploy of the TEST instance to the renamed domain
# (test-rezervacie.lodenicakvs.sk) + VERIFY the SPA actually calls the right
# API domain. Fixes the "Network Error" caused by the deploy's parallel SFTP
# mirror silently skipping the freshly-built SPA assets.
#
# RUN WITH VPN OFF (port 22 must be reachable). Safe to re-run.
#
#   scripts/redeploy-test.sh
#
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

SECRETS=".deploy-secrets.test"
STAGE="${LODENICA_DEPLOY_STAGE:-/tmp/lodenica-rezervacie-deploy}"

bold() { printf '\033[1m%s\033[0m\n' "$*"; }

# ── Preflight: is port 22 reachable? (VPN usually blocks it) ────────────────
bold "==> Kontrola portu 22 (SFTP)…"
if ! timeout 8 bash -c "cat < /dev/null > /dev/tcp/lodenicakvs.sk/22" 2>/dev/null; then
  echo "‼  Port 22 je nedostupný — je VPN naozaj vypnutá? Vypni VPN a spusti script znova."
  exit 1
fi
echo "   OK, port 22 je priechodný."

# ── Full deploy: rebuild SPA (new domain baked in), rewrite .env, upload, ───
# ── migrate (noop), cache. Don't abort on its exit code — we verify below. ──
bold "==> Spúšťam plný deploy na test…"
scripts/deploy-rezervacie.sh --secrets "$SECRETS" || echo "‼  Deploy skript skončil s chybou — pokračujem overením + opravou."

# Load secrets for verification + the serial fallback upload.
set -a; . "./$SECRETS"; set +a

served_chunk() { curl -sk "https://$PROD_DOMAIN/" | grep -oE 'index-[A-Za-z0-9_-]+\.js' | head -1; }
spa_ok() {
  local js; js="$(served_chunk)"
  [ -n "$js" ] || return 1
  curl -sk "https://$PROD_DOMAIN/assets/$js" | grep -q "https://$PROD_DOMAIN/api/v1"
}

# ── Verify the served SPA points at the new API domain; if not, re-upload ──
# ── the docroot SERIALLY (parallel=1) which doesn't suffer the skip. ───────
bold "==> Overujem, že SPA volá https://$PROD_DOMAIN/api/v1…"
for attempt in 1 2 3 4; do
  if spa_ok; then
    echo "   ✅ SPA odkazuje na správnu doménu (chunk $(served_chunk))."
    break
  fi
  if [ "$attempt" = 4 ]; then
    echo "   ‼  SPA stále odkazuje na zlú doménu ani po 3 re-uploadoch."
    break
  fi
  echo "   ⚠  Zlá/stará doména — sériový re-upload docrootu (pokus $attempt)…"
  lftp -u "$DEPLOY_SFTP_USER,$DEPLOY_SFTP_PASSWORD" -p "${DEPLOY_SFTP_PORT:-22}" "sftp://$DEPLOY_SFTP_HOST" <<LFTP
set sftp:auto-confirm yes
set net:max-retries 3
set net:timeout 30
set mirror:parallel-transfer-count 1
set xfer:clobber yes
mirror -R --delete --verbose=1 \
    --exclude-glob 'laravel' \
    --exclude-glob 'laravel/*' \
    --exclude-glob 'install.php' \
    --exclude-glob 'logo.jpg' \
    $STAGE/docroot/ $DEPLOY_DOCROOT_REMOTE/
bye
LFTP
done

# ── Final smoke ─────────────────────────────────────────────────────────────
bold "==> Smoke testy na https://$PROD_DOMAIN …"
curl -sk -o /dev/null -w '   /health            HTTP %{http_code}\n' "https://$PROD_DOMAIN/health"
curl -sk -o /dev/null -w '   / (SPA)            HTTP %{http_code}\n' "https://$PROD_DOMAIN/"
curl -sk -o /dev/null -w '   /api/v1/resources  HTTP %{http_code}\n' "https://$PROD_DOMAIN/api/v1/resources?pageSize=1"
LOGIN=$(curl -sk -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d '{"email":"x@y.z","password":"nope"}' "https://$PROD_DOMAIN/api/v1/auth/login")
echo "   login endpoint     HTTP $LOGIN  (401 = OK, endpoint žije)"
PROVIDERS=$(curl -sk "https://$PROD_DOMAIN/api/v1/auth/providers")
if echo "$PROVIDERS" | grep -q '"google"'; then
  echo "   OAuth: ✅ Google je AKTÍVNY (tlačidlo „Prihlásiť cez Google“ sa zobrazí)"
else
  echo "   OAuth: Google sa nenačítal ako aktívny → $PROVIDERS"
fi

echo
if spa_ok; then
  bold "✅ HOTOVO."
  echo "   Otvor https://$PROD_DOMAIN/ a prihlás sa — Network Error má byť preč."
  echo "   Ak v prehliadači stále vidíš starú verziu: hard refresh (Ctrl/Cmd+Shift+R)."
else
  bold "‼  SPA stále nie je správna."
  echo "   Zapni VPN späť, pripoj sa ku Claude a pošli mi výstup tohto scriptu."
fi
