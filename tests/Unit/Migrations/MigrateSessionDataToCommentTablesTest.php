<?php

use App\Models\Project;
use Faker\Factory as Faker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    // Re-add the legacy shape so we can replay the migration in isolation.
    Schema::table('review_sessions', function (Blueprint $table) {
        if (! Schema::hasColumn('review_sessions', 'context_fingerprint')) {
            $table->string('context_fingerprint')->default('working')->after('project_id');
        }
        if (! Schema::hasColumn('review_sessions', 'reviewed_files')) {
            $table->json('reviewed_files')->default('[]');
        }
        if (! Schema::hasColumn('review_sessions', 'comments')) {
            $table->json('comments')->default('[]');
        }
    });

    // The pre-migration unique index; the migration under test drops it.
    try {
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->unique(['project_id', 'context_fingerprint']);
        });
    } catch (Throwable) {
        // Already exists.
    }

    DB::table('comments')->delete();
    DB::table('reviewed_files')->delete();
    DB::table('review_sessions')->delete();

    $this->migration = require database_path('migrations/2026_04_19_074936_migrate_session_data_to_comment_tables.php');
});

test('migrates working-directory comments into the new comments table with origin_ref=working', function () {
    DB::table('review_sessions')->insert([
        'repo_path' => '/tmp/repo',
        'context_fingerprint' => 'working',
        'comments' => json_encode([
            [
                'id' => 'c-working-1',
                'fileId' => 'file-old',
                'file' => 'src/a.php',
                'side' => 'right',
                'startLine' => 12,
                'endLine' => 12,
                'body' => 'needs docblock',
                'isDraft' => false,
            ],
        ]),
        'reviewed_files' => '[]',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    $row = DB::table('comments')->where('id', 'c-working-1')->first();

    expect($row)->not->toBeNull();
    expect($row->origin_ref)->toBe('working');
    expect($row->file_path)->toBe('src/a.php');
    expect($row->side)->toBe('right');
    expect((int) $row->start_line)->toBe(12);
    expect((int) $row->end_line)->toBe(12);
    expect($row->body)->toBe('needs docblock');
    expect((bool) $row->is_draft)->toBeFalse();
});

test('maps a range context_fingerprint to the head commit of the selection', function () {
    DB::table('review_sessions')->insert([
        'repo_path' => '/tmp/repo',
        'context_fingerprint' => 'abc123..def456',
        'comments' => json_encode([
            ['id' => 'c-range-1', 'fileId' => 'old', 'file' => 'x.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'x'],
        ]),
        'reviewed_files' => '[]',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    $row = DB::table('comments')->where('id', 'c-range-1')->first();

    expect($row->origin_ref)->toBe('def456');
});

test('maps a single-commit context_fingerprint to that commit', function () {
    DB::table('review_sessions')->insert([
        'repo_path' => '/tmp/repo',
        'context_fingerprint' => 'solohash',
        'comments' => json_encode([
            ['id' => 'c-solo-1', 'fileId' => 'old', 'file' => 'x.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'x'],
        ]),
        'reviewed_files' => '[]',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    $row = DB::table('comments')->where('id', 'c-solo-1')->first();

    expect($row->origin_ref)->toBe('solohash');
});

test('migrates reviewed_files with their stored fingerprint', function () {
    DB::table('review_sessions')->insert([
        'repo_path' => '/tmp/repo',
        'context_fingerprint' => 'working',
        'comments' => '[]',
        'reviewed_files' => json_encode(['a.php' => 'hash-a', 'b.php' => 'hash-b']),
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    $rows = DB::table('reviewed_files')->get()->keyBy('file_path');

    expect($rows->get('a.php')->content_hash)->toBe('hash-a');
    expect($rows->get('b.php')->content_hash)->toBe('hash-b');
});

test('migrates legacy indexed-array reviewed_files with an empty content_hash', function () {
    DB::table('review_sessions')->insert([
        'repo_path' => '/tmp/repo',
        'context_fingerprint' => 'working',
        'comments' => '[]',
        'reviewed_files' => json_encode(['a.php', 'b.php']),
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    $rows = DB::table('reviewed_files')->get()->keyBy('file_path');

    expect($rows->get('a.php')->content_hash)->toBe('');
    expect($rows->get('b.php')->content_hash)->toBe('');
});

test('dedupes review_sessions rows keeping the longest global_comment', function () {
    DB::table('review_sessions')->insert([
        [
            'repo_path' => '/tmp/repo',
            'context_fingerprint' => 'working',
            'comments' => '[]',
            'reviewed_files' => '[]',
            'global_comment' => 'short',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'repo_path' => '/tmp/repo',
            'context_fingerprint' => 'abc..def',
            'comments' => '[]',
            'reviewed_files' => '[]',
            'global_comment' => 'the longer feedback survives',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->migration->up();

    $remaining = DB::table('review_sessions')->where('repo_path', '/tmp/repo')->get();
    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->global_comment)->toBe('the longer feedback survives');
});

test('drops legacy columns from review_sessions', function () {
    DB::table('review_sessions')->insert([
        'repo_path' => '/tmp/repo',
        'context_fingerprint' => 'working',
        'comments' => '[]',
        'reviewed_files' => '[]',
        'global_comment' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    expect(Schema::hasColumn('review_sessions', 'comments'))->toBeFalse();
    expect(Schema::hasColumn('review_sessions', 'reviewed_files'))->toBeFalse();
    expect(Schema::hasColumn('review_sessions', 'context_fingerprint'))->toBeFalse();
});

test('is a no-op when no legacy rows exist', function () {
    $this->migration->up();

    expect(DB::table('comments')->count())->toBe(0);
    expect(DB::table('reviewed_files')->count())->toBe(0);
});

test('preserves project_id when present on the legacy row', function () {
    $project = Project::create([
        'slug' => 'legacy',
        'name' => 'legacy',
        'path' => '/tmp/legacy',
        'git_common_dir' => '/tmp/legacy/.git',
        'is_worktree' => false,
    ]);

    DB::table('review_sessions')->insert([
        'project_id' => $project->id,
        'repo_path' => $project->path,
        'context_fingerprint' => 'working',
        'comments' => json_encode([
            ['id' => 'c-proj-1', 'fileId' => 'old', 'file' => 'x.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'x'],
        ]),
        'reviewed_files' => json_encode(['x.php' => 'hash-x']),
        'global_comment' => 'hello',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    expect(DB::table('comments')->where('id', 'c-proj-1')->value('project_id'))->toBe($project->id);
    expect(DB::table('reviewed_files')->where('file_path', 'x.php')->value('project_id'))->toBe($project->id);
});
