#!/usr/bin/env bash
#
# Roll the current checkout out to EVERY client: one deploy per
# .deploy-secrets.<slug> file. `.deploy-secrets.example` and
# `.deploy-secrets.test` are skipped unless named with --only. Extra flags
# are passed through to scripts/deploy-rezervacie.sh (e.g. --no-build is
# NOT a good idea here — every client builds with its own domain).
# Stops at the first failing client so a broken release never reaches the
# rest.
#
#   scripts/deploy-all.sh                    # all clients, asks first
#   scripts/deploy-all.sh --yes              # no confirmation (CI)
#   scripts/deploy-all.sh --only kvs,klub2   # a subset, by slug
#   scripts/deploy-all.sh --no-smoke         # pass-through flag
#
# Run with VPN OFF (port 22 must be reachable), like the single deploy.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

YES=0
ONLY=""
PASS=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes|-y) YES=1 ;;
        --only)   shift; [[ -n "${1:-}" ]] || { echo "--only needs a comma-separated list of slugs" >&2; exit 2; }; ONLY="$1" ;;
        --only=*) ONLY="${1#--only=}" ;;
        -h|--help) sed -n '2,/^set -euo/p' "$0" | grep '^#' | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)        PASS+=("$1") ;;
    esac
    shift
done

FILES=()
if [[ -n "$ONLY" ]]; then
    IFS=, read -ra SLUGS <<< "$ONLY"
    for slug in "${SLUGS[@]}"; do
        FILES+=(".deploy-secrets.$slug")
    done
else
    for f in .deploy-secrets.*; do
        [[ -e "$f" ]] || continue
        case "$f" in *.example|*.test) continue ;; esac
        FILES+=("$f")
    done
fi

if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "No .deploy-secrets.<slug> files found. Create one per client (see docs/CLIENT-ONBOARDING.md)." >&2
    exit 1
fi

echo "Clients to deploy:"
for f in "${FILES[@]}"; do
    [[ -r "$f" ]] || { echo "  $f — MISSING" >&2; exit 1; }
    domain=$(grep -E '^PROD_DOMAIN=' "$f" | tail -1 | cut -d= -f2- | tr -d "'\"")
    printf '  %-28s %s\n' "$f" "${domain:-?}"
done

if (( ! YES )); then
    read -r -p "Continue? [y/N] " answer
    [[ "$answer" == y || "$answer" == Y ]] || { echo "Aborted."; exit 1; }
fi

for f in "${FILES[@]}"; do
    echo
    echo "════════════════════════════════════════════════════════════"
    echo "  $f"
    echo "════════════════════════════════════════════════════════════"
    scripts/deploy-rezervacie.sh --secrets "$f" "${PASS[@]+"${PASS[@]}"}"
done

echo
echo "All clients deployed."
