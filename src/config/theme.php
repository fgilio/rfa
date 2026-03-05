<?php

return [
    // RGB triples - support Tailwind opacity modifiers (e.g. bg-gh-bg/50)
    'colors' => [
        'light' => [
            'bg' => '255 255 255',
            'surface' => '250 250 250',
            'border' => '228 228 231',
            'text' => '9 9 11',
            'muted' => '113 113 122',
            'accent' => '24 24 27',
            'link' => '59 130 246',
            'green' => '22 163 74',
            'red' => '220 38 38',
        ],
        'dark' => [
            'bg' => '9 9 11',
            'surface' => '24 24 27',
            'border' => '39 39 42',
            'text' => '250 250 250',
            'muted' => '161 161 170',
            'accent' => '250 250 250',
            'link' => '96 165 250',
            'green' => '74 222 128',
            'red' => '248 113 113',
        ],
    ],

    // Full color values - no opacity modifier needed
    'raw' => [
        'light' => [
            'add-bg' => 'rgba(22,163,74,0.08)',
            'add-line' => 'rgba(22,163,74,0.25)',
            'del-bg' => 'rgba(220,38,38,0.08)',
            'del-line' => 'rgba(220,38,38,0.25)',
            'hunk-bg' => 'rgba(9,9,11,0.03)',
            'hover-bg' => 'rgba(9,9,11,0.04)',
            'selected-bg' => 'rgba(9,9,11,0.08)',
            'scrollbar-track' => 'transparent',
            'scrollbar-thumb' => '#d4d4d8',
            'scrollbar-hover' => '#a1a1aa',
        ],
        'dark' => [
            'add-bg' => 'rgba(74,222,128,0.10)',
            'add-line' => 'rgba(74,222,128,0.30)',
            'del-bg' => 'rgba(248,113,113,0.10)',
            'del-line' => 'rgba(248,113,113,0.30)',
            'hunk-bg' => 'rgba(250,250,250,0.04)',
            'hover-bg' => 'rgba(250,250,250,0.05)',
            'selected-bg' => 'rgba(250,250,250,0.10)',
            'scrollbar-track' => 'transparent',
            'scrollbar-thumb' => '#3f3f46',
            'scrollbar-hover' => '#52525b',
        ],
    ],
];
