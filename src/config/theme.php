<?php

return [
    // RGB triples - support Tailwind opacity modifiers (e.g. bg-gh-bg/50)
    'colors' => [
        'light' => [
            'bg' => '255 255 255',
            'surface' => '246 248 250',
            'border' => '209 217 224',
            'text' => '31 35 40',
            'muted' => '101 109 118',
            'accent' => '9 105 218',
            'green' => '26 127 55',
            'red' => '209 36 47',
        ],
        'dark' => [
            'bg' => '13 17 23',
            'surface' => '22 27 34',
            'border' => '48 54 61',
            'text' => '230 237 243',
            'muted' => '139 148 158',
            'accent' => '88 166 255',
            'green' => '63 185 80',
            'red' => '248 81 73',
        ],
    ],

    // Full color values - no opacity modifier needed
    'raw' => [
        'light' => [
            'add-bg' => 'rgba(46,160,67,0.10)',
            'add-line' => 'rgba(46,160,67,0.30)',
            'del-bg' => 'rgba(248,81,73,0.10)',
            'del-line' => 'rgba(248,81,73,0.30)',
            'hunk-bg' => 'rgba(9,105,218,0.06)',
            'hover-bg' => 'rgba(9,105,218,0.06)',
            'selected-bg' => 'rgba(9,105,218,0.10)',
            'scrollbar-track' => '#f6f8fa',
            'scrollbar-thumb' => '#d1d9e0',
            'scrollbar-hover' => '#afb8c1',
        ],
        'dark' => [
            'add-bg' => 'rgba(46,160,67,0.15)',
            'add-line' => 'rgba(46,160,67,0.40)',
            'del-bg' => 'rgba(248,81,73,0.15)',
            'del-line' => 'rgba(248,81,73,0.40)',
            'hunk-bg' => 'rgba(56,139,253,0.10)',
            'hover-bg' => 'rgba(136,198,255,0.1)',
            'selected-bg' => 'rgba(88,166,255,0.15)',
            'scrollbar-track' => '#161b22',
            'scrollbar-thumb' => '#30363d',
            'scrollbar-hover' => '#484f58',
        ],
    ],
];
