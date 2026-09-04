<?php

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Render <x-diff.file-header> with a working-tree (diffTo === null) added file,
 * the exact shape the "Since the beginning" view produces for every tracked
 * file. The discard control must appear only when discard is allowed.
 */
function renderFileHeader(bool $allowDiscard, bool $isWholeFile = false): string
{
    return Blade::render(
        '<x-diff.file-header :file="$file" :repo-path="$repoPath" :diff-to="$diffTo" :allow-discard="$allowDiscard" />',
        [
            'file' => [
                'id' => 'file-1',
                'path' => 'app/Foo.php',
                'oldPath' => null,
                'status' => 'added',
                'isBinary' => false,
                'isSymlink' => false,
                'isUntracked' => false,
                'isExternal' => false,
                'isWholeFile' => $isWholeFile,
                'additions' => 3,
                'deletions' => 0,
            ],
            'repoPath' => '/tmp/repo',
            'diffTo' => null,
            'allowDiscard' => $allowDiscard,
        ],
    );
}

test('shows the discard affordance for a working-tree added file', function () {
    expect(renderFileHeader(allowDiscard: true))->toContain('Discard changes');
});

test('hides the discard affordance when discard is disallowed (entire-repo view)', function () {
    expect(renderFileHeader(allowDiscard: false))->not->toContain('Discard changes');
});

test('hides the discard affordance for a repository whole-file review', function () {
    expect(renderFileHeader(allowDiscard: true, isWholeFile: true))->not->toContain('Discard changes');
});
