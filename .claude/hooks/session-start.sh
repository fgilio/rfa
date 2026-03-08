#!/bin/bash
set -euo pipefail

cd "$CLAUDE_PROJECT_DIR"

# ── Lefthook ──────────────────────────────────────────────────────
if command -v lefthook >/dev/null 2>&1; then
  lefthook install
else
  echo "Warning: lefthook not found. Git hooks (pre-commit lint, pre-push types) will not run."
fi
