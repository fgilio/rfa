<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>rfa - Code Review</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
                            link: 'rgb(var(--gh-link) / <alpha-value>)',
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

        .diff-line:hover { background: var(--gh-hover-bg) !important; }
        .diff-line-num { cursor: pointer; user-select: none; }
        .diff-line-num:hover { color: rgb(var(--gh-link)); }
        .line-selected { background: var(--gh-selected-bg) !important; }
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
        /* Phiki syntax highlighting */
        .diff-line td span[style] { background-color: transparent !important; }
        .dark .diff-line td span[style] {
            color: var(--phiki-dark-color, inherit) !important;
            font-style: var(--phiki-dark-font-style) !important;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--gh-scrollbar-thumb); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--gh-scrollbar-hover); }

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
    </style>
    @fluxAppearance
</head>
<body class="bg-gh-bg text-gh-text min-h-screen font-display text-sm antialiased">
    <livewire:update-checker />
    {{ $slot }}
    @fluxScripts
</body>
</html>
