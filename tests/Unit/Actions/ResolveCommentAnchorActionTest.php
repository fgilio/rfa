<?php

use App\Actions\ResolveCommentAnchorAction;
use App\DTOs\DiffTarget;
use App\Enums\GitRef;
use App\Services\GitFileContentService;
use Faker\Factory as Faker;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->gitFileContent = Mockery::mock(GitFileContentService::class);
    app()->instance(GitFileContentService::class, $this->gitFileContent);

    $this->action = app(ResolveCommentAnchorAction::class);
});

test('resolves left-side comments on renamed files using the pre-rename path', function () {
    // Comment was created on `src/old.php` (left side) before a rename; the diff's
    // current file list reports the new name. Without oldPath, the hash lookup
    // against `src/new.php` at `from-sha` returns null and the comment would fall
    // to unplaced even though the content is in the diff.
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'src/old.php')->andReturn('pre-rename-hash');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'src/new.php')->andReturn('post-rename-hash');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-rename',
            'file_path' => 'src/new.php',
            'side' => 'left',
            'start_line' => 3,
            'file_content_hash' => 'pre-rename-hash',
            'body' => 'left-side comment',
        ]],
        [['id' => 'file-renamed', 'path' => 'src/new.php', 'oldPath' => 'src/old.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
});

test('marks comment as placed when the stored hash matches the right side of the diff', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'f.php')->andReturn('old');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'f.php')->andReturn('new-match');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'end_line' => 1,
            'file_content_hash' => 'new-match',
            'body' => 'body',
            'origin_ref' => 'to-sha',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
    expect($result[0]['fileId'])->toBe('file-new');
});

test('marks comment as placed when the stored hash matches the left side of the diff', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'f.php')->andReturn('old-match');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'f.php')->andReturn('new');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'left',
            'start_line' => 1,
            'file_content_hash' => 'old-match',
            'body' => 'body',
            'origin_ref' => 'to-sha',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
});

test('marks comment as unplaced when the stored hash matches neither side', function () {
    $this->gitFileContent->shouldReceive('hashAt')->andReturn('something-else');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => 'stale-hash',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('unplaced');
});

test('marks legacy comments without stored hash as placed when the file is in the current diff', function () {
    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => null,
            'body' => 'legacy',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
});

test('marks comment as unplaced when the file is not in the current diff', function () {
    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'gone.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => 'some-hash',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'other.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('unplaced');
});

test('flips side to right when a left-side comment now matches the right-side hash', function () {
    // Post-rebase: stored left hash no longer appears on left, but the right side of
    // the current diff has identical content (e.g. the reviewed commit now sits on top).
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'f.php')->andReturn('some-other-left');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'f.php')->andReturn('stored-hash');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-flip',
            'file_path' => 'f.php',
            'side' => 'left',
            'start_line' => 20,
            'end_line' => 20,
            'file_content_hash' => 'stored-hash',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
    expect($result[0]['side'])->toBe('right');
    expect($result[0]['originalSide'])->toBe('left');
});

test('flips side to left when a right-side comment now matches the left-side hash', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'f.php')->andReturn('stored-hash');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'f.php')->andReturn('some-other-right');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-flip',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 5,
            'file_content_hash' => 'stored-hash',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
    expect($result[0]['side'])->toBe('left');
});

test('keeps stored side when stored hash matches that same side', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'f.php')->andReturn('unchanged');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'f.php')->andReturn('unchanged');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-same',
            'file_path' => 'f.php',
            'side' => 'left',
            'start_line' => 1,
            'file_content_hash' => 'unchanged',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['side'])->toBe('left');
    expect($result[0]['originalSide'])->toBe('left');
});

test('places external comments when the on-disk hash matches the stored hash', function () {
    $tmp = $this->createTempDirectory('rfa_anchor_ext_');
    $absolute = $tmp.'/note.md';
    file_put_contents($absolute, "stable\n");
    $hash = hash_file('xxh128', $absolute);

    // Use the real GitFileContentService for hashAtAbsolute; the beforeEach
    // mock only stubs git-side hashAt() calls.
    app()->forgetInstance(GitFileContentService::class);
    $action = app(ResolveCommentAnchorAction::class);

    $result = $action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-ext',
            'file_path' => 'external/notes/note.md',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => $hash,
            'body' => 'body',
            'origin_ref' => 'external',
        ]],
        [[
            'id' => 'file-ext',
            'path' => 'external/notes/note.md',
            'isExternal' => true,
            'externalAbsolutePath' => $absolute,
        ]],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
    expect($result[0]['fileId'])->toBe('file-ext');
    expect($result[0]['originRef'])->toBe('external');
});

test('marks external comments as unplaced when the on-disk content has changed', function () {
    $tmp = $this->createTempDirectory('rfa_anchor_ext_stale_');
    $absolute = $tmp.'/note.md';
    file_put_contents($absolute, "current\n");

    app()->forgetInstance(GitFileContentService::class);
    $action = app(ResolveCommentAnchorAction::class);

    $result = $action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-ext-stale',
            'file_path' => 'external/notes/note.md',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => 'stale-hash',
            'body' => 'body',
            'origin_ref' => 'external',
        ]],
        [[
            'id' => 'file-ext',
            'path' => 'external/notes/note.md',
            'isExternal' => true,
            'externalAbsolutePath' => $absolute,
        ]],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('unplaced');
});

test('uses the working copy as the right side when the target has no `to`', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'HEAD', 'f.php')->andReturn('old');
    $this->gitFileContent->shouldReceive('hashAt')
        ->with('/tmp/repo', GitRef::Working->value, 'f.php')
        ->andReturn('working-match');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => 'working-match',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
});
