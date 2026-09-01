<?php

return [
    'component_namespaces' => [
        'layouts' => resource_path('views/layouts'),
        'pages' => resource_path('views/pages'),
    ],
    'make_command' => [
        'type' => 'sfc',
        'emoji' => true,
    ],
    'payload' => [
        // Laravel merges package config one level deep. Keep Livewire's safety
        // defaults when this nested array overrides max_components.
        'max_size' => 1024 * 1024,
        'max_nesting_depth' => 10,
        'max_calls' => 50,
        // Visible lazy file shells can hydrate in one local desktop request.
        'max_components' => 100,
    ],
];
