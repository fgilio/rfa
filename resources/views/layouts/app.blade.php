<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>rfa - Code Review</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="stylesheet" href="/build/app.css">
    <script>
        window.rfaDiagnosticsConfig = {
            enabled: @js((bool) config('rfa.diagnostics.enabled')),
            endpoint: '/api/diagnostics/browser',
            sampleIntervalMs: @js((int) config('rfa.diagnostics.browser_sample_interval_ms')),
            commitSampleThrottleMs: @js((int) config('rfa.diagnostics.commit_sample_throttle_ms')),
            processSampleIntervalMs: @js((int) config('rfa.diagnostics.process_sample_interval_ms')),
            animationDetailLimit: @js((int) config('rfa.diagnostics.animation_detail_limit')),
            animationClassSummaryLimit: @js((int) config('rfa.diagnostics.animation_class_summary_limit')),
        };
    </script>
    <script>
        // Single source of truth for keyboard shortcuts (config/shortcuts.php).
        // shortcuts-store.js reads this to wire handlers and the cheat sheet.
        window.RFA_SHORTCUTS = @js(\App\Support\Shortcuts::all());
    </script>
    @localScript('js/runtime-diagnostics.js')
    @localScript('js/settings-store.js')
    @localScript('js/overlays-store.js')
    @localScript('js/keymap-store.js')
    @localScript('js/shortcuts-store.js')
    @localScript('js/page-search.js')
    @localScript('js/session-recovery.js')
    @localScript('js/smart-poll.js')
    @localScript('js/pending-saves.js')
    <style>
        @font-face {
            font-family: 'Space Grotesk';
            src: url('/fonts/SpaceGrotesk-Variable.woff2') format('woff2');
            font-weight: 400 700;
            font-display: swap;
        }
        @font-face {
            font-family: 'JetBrains Mono';
            src: url('/fonts/JetBrainsMono-Variable.woff2') format('woff2');
            font-weight: 400 500;
            font-display: swap;
        }

        @php
            $lightColors = config('theme.colors.light');
            $darkColors  = config('theme.colors.dark');
            $lightRaw    = config('theme.raw.light');
            $darkRaw     = config('theme.raw.dark');
        @endphp

        :root {
            --header-h: 56px;
            --feedback-bar-h: 128px;
            @foreach($lightColors as $key => $value)
            --gh-{{ $key }}: {{ $value }};
            @endforeach
            @foreach($lightRaw as $key => $value)
            --gh-{{ $key }}: {{ $value }};
            @endforeach
        }

        .dark {
            @foreach($darkColors as $key => $value)
            --gh-{{ $key }}: {{ $value }};
            @endforeach
            @foreach($darkRaw as $key => $value)
            --gh-{{ $key }}: {{ $value }};
            @endforeach
        }

        /* Brutalist logo treatment */
        .rfa-logo {
            font-family: 'Space Grotesk', system-ui, sans-serif;
            font-weight: 700;
            letter-spacing: -0.06em;
            line-height: 1;
        }

        /* Section labels */
        .section-label {
            font-family: 'Space Grotesk', system-ui, sans-serif;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        /* Outer 4-column grid; each hunk is a subgrid wrapper that owns its own
           grid-auto-flow:dense, so remove+add pairing in split mode is scoped
           to within the hunk and can't cross hunk boundaries. */
        .diff-grid { display: grid; min-width: 0; }
        .diff-grid[data-view-mode="unified"] {
            grid-template-columns: max-content max-content max-content minmax(0, 1fr);
        }
        .diff-grid[data-view-mode="split"] {
            grid-template-columns: max-content minmax(0, 1fr) max-content minmax(0, 1fr);
        }

        .diff-grid .diff-hunk {
            display: grid;
            grid-template-columns: subgrid;
            grid-column: 1 / -1;
        }
        .diff-grid[data-view-mode="split"] .diff-hunk { grid-auto-flow: row dense; }

        /* dense lets cells backfill earlier columns: in split-mode context
           lines the DOM order is num-old(1), num-new(3), content(2),
           mirror(4) — without dense, content lands on row 2. */
        .diff-grid .diff-line { display: grid; grid-template-columns: subgrid; grid-auto-flow: row dense; }
        .diff-grid[data-view-mode="unified"] .diff-line { grid-column: 1 / -1; }
        .diff-grid[data-view-mode="split"] .diff-line[data-type="remove"]  { grid-column: 1 / 3; }
        .diff-grid[data-view-mode="split"] .diff-line[data-type="add"]     { grid-column: 3 / 5; }
        .diff-grid[data-view-mode="split"] .diff-line[data-type="context"] { grid-column: 1 / -1; }

        .diff-grid .diff-fullspan { grid-column: 1 / -1; }
        /* Diff code text size. Bumped two type-scale steps up from the
           inherited text-xs (12px) to text-base (16px) so the diff reads
           larger by default — equivalent to two browser zoom-in presses.
           line-height keeps the prior 20/12 density ratio (1.667) so rows
           scale proportionally, exactly like zoom. Scoped to the cells, not
           the .diff-grid wrapper, so inline comment/expand rows
           (.diff-fullspan) keep their own smaller sizing. */
        .diff-cell { min-width: 0; font-size: 1rem; line-height: 1.667; }
        .diff-cell-num {
            padding: 0 0.5rem;
            text-align: right;
            /* Full muted — never an opacity-on-muted, which on the add/del gutter
               tint compounds to illegibility (see resources/CLAUDE.md). The add/del
               signal is carried by the .diff-num-marker bar, not a fill behind the
               digits, so numbers always sit on the faint tint and stay readable. */
            color: rgb(var(--gh-muted));
            cursor: pointer;
            user-select: none;
        }
        /* Change-marker: a confident saturated bar on the inner (content-facing)
           edge of the gutter, replacing the old full-cell stripe that buried the
           line number under a 0.6-alpha green/red fill. */
        .diff-num-marker { position: relative; }
        .diff-num-marker::after {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            right: 0;
            width: 2px;
            pointer-events: none;
        }
        .diff-num-marker-add::after { background: var(--gh-add-line); }
        .diff-num-marker-del::after { background: var(--gh-del-line); }
        /* Empty num cells (added rows have no old number, deleted rows have no
           new number) have no @mousedown handler — show the default cursor so
           the affordance matches the actual click behavior. */
        .diff-cell-num:empty { cursor: default; }
        .diff-cell-num:not(:empty):hover { color: rgb(var(--gh-link)); }
        .diff-cell-prefix { padding: 0 0.25rem; text-align: center; user-select: none; }
        .diff-cell-content { padding: 0 0.5rem; white-space: pre-wrap; word-break: break-all; }
        ::highlight(rfa-hovered-diff-url) {
            color: rgb(var(--gh-link));
            text-decoration-line: underline;
            text-decoration-style: wavy;
            text-decoration-color: rgb(var(--gh-link) / 0.75);
            text-decoration-thickness: 1px;
            text-underline-offset: 2px;
        }

        /* Markdown table rows render their cells as a real grid so each cell wraps
           within its own column instead of the whole row wrapping mid-character.
           Every row in a group shares the same grid-template-columns, so columns
           line up across rows. The cell drops pre-wrap/break-all (set for raw
           source) so blade whitespace collapses and prose wraps on word bounds. */
        .diff-cell-table { white-space: normal; }
        .diff-md-table { display: grid; align-items: start; width: 100%; }
        .diff-md-td {
            min-width: 0;
            /* In `ch`, and printed from the constant the aligner budgets its
               tracks with, so the stylesheet and that budget cannot drift. */
            padding: 0 {{ \App\Services\MarkdownTableAlignerService::CELL_PADDING_CH }}ch;
            white-space: normal;
            /* break-word, not anywhere: both break an over-long word the same way,
               but `anywhere` also drops the intrinsic min-content width to a single
               character, which lets a column collapse to a vertical letter stack. */
            overflow-wrap: break-word;
            word-break: normal;
            /* Inset shadow, not a border: a border would eat a pixel of the track
               that the aligner's ch budget does not know about — see CELL_PADDING_CH. */
            box-shadow: inset 1px 0 0 rgb(var(--gh-border) / 0.6);
        }
        .diff-md-td:first-child { padding-left: 0; box-shadow: none; }
        .diff-md-th { font-weight: 600; color: rgb(var(--gh-text)); }
        .diff-md-sep { height: 0; border-bottom: 1px solid rgb(var(--gh-border)); margin: 0.15rem 0; }
        /* A changed separator shows its raw `:---`/`---:` markers, muted so they
           read as structure rather than content. */
        .diff-md-sep-cell { color: rgb(var(--gh-muted)); }

        /* Unified: cells fill cols 1-4 in source order. */
        .diff-grid[data-view-mode="unified"] .diff-cell-num-old { grid-column: 1; }
        .diff-grid[data-view-mode="unified"] .diff-cell-num-new { grid-column: 2; }
        .diff-grid[data-view-mode="unified"] .diff-cell-prefix  { grid-column: 3; }
        .diff-grid[data-view-mode="unified"] .diff-cell-content { grid-column: 4; }
        .diff-grid[data-view-mode="unified"] .diff-cell-content-mirror { display: none; }

        /* Split: each line is a 2-col (remove/add) or 4-col (context) subgrid. */
        .diff-grid[data-view-mode="split"] .diff-cell-prefix,
        .diff-grid[data-view-mode="split"] .diff-line[data-type="remove"] .diff-cell-num-new,
        .diff-grid[data-view-mode="split"] .diff-line[data-type="add"] .diff-cell-num-old { display: none; }

        .diff-grid[data-view-mode="split"] .diff-cell-num-old,
        .diff-grid[data-view-mode="split"] .diff-line[data-type="add"] .diff-cell-num-new { grid-column: 1; }
        .diff-grid[data-view-mode="split"] .diff-cell-content { grid-column: 2; }
        .diff-grid[data-view-mode="split"] .diff-line[data-type="context"] .diff-cell-num-new        { grid-column: 3; }
        .diff-grid[data-view-mode="split"] .diff-line[data-type="context"] .diff-cell-content-mirror { grid-column: 4; }

        .diff-grid[data-view-mode="split"] .diff-cell-num-new { border-left: 1px solid rgb(var(--gh-border)); }

        /* Cells paint their own bg on add/remove rows (bg-gh-add-bg etc.),
           which would hide a row-level background. Apply on the cells. */
        .diff-line:hover .diff-cell { background: var(--gh-hover-bg); }
        .diff-line.line-selected .diff-cell { background: var(--gh-selected-bg); }

        .comment-indicator { position: relative; }
        .comment-indicator::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: rgb(var(--gh-link));
        }
        .draft-indicator { position: relative; }
        .draft-indicator::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: rgb(var(--gh-muted) / 0.5);
        }
        /* Prevent Flux menu scroll-lock from hiding scrollbar and causing layout shift */
        html { overflow-y: scroll !important; }

        /* Alpine doesn't ship the x-cloak rule itself. */
        [x-cloak] { display: none !important; }

        /* Pre-paint state for a persisted collapsed sidebar; settings-store.js
           sets the class in <head> and drops it on alpine:initialized. No
           !important, so once the class is gone x-show is the only authority
           and an expanded sidebar is never suppressed. */
        .rfa-boot-sidebar-collapsed [data-sidebar-collapsible] { display: none; }

        /* Fix checkbox visibility in dark mode */
        .dark [data-flux-checkbox-indicator] {
            border-color: rgb(var(--gh-border));
        }
        .dark [data-flux-checkbox-indicator] svg {
            color: var(--color-accent-foreground);
        }

        /* Override Flux heading to use display font */
        [data-flux-heading] {
            font-family: 'Space Grotesk', system-ui, sans-serif !important;
            letter-spacing: -0.04em;
        }

        /* Text color stays inherited so syntax highlighting still shows through. */
        .rfa-search-match {
            background: rgb(var(--gh-search-match) / 0.55);
            border-radius: 2px;
            box-shadow: 0 0 0 1px rgb(var(--gh-search-match) / 0.7);
        }
        .rfa-search-match--current {
            background: rgb(var(--gh-search-match-current) / 0.85);
            box-shadow: 0 0 0 2px rgb(var(--gh-search-match-current));
            position: relative;
            z-index: 1;
        }
        /* Render the "X of Y" pill only on the piece that carries the number.
           A match that crosses a token boundary wraps each piece in its own
           --current span; gating on [data-match-number] stops the other pieces
           from painting empty phantom pills. `left` is centered across the
           whole match via the JS-measured --rfa-match-center offset, falling
           back to the first piece's center for single-piece matches. */
        .rfa-search-match--current[data-match-number]::after {
            content: attr(data-match-number);
            position: absolute;
            top: 100%;
            left: var(--rfa-match-center, 50%);
            transform: translate(-50%, 6px);
            background: rgb(var(--gh-accent));
            color: rgb(var(--gh-bg));
            font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
            font-size: 10px;
            font-weight: 500;
            line-height: 1;
            padding: 3px 6px;
            border-radius: 2px;
            white-space: nowrap;
            pointer-events: none;
            box-shadow: 0 0 0 1px rgb(var(--gh-border));
            z-index: 2;
        }
    </style>
    @fluxAppearance
</head>
<body class="bg-gh-bg text-gh-text min-h-screen font-display text-sm antialiased"
    x-data
    @hard-reload-requested.window="window.location.reload()"
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.text).then(() => {
            if ($event.detail.toast) Flux.toast({ text: $event.detail.toast, variant: 'success' });
        }).catch(() => {});
    "
>
    {{-- Find-in-page search bar (Cmd/Ctrl+F).
         @keydown.window (not a plain @keydown) is deliberate: the listener
         lives on window, so Cmd+F still fires while this bar is x-show-hidden
         (display:none). Move it onto the element and the shortcut can no longer
         open the bar. --}}
    <div x-data="pageSearch" x-show="open" x-cloak data-search-ignore
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-out duration-100"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-1"
         @keydown.window="handleKeydown($event)"
         class="fixed top-2 right-4 z-[9999] flex items-center gap-1.5 bg-gh-surface border border-gh-border rounded-lg shadow-lg px-3 py-1.5">
        <svg class="w-4 h-4 text-gh-muted shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
        <input x-ref="input" type="text" x-model="query"
               placeholder="Find..."
               aria-label="Find in page"
               class="bg-transparent text-gh-text text-sm font-mono outline-none w-56 placeholder:text-gh-muted"
               @input="onQueryInput()"
               @keydown.enter.prevent="find($event.shiftKey)"
               @keydown.escape.prevent="close()">
        <span x-show="query" role="status" aria-live="polite" aria-atomic="true"
              class="text-gh-muted text-xs font-mono tabular-nums shrink-0 min-w-[3rem] text-right"
              x-text="matches.length === 0 ? 'No results' : currentMatch + ' of ' + matches.length"></span>
        <button type="button" @click="find(true)" class="text-gh-muted hover:text-gh-text p-0.5" title="Previous (Shift+Enter)" aria-label="Previous match">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
            </svg>
        </button>
        <button type="button" @click="find(false)" class="text-gh-muted hover:text-gh-text p-0.5" title="Next (Enter)" aria-label="Next match">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>
        <button type="button" @click="close()" class="text-gh-muted hover:text-gh-text p-0.5 ml-0.5" title="Close (Esc)" aria-label="Close find">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <x-shortcuts-help />

    <livewire:keepalive />
    @native
        <livewire:update-banner />
    @endnative
    {{-- Single defined home for Flux toasts (bottom-right), clear of the undo-toast
         and submit bar. Surface styling is brought onto gh-* tokens in app.css. --}}
    <flux:toast position="bottom end" />
    {{ $slot }}
    <script>
        document.addEventListener('livewire:init', () => {
            window.Livewire.on('native:App\\Events\\HardReloadShortcutPressed', () => {
                window.location.reload();
            });

            {{-- The native "Keyboard Shortcuts" menu item opens the same cheat-sheet
                 modal as the `?` shortcut, crossing the main→renderer bridge. --}}
            window.Livewire.on('native:App\\Events\\ShowShortcutsRequested', () => {
                window.Flux?.modal('keyboard-shortcuts').show();
            });

            {{-- The native "Toggle Sidebar" menu item lands on the same store
                 mutation as hyper+S; resizable-sidebar-shell owns the listener,
                 so pages without a sidebar simply have nobody listening. --}}
            window.Livewire.on('native:App\\Events\\ToggleSidebarRequested', () => {
                window.dispatchEvent(new CustomEvent('rfa-toggle-sidebar'));
            });
        });
    </script>
    @fluxScripts
</body>
</html>
