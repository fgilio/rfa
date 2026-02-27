# rfa - Review For Agent

Local code review tool for AI agent changes. Run `rfa` inside a git repo with uncommitted changes to open a browser diff UI, add comments, and export structured JSON + Markdown for agents.

## Quick Start

```bash
cd ~/dev/rfa
cd src && composer install
./install
```

Installs a symlink at `~/.local/bin/rfa`.

## Usage

```bash
cd ~/my-project
rfa
```

Default `rfa` flow:
- Validates current directory is a git repo
- Starts (or reuses) local daemon
- Registers current project
- Opens browser at `/p/{slug}` review page

Dashboard with registered projects is available at `/`.

## Daemon Commands

```bash
rfa status   # daemon status + registered projects
rfa stop     # stop daemon
rfa dump     # dump sqlite data to CSV
rfa flush    # delete all saved projects/sessions
```

## Output

After submit, exports to `.rfa/` in the reviewed repo:
- `.rfa/{timestamp}_comments_{hash}.json` - Structured comment data
- `.rfa/{timestamp}_comments_{hash}.md` - Agent-friendly markdown with diff context

Clipboard prompt (best effort browser copy):  
`review my comments on these changes in @.rfa/{timestamp}_comments_{hash}.md`

You may want to add `.rfa/` to your project's `.gitignore`.

## Features

- Unified diff view with GitHub-style coloring
- Click line numbers to add inline comments
- Shift+click for range selection
- File sidebar with +/- stats
- Global review comment
- Registered project dashboard
- Optional respect of global gitignore rules

## Ignore Rules

`rfa` reads `.rfaignore` from repo root for exclude patterns (including glob-style patterns).

This is exclude-focused matching, not full `.gitignore` parity.

Always excluded lock files:
- `package-lock.json`
- `pnpm-lock.yaml`
- `yarn.lock`
- `bun.lock`
- `composer.lock`

## Requirements

- PHP 8.3+
- Composer dependencies installed (`cd src && composer install`)
- git
- curl

Optional for `rfa dump` / `rfa flush`: `sqlite3`

Works on macOS and Linux.
