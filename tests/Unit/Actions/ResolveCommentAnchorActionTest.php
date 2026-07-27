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
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('from-sha', 'src/old.php'))->andReturn('pre-rename-hash');
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('to-sha', 'src/new.php'))->andReturn('post-rename-hash');

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
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('from-sha', 'f.php'))->andReturn('old');
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('to-sha', 'f.php'))->andReturn('new-match');

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

test('preserves normalized replies while resolving review anchors', function () {
    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-thread',
            'file_path' => 'f.php',
            'side' => 'right',
            'body' => 'Root',
            'replies' => [[
                'id' => 'r-1',
                'comment_id' => 'c-thread',
                'author_type' => 'human',
                'author_key' => 'rfa-ui',
                'body' => 'Reply',
            ]],
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['replies'][0])->toMatchArray([
        'id' => 'r-1',
        'commentId' => 'c-thread',
        'authorType' => 'human',
        'body' => 'Reply',
    ]);
});

test('marks comment as placed when the stored hash matches the left side of the diff', function () {
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('from-sha', 'f.php'))->andReturn('old-match');
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('to-sha', 'f.php'))->andReturn('new');

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
    $this->gitFileContent->shouldReceive('hashForSource')->andReturn('something-else');

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
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('from-sha', 'f.php'))->andReturn('some-other-left');
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('to-sha', 'f.php'))->andReturn('stored-hash');

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
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('from-sha', 'f.php'))->andReturn('stored-hash');
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('to-sha', 'f.php'))->andReturn('some-other-right');

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
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('from-sha', 'f.php'))->andReturn('unchanged');
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('to-sha', 'f.php'))->andReturn('unchanged');

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

test('recovers a right-side comment via snippet after an unrelated edit drifts the file hash', function () {
    // The whole-file hash drifts because line 2 changed, but the commented line
    // ($c = 3) is untouched. Snippet recovery must keep the comment placed instead
    // of dropping it — the silent-loss bug.
    $repo = $this->createTempDirectory('rfa_anchor_recover_right_');
    $this->initTestRepo($repo);
    file_put_contents($repo.'/f.php', "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
    $this->commitTestRepo($repo, 'init');
    file_put_contents($repo.'/f.php', "<?php\n\$a = 99;\n\$b = 2;\n\$c = 3;\n");

    app()->forgetInstance(GitFileContentService::class);
    $action = app(ResolveCommentAnchorAction::class);

    $result = $action->handle(
        $repo,
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 4,
            'end_line' => 4,
            'file_content_hash' => 'stale-whole-file-hash',
            'line_snippet' => '$c = 3;',
            'body' => 'comment on $c',
        ]],
        [['id' => 'file-f', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
    expect($result[0]['side'])->toBe('right');
    expect($result[0]['startLine'])->toBe(4);
});

test('recovers a left-side comment via snippet from the committed content', function () {
    $repo = $this->createTempDirectory('rfa_anchor_recover_left_');
    $this->initTestRepo($repo);
    file_put_contents($repo.'/f.php', "<?php\n\$a = 1;\n\$keep = 'anchor';\n\$c = 3;\n");
    $this->commitTestRepo($repo, 'init');
    // Working copy drifts; the left/committed side still holds the snippet.
    file_put_contents($repo.'/f.php', "<?php\nchanged\n\$keep = 'anchor';\n\$c = 3;\n");

    app()->forgetInstance(GitFileContentService::class);
    $action = app(ResolveCommentAnchorAction::class);

    $result = $action->handle(
        $repo,
        [[
            'id' => 'c-left',
            'file_path' => 'f.php',
            'side' => 'left',
            'start_line' => 3,
            'end_line' => 3,
            'file_content_hash' => 'stale-whole-file-hash',
            'line_snippet' => "\$keep = 'anchor';",
            'body' => 'left comment',
        ]],
        [['id' => 'file-f', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
    expect($result[0]['side'])->toBe('left');
});

test('stays unplaced when the snippet itself is gone from every side', function () {
    $repo = $this->createTempDirectory('rfa_anchor_recover_gone_');
    $this->initTestRepo($repo);
    file_put_contents($repo.'/f.php', "<?php\n\$a = 1;\n");
    $this->commitTestRepo($repo, 'init');
    file_put_contents($repo.'/f.php', "<?php\ntotally different\n");

    app()->forgetInstance(GitFileContentService::class);
    $action = app(ResolveCommentAnchorAction::class);

    $result = $action->handle(
        $repo,
        [[
            'id' => 'c-gone',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 2,
            'end_line' => 2,
            'file_content_hash' => 'stale-whole-file-hash',
            'line_snippet' => 'a line that exists nowhere anymore',
            'body' => 'orphan',
        ]],
        [['id' => 'file-f', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('unplaced');
});

test('uses the working copy as the right side when the target has no `to`', function () {
    $this->gitFileContent->shouldReceive('hashForSource')->with('/tmp/repo', gitSourceSpec('HEAD', 'f.php'))->andReturn('old');
    $this->gitFileContent->shouldReceive('hashForSource')
        ->with('/tmp/repo', gitSourceSpec(GitRef::Working->value, 'f.php'))
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
