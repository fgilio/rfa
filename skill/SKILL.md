---
name: rfa
description: Local code review tool for AI agent changes. Opens a browser diff viewer, exports structured JSON + Markdown for agents.
user-invocable: true
disable-model-invocation: true
---

# rfa - Review For Agent

Local code review tool for AI agent changes.

## What it does

Run `rfa` in any git repo with uncommitted changes. Opens a browser with a GitHub-style diff viewer where you can add inline comments. Exports structured JSON + Markdown that AI agents can consume.

## Usage

```bash
cd ~/my-project
rfa
```

## Output

After submitting a review, creates:
- `.rfa/{timestamp}_comments_{hash}.json` - Structured comment data
- `.rfa/{timestamp}_comments_{hash}.md` - Agent-friendly markdown with diff context

Copies to clipboard: `review my comments on these changes in @.rfa/{timestamp}_comments_{hash}.md`

## Features

- Unified diff view with GitHub-style coloring
- Click line numbers to add inline comments
- Shift+click for range selection
- File sidebar with +/- stats
- Global review comment
- `.rfaignore` support for excluding files
- Auto-excludes lock files

## Requirements

- PHP 8.3+ (via Herd)
- Composer dependencies installed (see SETUP.md)
