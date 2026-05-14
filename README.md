# rfa - Review For Agent

macOS (Apple Silicon) desktop app for reviewing AI agent code changes. Open any local git repo, browse diffs with syntax highlighting, add inline comments, and export structured feedback that agents can consume directly.

## Install

Download the latest `.dmg` from [GitHub Releases](https://github.com/fgilio/rfa/releases) and drag to Applications.

Builds are currently unsigned. After installing, remove the quarantine attribute before first launch:

```bash
xattr -cr /Applications/rfa.app
```

Auto-updates work normally after that (handled by Electron, independent of code signing).

## Quickstart

1. Open a git repo with `Cmd+O`
2. Browse diffs, click line numbers to add inline comments
3. Submit to export review files to `.rfa/` in the reviewed repo

The exported `.md` file is ready to paste into AI tools:

```
address my comments on these changes in /absolute/path/to/repo/.rfa/{timestamp}_comments_{hash}.md
```

## Terminal Helper

The repo includes a `rfa` shell script that opens repos via deep link (not bundled in the `.dmg`):

```bash
# Symlink to PATH
ln -s ~/dev/rfa/rfa ~/.local/bin/rfa

# Then from any git repo:
rfa
```

This launches the app (or focuses it if running) and opens the repo.

## Features

- Unified diff view with syntax highlighting (Phiki)
- Inline comments on any changed line; shift+click for range selection
- Global review comment
- Commit history browser
- File sidebar with change stats and grouping
- Discard/restore individual file changes
- Session persistence (comments, viewed files, viewport)
- Export to `.rfa/` in the reviewed repo:
  - `{timestamp}_comments_{hash}.json` (structured data for automation)
  - `{timestamp}_comments_{hash}.md` (markdown with full diff context for agents)

RFA automatically adds `.rfa/` to your repo's `.git/info/exclude` on first export, so review files won't appear in `git status`.

## Ignore Rules

Place a `.rfaignore` file in your repo root to exclude files from the diff view. One pattern per line, glob-style:

```
*.generated.ts
docs/
vendor/
```

Always excluded: `package-lock.json`, `pnpm-lock.yaml`, `yarn.lock`, `bun.lock`, `composer.lock`.

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Cmd+O` | Open repository |
| `Cmd+Enter` | Save comment |
| `Esc` | Cancel comment |

## Development

Prerequisites: PHP 8.4+, Composer, Node.js 22+, git.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer native:dev
```

### Tests

```bash
composer test:lint   # Pint
composer test:types  # PHPStan
composer test        # Pest
```

## Tech Stack

Laravel 13, Livewire 4, Flux UI, Alpine.js, Tailwind CSS, NativePHP Desktop (Electron).

## License

MIT
