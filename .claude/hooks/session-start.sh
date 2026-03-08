#!/bin/bash
set -euo pipefail

# Only run in remote (web) environments
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

# ── PHP Dependencies ──────────────────────────────────────────────
echo "Installing PHP dependencies..."
cd src
composer install --no-interaction --quiet
cd "$CLAUDE_PROJECT_DIR"

# ── Laravel Setup ─────────────────────────────────────────────────
echo "Setting up Laravel environment..."
cd src
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --quiet
fi
php artisan migrate --force --quiet
cd "$CLAUDE_PROJECT_DIR"

# ── Lefthook ──────────────────────────────────────────────────────
if command -v lefthook >/dev/null 2>&1; then
  echo "Installing lefthook git hooks..."
  lefthook install
else
  echo "Warning: lefthook not found in PATH. Git hooks (pre-commit lint, pre-push types) will not run."
  echo "Install lefthook to enable: https://github.com/evilmartians/lefthook"
fi

echo "Session setup complete."
