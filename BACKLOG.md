# Backlog

Items deferred from the open-source readiness audit. To be addressed in future iterations.

## ~~livewire/flux - Adopt instead of removing~~ DONE
Flux UI adopted across all 4 Blade templates: heading, text, badge, button, icon, textarea, tooltip, card, and toast. Custom diff table/CSS preserved intentionally.

## Analytics - Implement or remove ANALYTICS.md
ANALYTICS.md references `analytics.jsonl` tracking but no code exists. Either implement usage tracking or remove the file.

## Configurable keyboard shortcuts
Currently hardcoded `Cmd+Enter` / `Ctrl+Enter`. Make shortcuts configurable, possibly via Alpine.js data or a config file.

## Defensive guards for edge cases
- Guard against large untracked files (skip files > 1MB in GitDiffService)
- Handle initial-commit repos (no HEAD)
- Surface git errors to UI instead of silent "No changes detected"
- Handle file paths with spaces in synthetic diffs
- Size limit on total diff output to prevent OOM / browser crash


## Code highlighting
- Implement syntax highlighting for code snippets in comments, check in flux has an official way of doing this.
