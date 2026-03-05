<?php

use App\Actions\LoadFileDiffAction;
use App\DTOs\DiffTarget;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Helpers\DiffFixtureGenerator;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->file = DiffFixtureGenerator::fileEntry('src/Test.php');
    $this->diffData = DiffFixtureGenerator::diffData(path: 'src/Test.php');

    // Prime cache so component doesn't try to load from git
    $cacheKey = DiffCacheKey::for(0, $this->file['id']);
    Cache::put($cacheKey, $this->diffData, 3600);

    // Mock LoadFileDiffAction so it never touches git
    app()->bind(LoadFileDiffAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null, string $theme = 'light'): array
        {
            return DiffFixtureGenerator::diffData(path: $path);
        }
    });
});

function mountDiffFile(array $file, array $comments = [], bool $loadDiff = true): \Livewire\Features\SupportTesting\Testable
{
    $component = Livewire::test('diff-file', [
        'file' => $file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => $comments,
    ]);

    if ($loadDiff) {
        $component->call('loadFileDiff');
    }

    return $component;
}

// -- File comment form placement --

test('file comment form renders before diff content', function () {
    $html = mountDiffFile($this->file)->html();

    $formPos = strpos($html, 'x-ref="fileCommentForm"');
    $tablePos = strpos($html, '<table');

    expect($formPos)->not->toBeFalse()
        ->and($tablePos)->not->toBeFalse()
        ->and($formPos)->toBeLessThan($tablePos);
});

test('file-level saved comments render at top of file body', function () {
    $comments = [[
        'id' => 'fc-1',
        'fileId' => $this->file['id'],
        'file' => 'src/Test.php',
        'side' => 'file',
        'startLine' => null,
        'endLine' => null,
        'body' => 'This file needs a refactor',
    ]];

    $html = mountDiffFile($this->file, $comments)->html();

    $commentPos = strpos($html, 'This file needs a refactor');
    $tablePos = strpos($html, '<table');

    expect($commentPos)->not->toBeFalse()
        ->and($tablePos)->not->toBeFalse()
        ->and($commentPos)->toBeLessThan($tablePos);
});

test('file-level saved comments use border-b class', function () {
    $comments = [[
        'id' => 'fc-2',
        'fileId' => $this->file['id'],
        'file' => 'src/Test.php',
        'side' => 'file',
        'startLine' => null,
        'endLine' => null,
        'body' => 'Border test comment',
    ]];

    $html = mountDiffFile($this->file, $comments)->html();

    // The comment-display component wraps the comment body in a div with the border class
    $commentPos = strpos($html, 'Border test comment');
    $borderPos = strrpos(substr($html, 0, $commentPos), 'border-b');

    expect($commentPos)->not->toBeFalse()
        ->and($borderPos)->not->toBeFalse();
});

test('comment count badge markup is present', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('x-text="$wire.fileComments.length"')
        ->and($html)->toContain('tabular-nums');
});

test('file comment button has x-ref', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('x-ref="fileCommentBtn"');
});

test('collapse event handlers reset autoExpandedForComment', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('@collapse-all-files.window="autoExpandedForComment = false');
});
