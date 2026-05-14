<?php

return [
    'diff_max_bytes' => env('RFA_DIFF_MAX_BYTES', 512_000),
    'cache_ttl_hours' => env('RFA_CACHE_TTL_HOURS', 24),
    'github_repo' => env('RFA_GITHUB_REPO', 'fgilio/rfa'),
    'diagnostics' => [
        'enabled' => env('RFA_DIAGNOSTICS_ENABLED', true),
        'path' => env('RFA_DIAGNOSTICS_PATH', storage_path('logs/rfa-diagnostics.jsonl')),
        'max_file_bytes' => env('RFA_DIAGNOSTICS_MAX_FILE_BYTES', 5 * 1024 * 1024),
        'max_files' => env('RFA_DIAGNOSTICS_MAX_FILES', 5),
        'max_browser_payload_bytes' => env('RFA_DIAGNOSTICS_MAX_BROWSER_PAYLOAD_BYTES', 64 * 1024),
        'browser_sample_interval_ms' => env('RFA_DIAGNOSTICS_BROWSER_SAMPLE_INTERVAL_MS', 60_000),
        'process_sample_interval_ms' => env('RFA_DIAGNOSTICS_PROCESS_SAMPLE_INTERVAL_MS', 300_000),
    ],

    /*
    | Path prefixes (from repo root) the AgentContextFileScanner skips when
    | walking the working tree for untracked CLAUDE.md / AGENTS.md candidates.
    | The defaults mirror the build artifact locations the Context page design
    | doc calls out (the 5 stale copies under nativephp/electron/dist).
    */
    'context_scan_skip_dirs' => [
        'nativephp/electron/dist',
        'vendor',
        'node_modules',
        'storage/framework',
        '.git',
    ],
];
