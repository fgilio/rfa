<?php

return [
    'diff_max_bytes' => env('RFA_DIFF_MAX_BYTES', 512_000),
    'cache_ttl_hours' => env('RFA_CACHE_TTL_HOURS', 24),
    'github_repo' => env('RFA_GITHUB_REPO', 'fgilio/rfa'),

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
