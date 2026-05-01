<?php

use App\Actions\ScanReviewFilesAction;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->action = app(ScanReviewFilesAction::class);
    $this->tempDir = $this->createTempDirectory('rfa_scan_review_');
    $this->rfaDir = $this->tempDir.'/.rfa';
});

test('returns empty array when .rfa/ directory does not exist', function () {
    $result = $this->action->handle($this->tempDir);

    expect($result)->toBe([]);
});

test('returns empty array when .rfa/ directory is empty', function () {
    File::makeDirectory($this->rfaDir, 0755, true);

    $result = $this->action->handle($this->tempDir);

    expect($result)->toBe([]);
});

test('discovers md review files', function () {
    File::makeDirectory($this->rfaDir, 0755, true);
    $basename = '20250115_143022_comments_AbCd1234';
    File::put($this->rfaDir."/{$basename}.md", '# Review');

    $result = $this->action->handle($this->tempDir);

    expect($result)->toHaveCount(1)
        ->and($result[0]['basename'])->toBe($basename)
        ->and($result[0]['mdFile'])->not->toBeNull()
        ->and($result[0]['mdFile']['path'])->toBe(".rfa/{$basename}.md")
        ->and($result[0]['mdFile']['id'])->toBe('file-'.hash('xxh128', ".rfa/{$basename}.md"))
        ->and($result[0]['mdFile']['isUntracked'])->toBeTrue();
});

test('ignores stray json files alongside md', function () {
    File::makeDirectory($this->rfaDir, 0755, true);
    $basename = '20250115_143022_comments_AbCd1234';
    File::put($this->rfaDir."/{$basename}.json", '{}');
    File::put($this->rfaDir."/{$basename}.md", '# Review');

    $result = $this->action->handle($this->tempDir);

    expect($result)->toHaveCount(1)
        ->and($result[0]['basename'])->toBe($basename)
        ->and($result[0]['mdFile']['path'])->toBe(".rfa/{$basename}.md");
});

test('sorts newest-first', function () {
    File::makeDirectory($this->rfaDir, 0755, true);
    $older = '20250115_100000_comments_OlDr1234';
    $newer = '20250116_100000_comments_NeWr5678';

    File::put($this->rfaDir."/{$older}.md", '# Older');
    File::put($this->rfaDir."/{$newer}.md", '# Newer');

    $result = $this->action->handle($this->tempDir);

    expect($result)->toHaveCount(2)
        ->and($result[0]['basename'])->toBe($newer)
        ->and($result[1]['basename'])->toBe($older);
});

test('ignores non-matching files in .rfa/', function () {
    File::makeDirectory($this->rfaDir, 0755, true);
    File::put($this->rfaDir.'/random.txt', 'not a review');
    File::put($this->rfaDir.'/notes.md', '# Notes');

    $result = $this->action->handle($this->tempDir);

    expect($result)->toBe([]);
});
