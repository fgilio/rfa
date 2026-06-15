<?php

use App\DTOs\FileSourceSpec;
use App\DTOs\SourceText;
use App\Services\FileSourceService;
use App\Services\GitFileContentService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_file_source_test_');
    $this->initTestRepo($this->repoPath);

    File::put($this->repoPath.'/notes.txt', "committed\n");
    $this->commitTestRepo($this->repoPath, 'initial');

    $this->head = trim($this->runTestRepoCommand($this->repoPath, 'git rev-parse HEAD'));
    $this->service = new FileSourceService(new GitFileContentService(new GitProcessService));
});

test('fetch loads content from a git ref', function () {
    $text = $this->service->fetch($this->repoPath, FileSourceSpec::git($this->head, 'notes.txt'));

    expect($text->isLoaded())->toBeTrue()
        ->and($text->content)->toBe("committed\n")
        ->and($text->byteSize)->toBe(10);
});

test('fetch loads content from the working tree', function () {
    File::put($this->repoPath.'/notes.txt', "working\n");

    $text = $this->service->fetch($this->repoPath, FileSourceSpec::working('notes.txt'));

    expect($text->isLoaded())->toBeTrue()
        ->and($text->content)->toBe("working\n");
});

test('fetch loads content from an absolute path', function () {
    $directory = $this->createTempDirectory('rfa_file_source_absolute_test_');
    $absolutePath = $directory.'/external.md';

    File::put($absolutePath, "# External\n");

    $text = $this->service->fetch($this->repoPath, FileSourceSpec::absolute($absolutePath));

    expect($text->isLoaded())->toBeTrue()
        ->and($text->content)->toBe("# External\n");
});

test('fetch returns none for the none source', function () {
    $text = $this->service->fetch($this->repoPath, FileSourceSpec::none());

    expect($text->status)->toBe(SourceText::STATUS_NONE)
        ->and($text->content)->toBeNull();
});

test('fetch returns missing when the source cannot be read', function () {
    $text = $this->service->fetch($this->repoPath, FileSourceSpec::git($this->head, 'missing.txt'));

    expect($text->isMissing())->toBeTrue()
        ->and($text->content)->toBeNull();
});

test('fetch returns too large without content when source exceeds max bytes', function () {
    File::put($this->repoPath.'/notes.txt', str_repeat('x', 20));

    $text = $this->service->fetch($this->repoPath, FileSourceSpec::working('notes.txt'), maxBytes: 10);

    expect($text->isTooLarge())->toBeTrue()
        ->and($text->content)->toBeNull()
        ->and($text->byteSize)->toBe(20)
        ->and($text->skipReason)->toBe('source-too-large');
});

test('fetch skips oversized sources without reading their content', function () {
    $contentService = Mockery::mock(GitFileContentService::class);
    $contentService->shouldReceive('byteSizeForSource')
        ->with($this->repoPath, gitSourceSpec('working', 'huge.bin'))
        ->once()
        ->andReturn(5_000_000_000);
    $contentService->shouldNotReceive('contentForSource');

    $text = (new FileSourceService($contentService))
        ->fetch($this->repoPath, FileSourceSpec::working('huge.bin'), maxBytes: 1_048_576);

    expect($text->isTooLarge())->toBeTrue()
        ->and($text->content)->toBeNull()
        ->and($text->byteSize)->toBe(5_000_000_000);
});

test('fetch skips oversized absolute sources without reading their content', function () {
    $contentService = Mockery::mock(GitFileContentService::class);
    $contentService->shouldReceive('byteSizeForSource')
        ->with($this->repoPath, absoluteSourceSpec('/mnt/huge.iso'))
        ->once()
        ->andReturn(5_000_000_000);
    $contentService->shouldNotReceive('contentForSource');

    $text = (new FileSourceService($contentService))
        ->fetch($this->repoPath, FileSourceSpec::absolute('/mnt/huge.iso'), maxBytes: 1_048_576);

    expect($text->isTooLarge())->toBeTrue()
        ->and($text->content)->toBeNull();
});
