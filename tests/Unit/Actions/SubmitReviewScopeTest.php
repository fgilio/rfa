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

test('submit only exports comments whose origin matches the selection and marks them submitted', function () {
    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];

    $inScope = [[
        'id' => 'c-in',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'in scope',
        'originRef' => 'target-head',
    ]];

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

    $outOfScope = [[
        'id' => 'c-out',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'out of scope',
        'originRef' => 'working',
    ]];

    $allComments = array_merge($inScope, $outOfScope);
    $target = DiffTarget::range('base', 'target-head');

    $result = app(ExportReviewAction::class)->handle($this->tmpDir, $allComments, '', $files, $target);

    expect(File::get($result['md']))->toContain('in scope');
    expect(File::get($result['md']))->not->toContain('out of scope');

    expect(Comment::find('c-in')->submitted_at)->not->toBeNull();
    expect(Comment::find('c-out')->submitted_at)->toBeNull();
});
