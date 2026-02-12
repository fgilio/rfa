# rfa - Review For Agent

Local code review tool for AI agent changes. Run `rfa` in any git repo with uncommitted changes - opens a browser with a GitHub-style diff viewer where you can add inline comments, then exports structured JSON + Markdown that AI agents can consume.

## Quick Start

```bash
cd ~/.claude/skills/rfa
./install
```

## Usage

```bash
cd ~/my-project
rfa
```

This creates a `.rfa/` directory in your repo with the exported review files. You may want to add `.rfa/` to your project's `.gitignore`.

## Output

After submitting a review, creates:
- `.rfa/comments_{hash}.json` - Structured comment data
- `.rfa/comments_{hash}.md` - Agent-friendly markdown with diff context

Copies to clipboard: `review my comments on these changes in @.rfa/comments_{hash}.md`

## Features

- Unified diff view with GitHub-style coloring
- Click line numbers to add inline comments
- Shift+click for range selection
- File sidebar with +/- stats
- Global review comment
- `.rfaignore` support for excluding files (same syntax as `.gitignore`)
- Auto-excludes lock files

## Requirements

- PHP 8.3+
- Composer dependencies installed (`cd src && composer install`)

Works on macOS and Linux.
