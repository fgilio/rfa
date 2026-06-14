<?php

use App\Actions\SessionStateAction;
use App\DTOs\DiffTarget;
use App\Models\Comment;
use App\Services\GitFileContentService;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->gitFileContent = Mockery::mock(GitFileContentService::class);
    app()->instance(GitFileContentService::class, $this->gitFileContent);
});

test('a comment authored at commit B is visible when viewing range A..D if content matches', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-in-pool',
        'repo_path' => $repoPath,
        'origin_ref' => 'B',
        'file_path' => 'shared.php',
        'side' => 'right',
        'file_content_hash' => 'stable-hash',
        'body' => 'authored while viewing commit B',
    ]);

    $this->gitFileContent->shouldReceive('hashForSource')
        ->with($repoPath, gitSourceSpec('A', 'shared.php'))
        ->andReturn('different');
    $this->gitFileContent->shouldReceive('hashForSource')
        ->with($repoPath, gitSourceSpec('D', 'shared.php'))
        ->andReturn('stable-hash');

    $files = [['id' => 'file-shared', 'path' => 'shared.php', 'isUntracked' => false]];
    $result = app(SessionStateAction::class)->handle($repoPath, $files, null, DiffTarget::range('A', 'D'));

    expect($result['comments'])->toHaveCount(1);
    expect($result['comments'][0]['anchorStatus'])->toBe('placed');
    expect($result['comments'][0]['id'])->toBe('c-in-pool');
});

test('a submitted comment is hidden from the default view but persists in the pool', function () {
    $repoPath = '/tmp/'.$this->faker->word();

    Comment::create([
        'id' => 'c-open',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'f.php',
        'side' => 'right',
        'body' => 'still open',
    ]);

    Comment::create([
        'id' => 'c-archived',
        'repo_path' => $repoPath,
        'origin_ref' => 'working',
        'file_path' => 'f.php',
        'side' => 'right',
        'body' => 'already exported',
        'submitted_at' => now(),
    ]);

    $this->gitFileContent->shouldReceive('hashForSource')->andReturn(null);

    $files = [['id' => 'file-f', 'path' => 'f.php', 'isUntracked' => false]];
    $result = app(SessionStateAction::class)->handle($repoPath, $files);

    expect($result['comments'])->toHaveCount(1);
    expect($result['comments'][0]['id'])->toBe('c-open');

    // The archived comment is still present in the pool.
    expect(Comment::count())->toBe(2);
});
