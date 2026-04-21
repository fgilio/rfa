<?php

use App\Actions\ResolveRangeAction;
use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_resolve_range_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/a.txt', "root\n");
    $this->commitTestRepo($this->tmpDir, 'root commit');
    $this->rootSha = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    File::put($this->tmpDir.'/b.txt', "child\n");
    $this->commitTestRepo($this->tmpDir, 'child commit');
    $this->childSha = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    $this->action = new ResolveRangeAction(new GitMetadataService(new GitProcessService));
});

test('falls back to empty tree when base is parent of the root commit', function () {
    $target = $this->action->handle($this->tmpDir, $this->rootSha.'^', $this->childSha);

    expect($target->from())->toBe(DiffTarget::EMPTY_TREE_HASH)
        ->and($target->to())->toBe($this->childSha);
});

test('passes through a normal <hash>^ base when the hash has a parent', function () {
    $target = $this->action->handle($this->tmpDir, $this->childSha.'^', $this->childSha);

    expect($target->from())->toBe($this->childSha.'^')
        ->and($target->to())->toBe($this->childSha);
});

test('derives base as <to>^ when from is null and applies the fallback', function () {
    $target = $this->action->handle($this->tmpDir, null, $this->rootSha);

    expect($target->from())->toBe(DiffTarget::EMPTY_TREE_HASH)
        ->and($target->to())->toBe($this->rootSha);
});

test('passes through a plain SHA base unchanged', function () {
    $target = $this->action->handle($this->tmpDir, $this->rootSha, $this->childSha);

    expect($target->from())->toBe($this->rootSha)
        ->and($target->to())->toBe($this->childSha);
});

test('passes through an unresolvable <ref>^ base without substitution', function () {
    $target = $this->action->handle($this->tmpDir, 'does-not-exist^', $this->childSha);

    expect($target->from())->toBe('does-not-exist^')
        ->and($target->to())->toBe($this->childSha);
});
