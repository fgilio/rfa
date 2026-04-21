#!/bin/bash
set -euo pipefail

cd "$CLAUDE_PROJECT_DIR"

# ── Lefthook ──────────────────────────────────────────────────────
if command -v lefthook >/dev/null 2>&1; then
  lefthook install
else
  echo "Warning: lefthook not found. Git hooks (pre-commit lint, pre-push types) will not run."
fi

# ── Bootstrap (remote sessions only) ─────────────────────────────
# Local sessions are expected to manage their own deps. In Claude Code on
# the web the container starts empty, so ensure composer/npm/playwright
# are ready before the agent runs anything.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# Composer-plugins (including pestphp/pest-plugin, whose post-autoload-dump
# hook writes vendor/pest-plugins.json that Pest needs to discover the
# browser plugin) are silently skipped when composer runs as root unless
# this is set. Missing pest-plugins.json makes every browser test fail with
# a bare "sendText() on null" because the WebSocket never opens.
export COMPOSER_ALLOW_SUPERUSER=1

if [ -f composer.json ]; then
  if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
  elif [ ! -f vendor/pest-plugins.json ]; then
    # vendor/ was cached from an install that ran without superuser; the
    # post-autoload hook was skipped. Regenerate to write the plugin file.
    composer dump-autoload --no-interaction
  fi
fi

if [ -f package.json ] && [ ! -d node_modules ]; then
  echo "Installing npm dependencies..."
  npm install
fi

# Install the chromium build that matches the installed Playwright. The
# cloud image pre-seeds PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers with an
# older chromium; Playwright will install the matching revision alongside
# it and skip the download on subsequent runs.
if [ -f node_modules/.bin/playwright ]; then
  echo "Ensuring Playwright chromium is installed..."
  npx --no-install playwright install chromium
fi
