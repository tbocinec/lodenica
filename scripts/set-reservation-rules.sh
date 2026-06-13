#!/usr/bin/env bash
#
# scripts/set-reservation-rules.sh
#
# Pushes the canonical reservation-rules HTML
# (backend-php/deploy/reservation-rules.html) into the live database
# at https://<domain>/api/v1/reservation-rules via the admin PATCH
# endpoint. Use it whenever you've edited the .html file in git and
# want the change reflected on a running instance — no SSH, no SFTP,
# just one HTTPS round-trip.
#
# Defaults:
#   --domain     rezervacie.lodenicakvs.sk
#   --email      admin@lodenica.sk
#   --password   Lodenica2026!         (override via env ADMIN_PASSWORD)
#   --file       backend-php/deploy/reservation-rules.html
#
# Usage:
#   scripts/set-reservation-rules.sh                              # prod
#   scripts/set-reservation-rules.sh --domain localhost:8000       # docker
#   ADMIN_PASSWORD='…' scripts/set-reservation-rules.sh
#
# The script intentionally has NO `set -e` until after argument parsing
# so a bad flag fails with a friendly usage hint instead of bash's
# generic "unbound variable" trace.

set -uo pipefail

# ─── Defaults ──────────────────────────────────────────────────────────
DOMAIN="rezervacie.lodenicakvs.sk"
EMAIL="${ADMIN_EMAIL:-admin@lodenica.sk}"
PASSWORD="${ADMIN_PASSWORD:-Lodenica2026!}"
SCHEME="https"
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
HTML_FILE="$SCRIPT_DIR/../backend-php/deploy/reservation-rules.html"

# ─── CLI parsing ───────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)   DOMAIN="$2"; shift 2 ;;
        --email)    EMAIL="$2"; shift 2 ;;
        --password) PASSWORD="$2"; shift 2 ;;
        --file)     HTML_FILE="$2"; shift 2 ;;
        --http)     SCHEME="http"; shift ;;
        -h|--help)
            sed -n '1,/^set -uo pipefail$/p' "$0" | grep '^#' | sed 's/^# *//'
            exit 0 ;;
        *) echo "Unknown flag: $1 (try --help)"; exit 2 ;;
    esac
done

# Use http for any localhost target to keep docker-compose dev sane.
if [[ "$DOMAIN" == localhost* || "$DOMAIN" == 127.0.0.1* ]]; then
    SCHEME="http"
fi
BASE="$SCHEME://$DOMAIN"

set -e

# ─── Sanity checks ─────────────────────────────────────────────────────
for cmd in curl jq; do
    command -v "$cmd" >/dev/null 2>&1 || { echo "❌ Missing required command: $cmd"; exit 1; }
done
[[ -f "$HTML_FILE" ]] || { echo "❌ File not found: $HTML_FILE"; exit 1; }

BYTES=$(wc -c < "$HTML_FILE")
echo "==> Source: $HTML_FILE ($BYTES bytes)"
echo "==> Target: $BASE/api/v1/reservation-rules"
echo "==> Admin:  $EMAIL"

# ─── 1. Login ──────────────────────────────────────────────────────────
echo "==> Logging in…"
LOGIN_BODY=$(jq -nc --arg email "$EMAIL" --arg password "$PASSWORD" \
    '{email: $email, password: $password}')

LOGIN_RESPONSE=$(curl -sS -X POST "$BASE/api/v1/auth/login" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    --data "$LOGIN_BODY")

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token // empty')
if [[ -z "$TOKEN" ]]; then
    echo "❌ Login failed. Response:"
    echo "$LOGIN_RESPONSE" | jq . 2>/dev/null || echo "$LOGIN_RESPONSE"
    exit 1
fi
echo "==> Got token: ${TOKEN:0:14}…"

# ─── 2. PATCH the rules ────────────────────────────────────────────────
# `jq -Rs '{content: .}'` reads the HTML file as a raw string and
# wraps it in {"content": "<html…>"} with proper JSON escaping of
# quotes, newlines, etc.
PATCH_BODY=$(jq -Rs '{content: .}' < "$HTML_FILE")

echo "==> Pushing…"
RESPONSE_FILE=$(mktemp)
trap 'rm -f "$RESPONSE_FILE"' EXIT

HTTP=$(curl -sS -o "$RESPONSE_FILE" -w '%{http_code}' \
    -X PATCH "$BASE/api/v1/reservation-rules" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    --data "$PATCH_BODY")

if [[ "$HTTP" == "200" ]]; then
    UPDATED=$(jq -r '.updatedAt // "?"' < "$RESPONSE_FILE")
    echo "✅ Rules updated on $BASE — server updatedAt: $UPDATED"
else
    echo "❌ PATCH failed (HTTP $HTTP). Response:"
    jq . < "$RESPONSE_FILE" 2>/dev/null || cat "$RESPONSE_FILE"
    exit 1
fi
