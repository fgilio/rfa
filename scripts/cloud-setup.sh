#!/usr/bin/env bash
set -euo pipefail

log() {
  printf "\n==> %s\n" "$*"
}

warn() {
  printf "Warning: %s\n" "$*" >&2
}

resolve_project_dir() {
  local candidates=(
    "${RFA_PROJECT_DIR:-}"
    "${CLAUDE_PROJECT_DIR:-}"
    "${CODEX_PROJECT_DIR:-}"
    "${CODEX_WORKSPACE:-}"
    "${GITHUB_WORKSPACE:-}"
    "$PWD"
    "/workspace/rfa"
  )

  local candidate
  for candidate in "${candidates[@]}"; do
    if [ -n "$candidate" ] \
      && [ -f "$candidate/composer.json" ] \
      && grep -Eq '"name"[[:space:]]*:[[:space:]]*"fgilio/rfa"' "$candidate/composer.json"; then
      printf "%s\n" "$candidate"
      return 0
    fi
  done

  warn "Unable to find the RFA project directory."
  exit 1
}

install_lefthook() {
  if command -v lefthook >/dev/null 2>&1; then
    log "Installing Lefthook hooks..."
    lefthook install
    return
  fi

  warn "lefthook not found. Git hooks will not run in this environment."
}

install_composer_dependencies() {
  if [ ! -f composer.json ]; then
    return
  fi

  export COMPOSER_ALLOW_SUPERUSER="${COMPOSER_ALLOW_SUPERUSER:-1}"

  # Composer plugins are skipped when Composer runs as root unless
  # COMPOSER_ALLOW_SUPERUSER is set. Missing pest-plugins.json breaks Pest
  # browser plugin discovery with opaque browser test failures.
  if [ ! -f vendor/autoload.php ]; then
    log "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --no-progress
  fi

  if [ -f vendor/autoload.php ] && [ ! -f vendor/pest-plugins.json ]; then
    log "Regenerating Composer autoload files..."
    composer dump-autoload --no-interaction
  fi
}

prepare_laravel_environment() {
  if [ ! -f artisan ]; then
    return
  fi

  # Cloud sessions should land on a runnable Laravel app, not just an
  # installed dependency tree. Local hooks opt out with RFA_CLOUD_SETUP_SKIP_DEPS.
  if [ ! -f .env ] && [ -f .env.example ]; then
    log "Creating .env..."
    cp .env.example .env
  fi

  if [ -f .env ] && ! grep -Eq '^APP_KEY=.+$' .env; then
    log "Generating application key..."
    php artisan key:generate --no-interaction
  fi

  if [ -d database ]; then
    touch database/database.sqlite

    log "Running database migrations..."
    php artisan migrate --force --no-interaction
  fi
}

install_node_dependencies() {
  if [ ! -f package.json ]; then
    return
  fi

  # npm install is intentional here: it heals stale cached node_modules without
  # the full wipe that npm ci performs on every cloud session.
  log "Installing npm dependencies..."
  npm install --no-audit --no-fund
}

install_playwright_browser() {
  if [ ! -x node_modules/.bin/playwright ]; then
    return
  fi

  if ! node_modules/.bin/playwright --version >/dev/null 2>&1; then
    return
  fi

  log "Ensuring Playwright Chromium is installed..."

  # PLAYWRIGHT_BROWSERS_PATH may point at a pre-seeded cache; Playwright installs
  # the matching revision alongside it when the lockfile needs a newer browser.
  if [ "${RFA_PLAYWRIGHT_WITH_DEPS:-}" = "1" ]; then
    npx --no-install playwright install --with-deps chromium
    return
  fi

  npx --no-install playwright install chromium
}

project_dir="$(resolve_project_dir)"
cd "$project_dir"

log "Preparing RFA in $project_dir..."

install_lefthook

if [ "${RFA_CLOUD_SETUP_SKIP_DEPS:-}" = "1" ]; then
  exit 0
fi

install_composer_dependencies
prepare_laravel_environment
install_node_dependencies
install_playwright_browser

log "Cloud setup complete."
