<?php

use App\Models\ReviewedFile;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('forProjectOrRepo limits the result to the given project', function () {
    $project = $this->createTestProject(['slug' => 'p', 'path' => '/tmp/p']);

    ReviewedFile::create(['project_id' => $project->id, 'repo_path' => '/tmp/p', 'file_path' => 'a.php', 'content_hash' => 'h1']);
    ReviewedFile::create(['project_id' => null, 'repo_path' => '/tmp/other', 'file_path' => 'a.php', 'content_hash' => 'h2']);

    $paths = ReviewedFile::query()->forProjectOrRepo($project->id, $project->path)->pluck('content_hash')->all();

    expect($paths)->toBe(['h1']);
});

test('forProjectOrRepo with null project_id matches only bare repo_path rows', function () {
    ReviewedFile::create(['project_id' => null, 'repo_path' => '/tmp/a', 'file_path' => 'a.php', 'content_hash' => 'ha']);
    ReviewedFile::create(['project_id' => null, 'repo_path' => '/tmp/b', 'file_path' => 'a.php', 'content_hash' => 'hb']);

    $hashes = ReviewedFile::query()->forProjectOrRepo(null, '/tmp/a')->pluck('content_hash')->all();

    expect($hashes)->toBe(['ha']);
});
