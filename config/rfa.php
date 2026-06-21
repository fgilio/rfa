<?php

return [
    'diff_max_bytes' => env('RFA_DIFF_MAX_BYTES', 512_000),
    'source_max_bytes' => env('RFA_SOURCE_MAX_BYTES', 1_048_576),
    'cache_ttl_hours' => env('RFA_CACHE_TTL_HOURS', 24),
    'default_context_lines' => env('RFA_DEFAULT_CONTEXT_LINES', 3),
    'moved_lines' => [
        'enabled' => env('RFA_MOVED_LINES_ENABLED', false),
        'mode' => env('RFA_MOVED_LINES_MODE', 'zebra'),
    ],
    'github_repo' => env('RFA_GITHUB_REPO', 'fgilio/rfa'),
    'diagnostics' => [
        'enabled' => env('RFA_DIAGNOSTICS_ENABLED', true),
        'path' => env('RFA_DIAGNOSTICS_PATH', storage_path('logs/rfa-diagnostics.jsonl')),
        'max_file_bytes' => env('RFA_DIAGNOSTICS_MAX_FILE_BYTES', 5 * 1024 * 1024),
        'max_files' => env('RFA_DIAGNOSTICS_MAX_FILES', 5),
        'max_browser_payload_bytes' => env('RFA_DIAGNOSTICS_MAX_BROWSER_PAYLOAD_BYTES', 64 * 1024),
        'browser_sample_interval_ms' => env('RFA_DIAGNOSTICS_BROWSER_SAMPLE_INTERVAL_MS', 60_000),
        'commit_sample_throttle_ms' => env('RFA_DIAGNOSTICS_COMMIT_SAMPLE_THROTTLE_MS', 30_000),
        'process_sample_interval_ms' => env('RFA_DIAGNOSTICS_PROCESS_SAMPLE_INTERVAL_MS', 300_000),
        'process_snapshots' => env('RFA_DIAGNOSTICS_PROCESS_SNAPSHOTS', PHP_OS_FAMILY === 'Darwin'),
        'process_snapshot_timeout_seconds' => env('RFA_DIAGNOSTICS_PROCESS_SNAPSHOT_TIMEOUT_SECONDS', 2),
        'process_snapshot_command_features' => env('RFA_DIAGNOSTICS_PROCESS_SNAPSHOT_COMMAND_FEATURES', true),
        'animation_detail_limit' => env('RFA_DIAGNOSTICS_ANIMATION_DETAIL_LIMIT', 20),
        'animation_class_summary_limit' => env('RFA_DIAGNOSTICS_ANIMATION_CLASS_SUMMARY_LIMIT', 20),
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
