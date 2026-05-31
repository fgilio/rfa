<?php

use App\Actions\SessionStateAction;
use App\DTOs\DiffTarget;
use App\Enums\GitRef;
use App\Models\Comment;
use App\Models\ReviewedFile;
use App\Models\ReviewSession;
use App\Services\GitFileContentService;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->gitFileContentMock = Mockery::mock(GitFileContentService::class);
    $this->gitFileContentMock->shouldReceive('hashAt')->byDefault()->andReturn(null);
    app()->instance(GitFileContentService::class, $this->gitFileContentMock);
});

test('creates session when none exists and returns defaults', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    $files = [['id' => 'file-abc', 'path' => 'f.php', 'isUntracked' => false]];

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['comments'])->toBeEmpty();
    expect($result['reviewedFiles'])->toBeEmpty();
    expect($result['globalComment'])->toBe('');
    expect($result['orphanedPaths'])->toBeEmpty();
    expect(ReviewSession::where('repo_path', $repoPath)->exists())->toBeTrue();
});

test('restores comments and tracks orphaned paths', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-1',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'exists.php',
        'side' => 'right',
        'body' => 'comment on existing',
    ]);

    Comment::create([
        'id' => 'c-2',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'gone.php',
        'side' => 'right',
        'body' => 'comment on missing file',
    ]);

    ReviewSession::updateOrCreate(
        ['project_id' => null, 'repo_path' => $repoPath],
        ['repo_path' => $repoPath, 'global_comment' => 'hello'],
    );

    $files = [['id' => 'file-new', 'path' => 'exists.php', 'isUntracked' => false]];

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['comments'])->toHaveCount(2);
    $byFile = collect($result['comments'])->keyBy('file');
    expect($byFile['exists.php']['fileId'])->toBe('file-new');
    expect($byFile['exists.php']['anchorStatus'])->toBe('placed');
    expect($byFile['gone.php']['anchorStatus'])->toBe('unplaced');
    expect($result['orphanedPaths'])->toBe(['gone.php']);
    expect($result['globalComment'])->toBe('hello');
});

test('remaps fileId to current file list', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-1',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'f.php',
        'side' => 'right',
        'body' => 'comment',
    ]);

    $currentId = 'file-'.hash('xxh128', 'f.php');
    $files = [['id' => $currentId, 'path' => 'f.php', 'isUntracked' => false]];

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['comments'][0]['fileId'])->toBe($currentId);
});

test('keys by project_id when provided', function () {
    $project = $this->createTestProject([
        'slug' => 'test-proj',
        'path' => '/tmp/test-proj',
    ]);

    Comment::create([
        'id' => 'c-1',
        'project_id' => $project->id,
        'repo_path' => '/tmp/test-proj',
        'origin_ref' => 'working',
        'file_path' => 'f.php',
        'side' => 'right',
        'body' => 'x',
    ]);

    ReviewSession::updateOrCreate(
        ['project_id' => $project->id],
        ['repo_path' => '/tmp/test-proj', 'global_comment' => 'from project'],
    );

    $files = [['id' => 'file-new', 'path' => 'f.php', 'isUntracked' => false]];

    $result = app(SessionStateAction::class)->handle('/tmp/test-proj', $files, $project->id);

    expect($result['globalComment'])->toBe('from project');
    expect($result['comments'])->toHaveCount(1);
});

test('restores reviewed files when current content hash matches stored record', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    ReviewedFile::create([
        'repo_path' => $repoPath,
        'file_path' => 'a.php',
        'content_hash' => 'hash-a',
    ]);

    ReviewedFile::create([
        'repo_path' => $repoPath,
        'file_path' => 'b.php',
        'content_hash' => 'hash-b',
    ]);

    $files = [
        ['id' => 'id-a', 'path' => 'a.php', 'isUntracked' => false],
        ['id' => 'id-b', 'path' => 'b.php', 'isUntracked' => false],
    ];

    $this->gitFileContentMock->shouldReceive('hashAt')
        ->with($repoPath, GitRef::Working->value, 'a.php')
        ->andReturn('hash-a');
    $this->gitFileContentMock->shouldReceive('hashAt')
        ->with($repoPath, GitRef::Working->value, 'b.php')
        ->andReturn('different-hash');

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['reviewedFiles'])->toBe(['a.php' => 'hash-a']);
});

test('excludes submitted comments from restored view', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-open',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'f.php',
        'side' => 'right',
        'body' => 'open',
    ]);

    Comment::create([
        'id' => 'c-submitted',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'f.php',
        'side' => 'right',
        'body' => 'already submitted',
        'submitted_at' => now(),
    ]);

    $files = [['id' => 'file-new', 'path' => 'f.php', 'isUntracked' => false]];

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['comments'])->toHaveCount(1);
    expect($result['comments'][0]['id'])->toBe('c-open');
});

test('excludes context-file comments from the review surface', function () {
    // A comment left on CLAUDE.md from the Context page must never surface on the
    // review diff, or submitting the review would export and consume it.
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-review',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'body' => 'review comment',
    ]);

    Comment::create([
        'id' => 'c-context',
        'repo_path' => $repoPath,
        'origin_ref' => Comment::ORIGIN_CONTEXT,
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'body' => 'context comment',
    ]);

    $files = [['id' => 'file-claude', 'path' => 'CLAUDE.md', 'isUntracked' => false]];

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['comments'])->toHaveCount(1);
    expect($result['comments'][0]['id'])->toBe('c-review');
});

test('marks comment as placed when content hash matches right side', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-1',
        'repo_path' => $repoPath,
        'origin_ref' => DiffTarget::commit('abc123')->to(),
        'file_path' => 'f.php',
        'side' => 'right',
        'file_content_hash' => 'matching-hash',
        'body' => 'body',
    ]);

    $target = DiffTarget::commit('abc123');
    $files = [['id' => 'file-new', 'path' => 'f.php', 'isUntracked' => false]];

    $this->gitFileContentMock->shouldReceive('hashAt')
        ->with($repoPath, $target->from(), 'f.php')
        ->andReturn('different-hash');
    $this->gitFileContentMock->shouldReceive('hashAt')
        ->with($repoPath, 'abc123', 'f.php')
        ->andReturn('matching-hash');

    $result = app(SessionStateAction::class)->handle($repoPath, $files, null, $target);

    expect($result['comments'][0]['anchorStatus'])->toBe('placed');
});

test('rehydrates external reviewed files via on-disk hash, not git refs', function () {
    // External files store an absolute-path hash. The session loader must
    // compare the stored hash to the current on-disk hash; if it changes,
    // the reviewed flag drops, matching the diff-anchor model.
    $repoPath = '/tmp/'.$this->faker->word();
    $tmp = $this->createTempDirectory('rfa_session_ext_');
    $absolute = $tmp.'/note.md';
    file_put_contents($absolute, "stable\n");
    $hash = hash_file('xxh128', $absolute);

    ReviewedFile::create([
        'repo_path' => $repoPath,
        'file_path' => 'external/notes/note.md',
        'content_hash' => $hash,
    ]);

    $files = [[
        'id' => 'file-ext',
        'path' => 'external/notes/note.md',
        'isUntracked' => false,
        'isExternal' => true,
        'externalAbsolutePath' => $absolute,
    ]];

    // Use the real hashAtAbsolute; mock the git-side hashAt as before.
    $this->gitFileContentMock->shouldReceive('hashAtAbsolute')->andReturnUsing(
        fn (string $p) => is_file($p) ? hash_file('xxh128', $p) : null,
    );

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['reviewedFiles'])->toBe(['external/notes/note.md' => $hash]);

    // Mutate the file → reviewed state should drop on the next load.
    file_put_contents($absolute, "changed\n");

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['reviewedFiles'])->toBe([]);
});

test('rehydrates reviewed files via left-side hash when the right ref has no content', function () {
    // ToggleReviewedAction explicitly stores the left-side hash for deleted /
    // left-only files. Without matching against the left ref too, those reviewed
    // markers would silently drop on restore.
    $repoPath = '/tmp/'.$this->faker->word();

    ReviewedFile::create([
        'repo_path' => $repoPath,
        'file_path' => 'deleted.php',
        'content_hash' => 'left-hash',
    ]);

    $files = [['id' => 'id-d', 'path' => 'deleted.php', 'isUntracked' => false]];

    $this->gitFileContentMock->shouldReceive('hashAt')
        ->with($repoPath, 'abc123', 'deleted.php')
        ->andReturn(null);
    $this->gitFileContentMock->shouldReceive('hashAt')
        ->with($repoPath, 'parent123', 'deleted.php')
        ->andReturn('left-hash');

    $result = app(SessionStateAction::class)->handle(
        $repoPath,
        $files,
        null,
        DiffTarget::range('parent123', 'abc123'),
    );

    expect($result['reviewedFiles'])->toBe(['deleted.php' => 'left-hash']);
});

test('rehydrates legacy reviewed_files rows that were migrated with an empty content_hash', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    ReviewedFile::create([
        'repo_path' => $repoPath,
        'file_path' => 'legacy.php',
        'content_hash' => '',
    ]);

    $files = [['id' => 'id-legacy', 'path' => 'legacy.php', 'isUntracked' => false]];

    $this->gitFileContentMock->shouldReceive('hashAt')
        ->with($repoPath, GitRef::Working->value, 'legacy.php')
        ->andReturn('current-hash');

    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['reviewedFiles'])->toHaveKey('legacy.php');
});

test('marks comment as unplaced when content hash does not match either side', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-1',
        'repo_path' => $repoPath,
        'origin_ref' => 'deadbeef',
        'file_path' => 'f.php',
        'side' => 'right',
        'file_content_hash' => 'orphan-hash',
        'body' => 'body',
    ]);

    $target = DiffTarget::commit('abc123');
    $files = [['id' => 'file-new', 'path' => 'f.php', 'isUntracked' => false]];

    $this->gitFileContentMock->shouldReceive('hashAt')->andReturn('different-hash');

    $result = app(SessionStateAction::class)->handle($repoPath, $files, null, $target);

    expect($result['comments'][0]['anchorStatus'])->toBe('unplaced');
});
