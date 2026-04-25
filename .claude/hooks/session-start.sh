#!/usr/bin/env bash
set -euo pipefail

cd "$CLAUDE_PROJECT_DIR"

# Local sessions are expected to manage their own dependencies, but still
# benefit from Lefthook being installed by the shared setup script.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  RFA_CLOUD_SETUP_SKIP_DEPS=1 bash "$CLAUDE_PROJECT_DIR/scripts/cloud-setup.sh"
  exit 0
fi

bash "$CLAUDE_PROJECT_DIR/scripts/cloud-setup.sh"
