<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rfa - Code Review</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                            green: 'rgb(var(--gh-green) / <alpha-value>)',
                            red: 'rgb(var(--gh-red) / <alpha-value>)',
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
                        mono: ['ui-monospace', 'SFMono-Regular', 'SF Mono', 'Menlo', 'Consolas', 'Liberation Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        @php
            $lightColors = config('theme.colors.light');
            $darkColors  = config('theme.colors.dark');
            $lightRaw    = config('theme.raw.light');
            $darkRaw     = config('theme.raw.dark');
        @endphp

        :root {
            --header-h: 53px;
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

        .diff-line:hover { background: var(--gh-hover-bg) !important; }
        .diff-line-num { cursor: pointer; user-select: none; }
        .diff-line-num:hover { color: rgb(var(--gh-accent)); }
        .line-selected { background: var(--gh-selected-bg) !important; }
        .comment-indicator { position: relative; }
        .comment-indicator::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: rgb(var(--gh-accent));
        }
        /* Phiki syntax highlighting */
        .diff-line td span[style] { background-color: transparent !important; }
        .dark .diff-line td span[style] {
            color: var(--phiki-dark-color, inherit) !important;
            font-style: var(--phiki-dark-font-style) !important;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--gh-scrollbar-track); }
        ::-webkit-scrollbar-thumb { background: var(--gh-scrollbar-thumb); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--gh-scrollbar-hover); }
    </style>
    @fluxAppearance
</head>
<body class="bg-gh-bg text-gh-text min-h-screen font-mono text-sm">
    {{ $slot }}
    @fluxScripts
</body>
</html>
