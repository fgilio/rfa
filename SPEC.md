# rfa - Design Decisions

## Why build our own?

`review-for-agent` (Go binary by Waraq-Labs) had frontend issues on macOS with both source builds and pre-built binaries. Full control with Laravel + Livewire.

## Architecture

- Full Laravel app served via `php artisan serve`
- Livewire for all interactivity (comments, submit)
- Alpine.js for client-side line selection (no Livewire roundtrip)
- Tailwind CDN for styling (no build step)
- Diff parsing server-side in PHP
- No React, no npm, no external JS libraries

## Key decisions

- **CDN Tailwind** over compiled: zero build step, acceptable for local tool
- **Livewire state** over session/DB: ephemeral review, no persistence needed
- **Alpine line selection** over Livewire: instant UX, no roundtrip for clicks
- **Unified view only** for v1: simpler, covers 95% of use cases
- **Atomic file writes** for export: prevents partial writes on crash
- **`RFA_REPO_PATH` env var**: clean separation between the tool and target repo

## Data model

Comments are anchored to: `file + side + startLine + endLine`
- `side`: 'left' (old), 'right' (new), or 'file' (file-level)
- Range comments use startLine < endLine
- File comments have null line numbers

## Export format

JSON includes `schema_version: 1` for forward compatibility.
Markdown groups comments by file with diff context snippets.
