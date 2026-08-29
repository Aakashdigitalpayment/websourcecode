#!/usr/bin/env bash
# Run every smoke-*.php script (local CI parity).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
FAIL=0
for f in scripts/smoke-*.php; do
  echo "=== ${f} ==="
  if ! php "$f"; then
    FAIL=1
  fi
  echo ""
done
exit "$FAIL"
