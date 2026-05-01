<?php

use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

test('context-comment-summary-updated event syncs the sidebar without re-render from parent', fn () => Livewire::test('context-tree', [
    'contextFiles' => [
        ['id' => 'ctx-a', 'path' => 'CLAUDE.md', 'kind' => 'CLAUDE', 'isTracked' => true, 'isSymlink' => false, 'symlinkTarget' => null],
    ],
    'commentSummary' => ['ctx-a' => ['count' => 1, 'drafts' => 0]],
])
    ->assertSet('commentSummary', ['ctx-a' => ['count' => 1, 'drafts' => 0]])
    ->dispatch('context-comment-summary-updated', summary: ['ctx-a' => ['count' => 3, 'drafts' => 1]])
    ->assertSet('commentSummary', ['ctx-a' => ['count' => 3, 'drafts' => 1]]));

test('clearing every comment shows a zero state in the sidebar', fn () => Livewire::test('context-tree', [
    'contextFiles' => [
        ['id' => 'ctx-a', 'path' => 'CLAUDE.md', 'kind' => 'CLAUDE', 'isTracked' => true, 'isSymlink' => false, 'symlinkTarget' => null],
    ],
    'commentSummary' => ['ctx-a' => ['count' => 5, 'drafts' => 2]],
])
    ->dispatch('context-comment-summary-updated', summary: [])
    ->assertSet('commentSummary', []));
