<?php

use App\Actions\ExportReviewSnapshotAction;
use App\DTOs\DiffTarget;
use App\Models\Comment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_snapshot_export_test_');
    $this->initTestRepo($this->repoPath);

    File::put($this->repoPath.'/src.php', "<?php\n");
    $this->commitTestRepo($this->repoPath, 'initial');
});

test('exports review snapshot json under .rfa', function () {
    $fileId = 'file-'.hash('xxh128', 'src.php');
    $files = [
        ['id' => $fileId, 'path' => 'src.php', 'status' => 'modified', 'additions' => 2, 'deletions' => 1],
        ['id' => 'review-file', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0],
    ];
    $comments = [[
        'id' => 'comment-1',
        'fileId' => $fileId,
        'file' => 'src.php',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'check this',
    ]];

    $result = app(ExportReviewSnapshotAction::class)->handle(
        repoPath: $this->repoPath,
        files: $files,
        comments: $comments,
        globalComment: 'overall note',
        reviewedFiles: ['src.php' => 'content-hash'],
        target: DiffTarget::range('base', 'head'),
        sourceLabel: 'snapshot repo',
    );

    expect($result)->toHaveKeys(['json', 'clipboard', 'snapshot'])
        ->and($result['json'])->toStartWith($this->repoPath.'/.rfa/')
        ->and($result['json'])->toEndWith('.json')
        ->and(File::exists($result['json']))->toBeTrue()
        ->and($result['clipboard'])->toContain($result['json']);

    $payload = json_decode(File::get($result['json']), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schemaVersion'])->toBe(1)
        ->and($payload['sourceLabel'])->toBe('snapshot repo')
        ->and($payload['target'])->toBe(['from' => 'base', 'to' => 'head'])
        ->and($payload['files'])->toHaveCount(1)
        ->and($payload['files'][0]['id'])->toBe($fileId)
        ->and($payload['files'][0]['oldSource'])->toMatchArray(['type' => 'git', 'ref' => 'base', 'path' => 'src.php'])
        ->and($payload['files'][0]['newSource'])->toMatchArray(['type' => 'git', 'ref' => 'head', 'path' => 'src.php'])
        ->and($payload['files'][0]['oldSourceText']['status'])->toBe('missing')
        ->and($payload['files'][0]['newSourceText']['status'])->toBe('loaded')
        ->and($payload['files'][0]['newSourceText']['content'])->toBe("<?php\n")
        ->and($payload['comments'])->toBe($comments)
        ->and($payload['reviewedFileIds'])->toBe([$fileId])
        ->and($payload['reviewedFiles'])->toBe(['src.php' => 'content-hash'])
        ->and($payload['globalComment'])->toBe('overall note')
        ->and($payload['exportedAt'])->toBeString();
});

test('exporting snapshot does not mark comments submitted', function () {
    $comment = Comment::create([
        'id' => 'comment-pending',
        'repo_path' => $this->repoPath,
        'origin_ref' => 'working',
        'file_path' => 'src.php',
        'side' => 'right',
        'start_line' => 1,
        'end_line' => 1,
        'body' => 'still pending',
    ]);

    app(ExportReviewSnapshotAction::class)->handle(
        repoPath: $this->repoPath,
        files: [['id' => 'file-'.hash('xxh128', 'src.php'), 'path' => 'src.php', 'status' => 'modified']],
        comments: [$comment->toArray()],
    );

    expect($comment->fresh()->submitted_at)->toBeNull();
});

test('exporting snapshot substitutes malformed utf8 in json payload', function () {
    $result = app(ExportReviewSnapshotAction::class)->handle(
        repoPath: $this->repoPath,
        files: [[
            'id' => 'file-invalid',
            'path' => "bad-\xff.php",
            'status' => 'added',
            'isUntracked' => true,
        ]],
    );

    $json = File::get($result['json']);

    expect($json)->toContain('\ufffd');
});
