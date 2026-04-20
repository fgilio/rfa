<?php

use App\Actions\ExportReviewAction;
use App\DTOs\DiffTarget;
use App\Models\Comment;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->tmpDir = $this->createTempDirectory('rfa_submit_scope_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");
    $this->commitTestRepo($this->tmpDir, 'init');
});

test('submit only exports placed comments and marks them submitted', function () {
    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];

    Comment::create([
        'id' => 'c-in',
        'repo_path' => $this->tmpDir,
        'origin_ref' => 'target-head',
        'file_path' => 'hello.php',
        'side' => 'right',
        'body' => 'in scope',
    ]);

    Comment::create([
        'id' => 'c-out',
        'repo_path' => $this->tmpDir,
        'origin_ref' => 'working',
        'file_path' => 'hello.php',
        'side' => 'right',
        'body' => 'out of scope',
    ]);

    $allComments = [
        ['id' => 'c-in', 'fileId' => $fileId, 'file' => 'hello.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'in scope', 'anchorStatus' => 'placed'],
        ['id' => 'c-out', 'fileId' => $fileId, 'file' => 'hello.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'out of scope', 'anchorStatus' => 'unplaced'],
    ];

    $target = DiffTarget::range('base', 'target-head');

    $result = app(ExportReviewAction::class)->handle($this->tmpDir, $allComments, '', $files, $target);

    expect(File::get($result['md']))->toContain('in scope');
    expect(File::get($result['md']))->not->toContain('out of scope');

    expect(Comment::find('c-in')->submitted_at)->not->toBeNull();
    expect(Comment::find('c-out')->submitted_at)->toBeNull();
});

test('submittedIds reports only the ids that were actually submitted', function () {
    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];

    Comment::create(['id' => 'c-in', 'repo_path' => $this->tmpDir, 'origin_ref' => 'target-head', 'file_path' => 'hello.php', 'side' => 'right', 'body' => 'in']);
    Comment::create(['id' => 'c-out', 'repo_path' => $this->tmpDir, 'origin_ref' => 'working', 'file_path' => 'hello.php', 'side' => 'right', 'body' => 'out']);

    $allComments = [
        ['id' => 'c-in', 'fileId' => $fileId, 'file' => 'hello.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'in', 'anchorStatus' => 'placed'],
        ['id' => 'c-out', 'fileId' => $fileId, 'file' => 'hello.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'out', 'anchorStatus' => 'unplaced'],
    ];

    $result = app(ExportReviewAction::class)->handle($this->tmpDir, $allComments, '', $files, DiffTarget::range('base', 'target-head'));

    expect($result['submittedIds'])->toBe(['c-in']);
});

test('comment with a different origin ref is still submitted when the resolver placed it', function () {
    // Comment authored at commit B; today the user reviews A..D. If the anchor resolver
    // matched the stored hash against D's content (rebase/amend reshuffled refs), the
    // comment is "placed" in this selection and must get submitted_at stamped.
    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];

    Comment::create([
        'id' => 'c-rebased',
        'repo_path' => $this->tmpDir,
        'origin_ref' => 'commit-B',
        'file_path' => 'hello.php',
        'side' => 'right',
        'file_content_hash' => 'hash-that-matches-D',
        'body' => 'rebased comment',
    ]);

    $resolved = [[
        'id' => 'c-rebased',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'rebased comment',
        'originRef' => 'commit-B',
        'anchorStatus' => 'placed',
    ]];

    $result = app(ExportReviewAction::class)->handle(
        $this->tmpDir,
        $resolved,
        '',
        $files,
        DiffTarget::range('commit-A', 'commit-D'),
    );

    expect($result['submittedIds'])->toBe(['c-rebased']);
    expect(Comment::find('c-rebased')->submitted_at)->not->toBeNull();
});
