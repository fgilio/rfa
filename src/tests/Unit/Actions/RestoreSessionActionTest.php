<?php

use App\Actions\RestoreSessionAction;
use App\Models\Project;
use App\Models\ReviewSession;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('creates session when none exists and returns defaults', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    $files = [['id' => 'file-abc', 'path' => 'f.php']];

    $result = app(RestoreSessionAction::class)->handle($repoPath, $files);

    expect($result['comments'])->toBeEmpty();
    expect($result['viewedFiles'])->toBeEmpty();
    expect($result['globalComment'])->toBe('');
    expect(ReviewSession::where('repo_path', $repoPath)->exists())->toBeTrue();
});

test('restores and prunes stale comments', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    ReviewSession::create([
        'repo_path' => $repoPath,
        'comments' => [
            ['id' => 'c-1', 'file' => 'exists.php', 'fileId' => 'old-id'],
            ['id' => 'c-2', 'file' => 'gone.php', 'fileId' => 'old-id-2'],
        ],
        'viewed_files' => ['exists.php', 'gone.php'],
        'global_comment' => 'hello',
    ]);

    $files = [['id' => 'file-new', 'path' => 'exists.php']];

    $result = app(RestoreSessionAction::class)->handle($repoPath, $files);

    expect($result['comments'])->toHaveCount(1);
    expect($result['comments'][0]['file'])->toBe('exists.php');
    expect($result['comments'][0]['fileId'])->toBe('file-new');
    expect($result['viewedFiles'])->toBe(['exists.php']);
    expect($result['globalComment'])->toBe('hello');
});

test('remaps fileId to current file list', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    ReviewSession::create([
        'repo_path' => $repoPath,
        'comments' => [
            ['id' => 'c-1', 'file' => 'f.php', 'fileId' => 'stale-id'],
        ],
        'viewed_files' => [],
        'global_comment' => '',
    ]);

    $currentId = 'file-'.hash('xxh128', 'f.php');
    $files = [['id' => $currentId, 'path' => 'f.php']];

    $result = app(RestoreSessionAction::class)->handle($repoPath, $files);

    expect($result['comments'][0]['fileId'])->toBe($currentId);
});

test('keys by project_id when provided', function () {
    $project = Project::create([
        'slug' => 'test-proj',
        'name' => 'test-proj',
        'path' => '/tmp/test-proj',
        'git_common_dir' => '/tmp/test-proj/.git',
        'is_worktree' => false,
    ]);

    ReviewSession::create([
        'repo_path' => '/tmp/test-proj',
        'project_id' => $project->id,
        'comments' => [['id' => 'c-1', 'file' => 'f.php', 'fileId' => 'x']],
        'viewed_files' => [],
        'global_comment' => 'from project',
    ]);

    $files = [['id' => 'file-new', 'path' => 'f.php']];

    $result = app(RestoreSessionAction::class)->handle('/tmp/test-proj', $files, $project->id);

    expect($result['globalComment'])->toBe('from project');
    expect($result['comments'])->toHaveCount(1);
});
