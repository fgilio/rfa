# rfa - Setup

## Prerequisites

- PHP 8.3+ (via Laravel Herd)
- Composer

## Install

```bash
# Install PHP dependencies
cd ~/.claude/skills/rfa/src
composer install

# Make the wrapper executable
chmod +x ~/.claude/skills/rfa/rfa

# Symlink to PATH
~/.claude/skills/rfa/install
```

## Verify

```bash
cd ~/any-git-repo
rfa
```

Browser should open with the diff viewer.

## .rfaignore

Create `.rfaignore` in your repo root to exclude files from review:

```
# Patterns (same as .gitignore)
*.min.js
dist/
generated/
```

Lock files (`package-lock.json`, `pnpm-lock.yaml`, `yarn.lock`, `composer.lock`) are always excluded.
