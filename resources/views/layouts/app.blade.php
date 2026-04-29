<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rfa - Code Review</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <script src="/js/tailwind.js"></script>
    <script src="/js/settings-store.js"></script>
    <script src="/js/overlays-store.js"></script>
    <script src="/js/keymap-store.js"></script>
    <script src="/js/zoom-shortcuts.js"></script>
    <script src="/js/page-search.js"></script>
    <script src="/js/session-recovery.js"></script>
    <script src="/js/smart-poll.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        gh: {
                            bg: 'rgb(var(--gh-bg) / <alpha-value>)',
                            surface: 'rgb(var(--gh-surface) / <alpha-value>)',
                            border: 'rgb(var(--gh-border) / <alpha-value>)',
                            text: 'rgb(var(--gh-text) / <alpha-value>)',
                            muted: 'rgb(var(--gh-muted) / <alpha-value>)',
                            accent: 'rgb(var(--gh-accent) / <alpha-value>)',
                            link: 'rgb(var(--gh-link) / <alpha-value>)',
                            green: 'rgb(var(--gh-green) / <alpha-value>)',
                            red: 'rgb(var(--gh-red) / <alpha-value>)',
                            draft: 'rgb(var(--gh-draft) / <alpha-value>)',
                            'add-bg': 'var(--gh-add-bg)',
                            'add-line': 'var(--gh-add-line)',
                            'del-bg': 'var(--gh-del-bg)',
                            'del-line': 'var(--gh-del-line)',
                            'hunk-bg': 'var(--gh-hunk-bg)',
                            'hover-bg': 'var(--gh-hover-bg)',
                            'selected-bg': 'var(--gh-selected-bg)',
                        }
                    },
                    fontFamily: {
                        display: ['"Space Grotesk"', 'system-ui', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'SF Mono', 'Menlo', 'Consolas', 'Liberation Mono', 'monospace'],
                    },
                    letterSpacing: {
                        'brutal': '-0.04em',
                        'brutal-tight': '-0.06em',
                    }
                }
            }
        }
    </script>
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
        .diff-cell { min-width: 0; }
        .diff-cell-num {
            padding: 0 0.5rem;
            text-align: right;
            color: rgb(var(--gh-muted) / 0.5);
            cursor: pointer;
            user-select: none;
        }
        .diff-cell-num:hover { color: rgb(var(--gh-link)); }
        .diff-cell-prefix { padding: 0 0.25rem; text-align: center; user-select: none; }
        .diff-cell-content { padding: 0 0.5rem; white-space: pre-wrap; word-break: break-all; }

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

        /* Fix checkbox visibility in dark mode */
        .dark [data-flux-checkbox-indicator] {
            border-color: rgb(var(--gh-border));
        }
        .dark [data-flux-checkbox-indicator] svg {
            color: white;
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
        .rfa-search-match--current::after {
            content: attr(data-match-number);
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translate(-50%, 6px);
            background: rgb(var(--gh-accent));
            color: rgb(var(--gh-bg));
            font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
            font-size: 10px;
            font-weight: 500;
            line-height: 1;
            padding: 3px 6px;
            border-radius: 4px;
            white-space: nowrap;
            pointer-events: none;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
            z-index: 2;
        }
    </style>
    @fluxAppearance
</head>
<body class="bg-gh-bg text-gh-text min-h-screen font-display text-sm antialiased">
    {{-- Find-in-page search bar (Cmd/Ctrl+F) --}}
    <div x-data="pageSearch" x-show="open" x-cloak data-search-ignore
         @keydown.window="handleKeydown($event)"
         class="fixed top-2 right-4 z-[9999] flex items-center gap-1.5 bg-gh-surface border border-gh-border rounded-lg shadow-lg px-3 py-1.5">
        <svg class="w-4 h-4 text-gh-muted shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
        <input x-ref="input" type="text" x-model="query"
               placeholder="Find..."
               class="bg-transparent text-gh-text text-sm font-mono outline-none w-56 placeholder:text-gh-muted"
               @input="onQueryInput()"
               @keydown.enter.prevent="find($event.shiftKey)"
               @keydown.escape.prevent="close()">
        <span x-show="query" role="status" aria-live="polite" aria-atomic="true"
              class="text-gh-muted text-xs font-mono tabular-nums shrink-0 min-w-[3rem] text-right"
              x-text="matchElements.length === 0 ? 'No results' : currentMatch + ' of ' + matchElements.length"></span>
        <button @click="find(true)" class="text-gh-muted hover:text-gh-text p-0.5" title="Previous (Shift+Enter)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
            </svg>
        </button>
        <button @click="find(false)" class="text-gh-muted hover:text-gh-text p-0.5" title="Next (Enter)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>
        <button @click="close()" class="text-gh-muted hover:text-gh-text p-0.5 ml-0.5" title="Close (Esc)">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <livewire:keepalive />
    {{ $slot }}
    @fluxScripts
</body>
</html>
