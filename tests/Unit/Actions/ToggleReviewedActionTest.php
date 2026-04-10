<?php

use App\Actions\ToggleReviewedAction;
use App\Services\GitDiffService;
use Faker\Factory as Faker;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->knownFiles = [
        ['id' => 'id-a', 'path' => 'a.php', 'isUntracked' => false],
        ['id' => 'id-b', 'path' => 'b.php', 'isUntracked' => false],
        ['id' => 'id-c', 'path' => 'c.php', 'isUntracked' => false],
    ];

    $mock = Mockery::mock(GitDiffService::class);
    $mock->shouldReceive('fileDiffFingerprint')->andReturn('mock-hash');
    app()->instance(GitDiffService::class, $mock);

    $this->action = app(ToggleReviewedAction::class);
});

test('adds file to reviewed list with fingerprint', function () {
    $result = $this->action->handle([], 'a.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBe(['a.php' => 'mock-hash']);
});

test('removes file from reviewed list', function () {
    $result = $this->action->handle(['a.php' => 'hash1', 'b.php' => 'hash2'], 'a.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBe(['b.php' => 'hash2']);
});

test('returns null for unknown path', function () {
    $result = $this->action->handle([], 'unknown.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBeNull();
});

test('adds file with empty fingerprint when no repo path', function () {
    $result = $this->action->handle([], 'a.php', $this->knownFiles);

    expect($result)->toBe(['a.php' => '']);
});

test('preserves other entries on toggle off', function () {
    $result = $this->action->handle(['a.php' => 'h1', 'b.php' => 'h2', 'c.php' => 'h3'], 'b.php', $this->knownFiles, '/tmp/repo');

    expect($result)->toBe(['a.php' => 'h1', 'c.php' => 'h3']);
});
