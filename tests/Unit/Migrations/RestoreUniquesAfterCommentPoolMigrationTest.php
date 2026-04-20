<?php

use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('review_sessions enforces a unique project_id', function () {
    $project = Project::factory()->create();

    DB::table('review_sessions')->insert([
        'project_id' => $project->id,
        'repo_path' => '/a',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('review_sessions')->insert([
        'project_id' => $project->id,
        'repo_path' => '/a-duplicate',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('review_sessions enforces a unique bare repo_path when project_id is null', function () {
    DB::table('review_sessions')->insert([
        'project_id' => null,
        'repo_path' => '/bare',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('review_sessions')->insert([
        'project_id' => null,
        'repo_path' => '/bare',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('review_sessions allows the same repo_path for a bare and a project row', function () {
    $project = Project::factory()->create(['path' => '/shared']);

    DB::table('review_sessions')->insert([
        'project_id' => null,
        'repo_path' => '/shared',
        'global_comment' => 'bare',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('review_sessions')->insert([
        'project_id' => $project->id,
        'repo_path' => '/shared',
        'global_comment' => 'project',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('review_sessions')->where('repo_path', '/shared')->count())->toBe(2);
});

test('reviewed_files allows the same repo_path+file+hash across bare and project rows', function () {
    $project = Project::factory()->create(['path' => '/x']);

    DB::table('reviewed_files')->insert([
        'project_id' => null,
        'repo_path' => '/x',
        'file_path' => 'a.php',
        'content_hash' => 'h1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('reviewed_files')->insert([
        'project_id' => $project->id,
        'repo_path' => '/x',
        'file_path' => 'a.php',
        'content_hash' => 'h1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('reviewed_files')->where('file_path', 'a.php')->count())->toBe(2);
});

test('reviewed_files still enforces uniqueness within bare repo rows', function () {
    DB::table('reviewed_files')->insert([
        'project_id' => null,
        'repo_path' => '/x',
        'file_path' => 'a.php',
        'content_hash' => 'h1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('reviewed_files')->insert([
        'project_id' => null,
        'repo_path' => '/x',
        'file_path' => 'a.php',
        'content_hash' => 'h1',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
