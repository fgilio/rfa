<!DOCTYPE html>
<html lang="en" class="dark">
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
                            bg: '#0d1117',
                            surface: '#161b22',
                            border: '#30363d',
                            text: '#e6edf3',
                            muted: '#8b949e',
                            accent: '#58a6ff',
                            green: '#3fb950',
                            red: '#f85149',
                            'add-bg': 'rgba(46, 160, 67, 0.15)',
                            'add-line': 'rgba(46, 160, 67, 0.4)',
                            'del-bg': 'rgba(248, 81, 73, 0.15)',
                            'del-line': 'rgba(248, 81, 73, 0.4)',
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
        body { background: #0d1117; color: #e6edf3; }
        .diff-line:hover { background: rgba(136, 198, 255, 0.1) !important; }
        .diff-line-num { cursor: pointer; user-select: none; }
        .diff-line-num:hover { color: #58a6ff; }
        .line-selected { background: rgba(88, 166, 255, 0.15) !important; }
        .comment-indicator { position: relative; }
        .comment-indicator::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #58a6ff;
        }
        /* Scrollbar styling */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #161b22; }
        ::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #484f58; }
    </style>
    @livewireStyles
</head>
<body class="min-h-screen font-mono text-sm">
    {{ $slot }}
    @livewireScripts
</body>
</html>
