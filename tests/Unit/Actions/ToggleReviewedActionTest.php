<?php

use App\Actions\ToggleReviewedAction;
use App\DTOs\DiffTarget;
use App\Models\ReviewedFile;
use App\Services\GitFileContentService;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->knownFiles = [
        ['id' => 'id-a', 'path' => 'a.php', 'isUntracked' => false],
        ['id' => 'id-b', 'path' => 'b.php', 'isUntracked' => false],
        ['id' => 'id-c', 'path' => 'c.php', 'isUntracked' => false],
    ];

    $mock = Mockery::mock(GitFileContentService::class);
    $mock->shouldReceive('hashAt')->byDefault()->andReturn('mock-hash');
    app()->instance(GitFileContentService::class, $mock);

    $this->action = app(ToggleReviewedAction::class);
});

test('adds file to reviewed list with content hash', function () {
    $result = $this->action->handle([], 'a.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBe(['a.php' => 'mock-hash']);
    expect(ReviewedFile::where('file_path', 'a.php')->where('content_hash', 'mock-hash')->exists())->toBeTrue();
});

test('removes file from reviewed list', function () {
    ReviewedFile::create(['repo_path' => '/tmp/repo', 'file_path' => 'a.php', 'content_hash' => 'hash1']);

    $result = $this->action->handle(['a.php' => 'hash1', 'b.php' => 'hash2'], 'a.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBe(['b.php' => 'hash2']);
    expect(ReviewedFile::where('file_path', 'a.php')->exists())->toBeFalse();
});

test('returns null for unknown path', function () {
    $result = $this->action->handle([], 'unknown.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBeNull();
});

test('adds file with empty content hash when no repo path', function () {
    $result = $this->action->handle([], 'a.php', $this->knownFiles, '');

    expect($result)->toBe(['a.php' => '']);
});

test('toggle off does not touch rows belonging to a different repo', function () {
    ReviewedFile::create(['repo_path' => '/tmp/repo', 'file_path' => 'a.php', 'content_hash' => 'h-own']);
    ReviewedFile::create(['repo_path' => '/tmp/other', 'file_path' => 'a.php', 'content_hash' => 'h-foreign']);

    $this->action->handle(['a.php' => 'h-own'], 'a.php', $this->knownFiles, '/tmp/repo');

    expect(ReviewedFile::where('repo_path', '/tmp/repo')->where('file_path', 'a.php')->exists())->toBeFalse();
    expect(ReviewedFile::where('repo_path', '/tmp/other')->where('file_path', 'a.php')->exists())->toBeTrue();
});

test('falls back to the left ref when the right side hash is unavailable', function () {
    $target = DiffTarget::commit('abc123', 'parent123');

    $this->gitFileContent = Mockery::mock(GitFileContentService::class);
    $this->gitFileContent->shouldReceive('hashAt')
        ->with('/tmp/repo', 'abc123', 'deleted.php')
        ->andReturn(null);
    $this->gitFileContent->shouldReceive('hashAt')
        ->with('/tmp/repo', 'parent123', 'deleted.php')
        ->andReturn('parent-hash');
    app()->instance(GitFileContentService::class, $this->gitFileContent);

    $action = app(ToggleReviewedAction::class);

    $result = $action->handle(
        [],
        'deleted.php',
        [['id' => 'id-d', 'path' => 'deleted.php', 'isUntracked' => false]],
        '/tmp/repo',
        $target,
    );

    expect($result)->toBe(['deleted.php' => 'parent-hash']);
});

test('preserves other entries on toggle off', function () {
    ReviewedFile::create(['repo_path' => '/tmp/repo', 'file_path' => 'b.php', 'content_hash' => 'h2']);

    $result = $this->action->handle(['a.php' => 'h1', 'b.php' => 'h2', 'c.php' => 'h3'], 'b.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBe(['a.php' => 'h1', 'c.php' => 'h3']);
});
