#!/usr/bin/env bash
# Safe git pull for live servers (handles local .htaccess customizations).
# Usage: bash scripts/deploy-pull-safe.sh [remote-branch]
# Example: bash scripts/deploy-pull-safe.sh main
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BRANCH="${1:-main}"
STASH_MSG="deploy-live-htaccess-$(date +%Y%m%d-%H%M%S)"
BACKUP=".htaccess.live-backup-$(date +%Y%m%d-%H%M%S)"

echo "==> Deploy pull safe (branch: ${BRANCH})"
echo "    Root: ${ROOT}"

if [[ -f .htaccess ]]; then
  cp .htaccess "$BACKUP"
  echo "==> Backed up .htaccess -> ${BACKUP}"
fi

HTACCESS_STASHED=0
if [[ -f .htaccess ]] && ! git diff --quiet -- .htaccess 2>/dev/null; then
  echo "==> Stashing local .htaccess changes..."
  git stash push -m "$STASH_MSG" -- .htaccess
  HTACCESS_STASHED=1
fi

echo "==> git pull origin ${BRANCH}"
git pull origin "$BRANCH"

if [[ "$HTACCESS_STASHED" -eq 1 ]]; then
  echo "==> Restoring stashed .htaccess (merge server rules with repo if conflict)..."
  if ! git stash pop; then
    echo ""
    echo "CONFLICT: Open ${BACKUP} and merge manually with repo .htaccess."
    echo "Keep repo security blocks + your server HTTPS/PHP rules."
    exit 1
  fi
fi

# One-time retired HRM module DB cleanup (no app code uses hrm_* tables anymore)
HRM_DROP_FLAG="$ROOT/cache/.hrm-tables-dropped"
if [[ ! -f "$HRM_DROP_FLAG" ]] && command -v php >/dev/null 2>&1; then
  set +e
  php scripts/drop-hrm-tables-safe.php --yes
  hrm_rc=$?
  set -e
  if [[ "$hrm_rc" -eq 0 ]]; then
    mkdir -p "$ROOT/cache"
    touch "$HRM_DROP_FLAG"
    echo "==> HRM tables dropped or already absent (one-time; flag: cache/.hrm-tables-dropped)"
  elif [[ "$hrm_rc" -eq 2 ]]; then
    echo "==> HRM table drop skipped (DB not configured on this host)"
  else
    echo "==> HRM table drop failed (non-fatal; check DB and re-run php scripts/drop-hrm-tables-safe.php --yes)"
  fi
fi

echo ""
echo "==> Pull complete."
echo "    Optional smoke: php scripts/smoke-security.php"
echo "    If CSS looks stale: python3 scripts/build-css-late-bundles.py (usually not needed on live pull)"
