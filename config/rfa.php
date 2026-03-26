<?php

return [
    'diff_max_bytes' => env('RFA_DIFF_MAX_BYTES', 512_000),
    'cache_ttl_hours' => env('RFA_CACHE_TTL_HOURS', 24),
    'github_repo' => env('RFA_GITHUB_REPO', 'fgilio/rfa'),
];
