<?php

use App\Actions\LoadFileDiffAction;
use App\Console\Benchmark\DiffFixtureFactory;
use App\DTOs\DiffTarget;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->file = DiffFixtureFactory::fileEntry('src/Test.php');
    $this->diffData = DiffFixtureFactory::diffData(path: 'src/Test.php');

    // Prime cache so component doesn't try to load from git
    $cacheKey = DiffCacheKey::for(0, $this->file['id']);
    Cache::put($cacheKey, $this->diffData, 3600);

    // Mock LoadFileDiffAction so it never touches git
    app()->bind(LoadFileDiffAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null): array
        {
            return DiffFixtureFactory::diffData(path: $path);
        }
    });
});

function mountDiffFile(array $file, array $comments = [], bool $loadDiff = true): Testable
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

test('rendered HTML contains style block with syntax CSS', function () {
    $html = mountDiffFile($this->file)->html();

    expect($html)->toContain('<style>')
        ->and($html)->toContain('.hl-variable{color:#e36209;}')
        ->and($html)->toContain('.dark .hl-variable{color:#ffab70;}');
});

// -- File header rendering --

test('file header shows rename arrow when oldPath is set', function () {
    $file = DiffFixtureFactory::fileEntry('src/NewName.php');
    $file['oldPath'] = 'src/OldName.php';

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->toContain('src/OldName.php')
        ->and($html)->toContain('&rarr;')
        ->and($html)->toContain('src/NewName.php');
});

test('file header shows symlink target when file is symlink', function () {
    $file = DiffFixtureFactory::fileEntry('AGENTS.md');
    $file['isSymlink'] = true;
    $file['symlinkTarget'] = 'CLAUDE.md';

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->toContain('CLAUDE.md');
});

test('file header shows addition and deletion counts', function () {
    $file = DiffFixtureFactory::fileEntry('src/Test.php', additions: 15, deletions: 8);

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->toContain('+15')
        ->and($html)->toContain('-8');
});

test('file header hides zero additions and deletions', function () {
    $file = DiffFixtureFactory::fileEntry('src/Test.php', additions: 0, deletions: 0);

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->not->toContain('text-gh-green')
        ->and($html)->not->toContain('text-gh-red');
});

test('discard button renders when diffTo is null', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('discard-file');
});

test('discard button hidden when diffTo is set', function () {
    $component = Livewire::test('diff-file', [
        'file' => $this->file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
        'diffTo' => 'abc123',
    ]);

    expect($component->html())->not->toContain('discard-file');
});

test('discard button hidden for commented status', function () {
    $file = DiffFixtureFactory::fileEntry('src/Test.php', status: 'commented');

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->not->toContain('discard-file');
});

test('discard confirm mentions comment count', function () {
    $comments = [
        ['id' => 'c1', 'fileId' => $this->file['id'], 'file' => 'src/Test.php', 'side' => 'file', 'startLine' => null, 'endLine' => null, 'body' => 'A'],
        ['id' => 'c2', 'fileId' => $this->file['id'], 'file' => 'src/Test.php', 'side' => 'file', 'startLine' => null, 'endLine' => null, 'body' => 'B'],
    ];

    $html = mountDiffFile($this->file, $comments, loadDiff: false)->html();

    expect($html)->toContain('remove 2 comments');
});

test('collapse event handlers reset autoExpandedForComment', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('@collapse-all-files.window="autoExpandedForComment = false');
});

// -- Data attributes for auto-scroll selection --

test('added lines have data-line-new but not data-line-old', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<tr[^>]*bg-gh-add-bg[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-new="')
            ->and($row)->not->toContain('data-line-old="');
    }
});

test('removed lines have data-line-old but not data-line-new', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<tr[^>]*bg-gh-del-bg[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-old="')
            ->and($row)->not->toContain('data-line-new="');
    }
});

test('context lines have both data-line-new and data-line-old', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<tr[^>]*class="diff-line\s*"[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-new="')
            ->and($row)->toContain('data-line-old="');
    }
});
