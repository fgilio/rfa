#!/usr/bin/env bash
#
# Sync org-wide Claude Code skills from a plugin marketplace at session start.
#
# Driven by a Claude Code plugin marketplace (default: fgilio/claude-plugins).
# For each allow-listed plugin we fetch its source repo and copy the skills it
# ships (skills/<name>/SKILL.md) into the skill-discovery root, so the skill is
# available in this session once the SessionStart hook emits `reloadSkills`.
#
# Two fetch transports, tried in order by fetch_repo():
#   1. `git clone --depth 1` (preferred).
#   2. The GitHub tarball endpoint over plain HTTPS, used when the clone is
#      blocked. Claude Code *web* runs behind an agent proxy that gates the git
#      smart-protocol to the session's repo allowlist, so cloning an org repo
#      that isn't the session's own repo returns 403 — but plain HTTPS reads to
#      api.github.com (which redirects to codeload) are still permitted. Without
#      this the sync silently no-ops in exactly the web containers it targets.
# Both transports land the repo's tree in one local dir, so a single copy loop
# handles either.
#
# Design notes / invariants:
#   - SOFT-FAIL. A skills sync must never break a session. This script always
#     exits 0, sends every human-readable line to STDERR, and reserves STDOUT
#     for the single `reloadSkills` control JSON that Claude Code parses.
#   - Skill discovery runs BEFORE SessionStart hooks finish, so skills written
#     here only become live this session via `reloadSkills: true`. See
#     https://code.claude.com/docs/en/hooks (SessionStart output fields).
#   - Destination differs by environment:
#       * Web (CLAUDE_CODE_REMOTE=true): the project skills dir, which lives in
#         the cloned working tree. Web containers do not persist or scan
#         ~/.claude, so user-level skills are not an option there.
#       * Local CLI/Desktop: ~/.claude/skills, so one fetch is shared across
#         every repo on the machine.
#   - 15-minute freshness window: if we synced recently, the skills are already
#     on disk and were discovered at startup, so we skip the network entirely.
#   - Trust boundary: ALLOWED_OWNERS gates which repos we fetch from — a skill
#     is instructions an agent follows.
#
# Tunables (env):
#   RFA_ORG_SKILLS_MARKETPLACE     GitHub "owner/repo" of the marketplace.
#   RFA_ORG_SKILLS_PLUGINS         Comma/space-separated plugin allowlist.
#   RFA_ORG_SKILLS_ALLOWED_OWNERS  Comma/space-separated trusted repo owners.
#   RFA_ORG_SKILLS_MAX_AGE         Freshness window in seconds.
#   RFA_ORG_SKILLS_DISABLE=1       Opt out entirely.

# NOT `set -e`: per-step failures (a flaky clone, a missing repo) must degrade
# gracefully, not abort the whole sync.
set -uo pipefail

MARKETPLACE_REPO="${RFA_ORG_SKILLS_MARKETPLACE:-fgilio/claude-plugins}"
PLUGIN_ALLOWLIST="${RFA_ORG_SKILLS_PLUGINS:-coding,polish,fgilio-review}"
MAX_AGE="${RFA_ORG_SKILLS_MAX_AGE:-900}"
ALLOWED_OWNERS="${RFA_ORG_SKILLS_ALLOWED_OWNERS:-fgilio,publicala}"

GH_API="https://api.github.com"

log() { printf '==> [org-skills] %s\n' "$*" >&2; }

emit_reload() {
  printf '{"hookSpecificOutput":{"hookEventName":"SessionStart","reloadSkills":true}}\n'
}

if [ "${RFA_ORG_SKILLS_DISABLE:-}" = "1" ]; then
  log "disabled via RFA_ORG_SKILLS_DISABLE; skipping"
  exit 0
fi

for tool in git jq curl tar; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    log "$tool is required; skipping"
    exit 0
  fi
done

project_dir="${CLAUDE_PROJECT_DIR:-$PWD}"

if [ "${CLAUDE_CODE_REMOTE:-}" = "true" ]; then
  skills_root="$project_dir/.claude/skills"
  cache_root="$project_dir/.claude/.skill-cache"
else
  skills_root="$HOME/.claude/skills"
  cache_root="$HOME/.claude/.skill-cache"
fi
marker="$cache_root/.last-sync"

mkdir -p "$skills_root" "$cache_root" 2>/dev/null || true

# --- Freshness window -------------------------------------------------------
now="$(date +%s 2>/dev/null || echo 0)"
last=0
if [ -f "$marker" ]; then
  last="$(cat "$marker" 2>/dev/null || echo 0)"
fi
case "$last" in '' | *[!0-9]*) last=0 ;; esac

if [ "$last" -gt 0 ] && [ "$((now - last))" -lt "$MAX_AGE" ]; then
  log "fresh (synced $((now - last))s ago); skipping fetch"
  # Skills written by an earlier run are already on disk and were discovered at
  # startup, so no reload is needed.
  exit 0
fi

# --- Fetch helpers ----------------------------------------------------------
# Cache path for a repo, namespaced by owner/repo so a different configured
# repo maps to a different dir instead of reusing a stale checkout.
repo_cache_dir() { printf '%s/%s' "$1" "$(printf '%s' "$2" | tr '/' '_')"; }

# Materialize a repo's tree into $2. Tries git clone, then the HTTPS tarball
# endpoint (see header). On success $2 contains the repo's files (skills/, etc).
fetch_repo() { # $1=owner/repo  $2=dest
  local url="https://github.com/$1.git"
  if [ -d "$2/.git" ]; then
    git -C "$2" pull --quiet --ff-only 2>/dev/null && return 0
  fi
  rm -rf "$2" 2>/dev/null || true
  if git clone --depth 1 --quiet "$url" "$2" 2>/dev/null; then
    return 0
  fi
  log "$1: git fetch blocked (repo scope?) — trying HTTPS tarball"
  rm -rf "$2" 2>/dev/null || true
  mkdir -p "$2" 2>/dev/null || true
  curl -fsSL --retry 2 --max-time 60 -H "Accept: application/vnd.github+json" \
    "$GH_API/repos/$1/tarball/HEAD" 2>/dev/null \
    | tar -xz -C "$2" --strip-components=1 2>/dev/null
}

# Copy each skills/<name>/ that contains a SKILL.md from a fetched repo dir
# ($1) into the discovery root. Increments the global `synced`.
install_skills_from() { # $1=repo-dir  $2=owner/repo (for logs)
  local sk name
  if [ ! -d "$1/skills" ]; then
    log "$2 has no skills/ dir (skipping)"
    return
  fi
  for sk in "$1/skills"/*/; do
    [ -d "$sk" ] || continue
    [ -f "$sk/SKILL.md" ] || continue
    name="$(basename "$sk")"
    rm -rf "${skills_root:?}/$name" 2>/dev/null || true
    if cp -R "$sk" "$skills_root/$name" 2>/dev/null; then
      synced=$((synced + 1))
      log "installed skill: $name (from $2)"
    fi
  done
}

# --- Fetch the marketplace --------------------------------------------------
log "syncing skills from $MARKETPLACE_REPO (plugins: $PLUGIN_ALLOWLIST)"
mp_clone="$(repo_cache_dir "$cache_root/marketplace" "$MARKETPLACE_REPO")"
mp_json="$mp_clone/.claude-plugin/marketplace.json"
if ! fetch_repo "$MARKETPLACE_REPO" "$mp_clone" || [ ! -f "$mp_json" ]; then
  log "marketplace unavailable over git and HTTPS; leaving existing skills as-is"
  [ -n "$(ls -A "$skills_root" 2>/dev/null)" ] && emit_reload
  exit 0
fi

# Normalize the allowlist (commas or spaces) to a comma-delimited string for jq.
allow_csv="$(printf '%s' "$PLUGIN_ALLOWLIST" | tr ' ' ',')"

plugin_repos="$(
  jq -r --arg names "$allow_csv" '
    ($names | split(",") | map(select(length > 0))) as $allow
    | .plugins[]?
    | . as $p
    | select($allow | index($p.name))
    | select($p.source.source == "github")
    | $p.source.repo
  ' "$mp_json" 2>/dev/null || true
)"

if [ -z "$plugin_repos" ]; then
  log "no matching github plugins in marketplace"
  exit 0
fi

# --- Sync each plugin's skills into the discovery root ----------------------
owners_csv=",$(printf '%s' "$ALLOWED_OWNERS" | tr ' ' ',')," # ",fgilio,publicala,"
synced=0
while IFS= read -r repo; do
  [ -n "$repo" ] || continue
  owner="${repo%%/*}"
  case "$owners_csv" in
    *",$owner,"*) : ;;
    *) log "refusing $repo: owner '$owner' not in allowlist ($ALLOWED_OWNERS)"; continue ;;
  esac
  dest="$(repo_cache_dir "$cache_root/plugins" "$repo")"
  if fetch_repo "$repo" "$dest"; then
    install_skills_from "$dest" "$repo"
  else
    log "plugin fetch failed: $repo (git + https); skipping"
  fi
done <<EOF
$plugin_repos
EOF

if [ "$synced" -gt 0 ]; then
  printf '%s' "$now" >"$marker" 2>/dev/null || true
  log "synced $synced skill(s) into $skills_root"
  emit_reload
else
  log "no skills synced"
  [ -n "$(ls -A "$skills_root" 2>/dev/null)" ] && emit_reload
fi

exit 0
