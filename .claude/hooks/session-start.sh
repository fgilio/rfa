#!/usr/bin/env bash
set -euo pipefail

cd "$CLAUDE_PROJECT_DIR"

# Dependency setup writes to STDERR on purpose: this hook's STDOUT is reserved
# for the single JSON control line that scripts/sync-org-skills.sh emits
# (reloadSkills). Mixed human text + JSON on STDOUT is treated as raw context
# and the reload flag would be silently ignored.
#
# The setup call is non-fatal (|| log): under restricted egress `composer
# install` / `npm install` can fail deterministically and `cloud-setup.sh`
# exits non-zero. Without the guard, this hook's `set -e` would abort here and
# the skills sync below would never run. Setup and sync are independent; a setup
# failure must not suppress the sync.
{
  # Local sessions are expected to manage their own dependencies, but still
  # benefit from Lefthook being installed by the shared setup script.
  if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    RFA_CLOUD_SETUP_SKIP_DEPS=1 bash "$CLAUDE_PROJECT_DIR/scripts/cloud-setup.sh" \
      || echo "cloud-setup.sh failed (continuing to skills sync)" >&2
  else
    bash "$CLAUDE_PROJECT_DIR/scripts/cloud-setup.sh" \
      || echo "cloud-setup.sh failed (continuing to skills sync)" >&2
  fi
} 1>&2

# Sync org-wide skills from the plugin marketplace. Soft-fails internally and
# only writes the reloadSkills JSON to STDOUT, so it can never break the hook.
bash "$CLAUDE_PROJECT_DIR/scripts/sync-org-skills.sh" || true
