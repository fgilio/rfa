<?php

use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

test('context-comment-summary-updated event syncs the sidebar without re-render from parent', function () {
    $contextFiles = [
        ['id' => 'ctx-a', 'path' => 'CLAUDE.md', 'kind' => 'CLAUDE', 'isTracked' => true, 'isSymlink' => false, 'symlinkTarget' => null],
    ];

    $initialSummary = ['ctx-a' => ['count' => 1, 'drafts' => 0]];
    $nextSummary = ['ctx-a' => ['count' => 3, 'drafts' => 1]];

    Livewire::test('context-tree', [
        'contextFiles' => $contextFiles,
        'commentSummary' => $initialSummary,
    ])
        ->assertSet('commentSummary', $initialSummary)
        ->dispatch('context-comment-summary-updated', summary: $nextSummary)
        ->assertSet('commentSummary', $nextSummary);
});

test('clearing every comment shows a zero state in the sidebar', function () {
    $contextFiles = [
        ['id' => 'ctx-a', 'path' => 'CLAUDE.md', 'kind' => 'CLAUDE', 'isTracked' => true, 'isSymlink' => false, 'symlinkTarget' => null],
    ];

    Livewire::test('context-tree', [
        'contextFiles' => $contextFiles,
        'commentSummary' => ['ctx-a' => ['count' => 5, 'drafts' => 2]],
    ])
        ->dispatch('context-comment-summary-updated', summary: [])
        ->assertSet('commentSummary', []);
});
