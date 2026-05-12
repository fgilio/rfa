# Routine setup: wide-events-audit

Configuration for the Claude.ai routine that runs
`.claude/skills/wide-events-audit/SKILL.md`. The human setting up the routine
reads this once. The runtime does not load this file at invocation.

## Routine config (Claude.ai UI)

- **Name:** `Wide Events Audit`
- **Repos:** `fgilio/rfa` (primary).
- **Trigger:** weekly. Choose the exact day and time in Claude.ai.
- **Connectors:** GitHub.
- **Env vars:** none.
- **Network:** Default.
- **Behavior:** Auto-fix pull requests on. The routine still opens human-gated
  PRs and must not enable auto-merge.

## Routine instructions

Single-line input for the Instructions field:

> Run the audit described at .claude/skills/wide-events-audit/SKILL.md
