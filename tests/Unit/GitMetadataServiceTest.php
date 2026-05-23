<?php

use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = new GitMetadataService(new GitProcessService);

    $this->tmpDir = $this->createTempDirectory('rfa_gitrepo_test_');
});

// -- resolveGlobalExcludesFile tests --

test('resolveGlobalExcludesFile returns string or null', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    // Result depends on host git config - just verify it returns the right type
    $result = $this->service->resolveGlobalExcludesFile($this->tmpDir);

    expect($result === null || is_string($result))->toBeTrue();
});

// -- getTopLevel tests --

test('getTopLevel returns repo root directory', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $subDir = $this->tmpDir.'/sub/dir';
    File::makeDirectory($subDir, 0755, true);

    $result = $this->service->getTopLevel($subDir);

    expect($result)->toBe(realpath($this->tmpDir));
});

// -- getCurrentBranch tests --

test('getCurrentBranch returns current branch name', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->getCurrentBranch($this->tmpDir))->toBe('main');
});

// -- getGitCommonDir tests --

test('getGitCommonDir returns empty string for regular repo', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->getGitCommonDir($this->tmpDir))->toBe('');
});

// -- getGitDir tests --

test('getGitDir returns .git path', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->getGitDir($this->tmpDir))->toBe(realpath($this->tmpDir.'/.git'));
});

// -- resolveRef tests --

test('resolveRef resolves HEAD', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $result = $this->service->resolveRef($this->tmpDir, 'HEAD');

    expect($result)->toHaveLength(40);
});

test('resolveRef resolves branch name', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $result = $this->service->resolveRef($this->tmpDir, 'main');

    expect($result)->toHaveLength(40);
});

test('resolveRef resolves short hash', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $fullHash = $this->service->resolveRef($this->tmpDir, 'HEAD');
    $short = substr($fullHash, 0, 7);

    expect($this->service->resolveRef($this->tmpDir, $short))->toBe($fullHash);
});

test('resolveRef returns null for invalid ref', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->resolveRef($this->tmpDir, 'nonexistent-ref-xyz'))->toBeNull();
});

test('resolveRef returns null for ref starting with dash', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->resolveRef($this->tmpDir, '--exec=bad'))->toBeNull();
});

test('resolveRefExpression expands short refs and preserves parent suffixes', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $fullHash = $this->service->resolveRef($this->tmpDir, 'HEAD');
    $shortHash = substr($fullHash, 0, 7);

    expect($this->service->resolveRefExpression($this->tmpDir, $shortHash))->toBe($fullHash)
        ->and($this->service->resolveRefExpression($this->tmpDir, $shortHash.'^'))->toBe($fullHash.'^');
});

test('resolveRefExpression leaves unresolvable refs unchanged', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->resolveRefExpression($this->tmpDir, 'missing-ref^'))->toBe('missing-ref^');
});

// -- getCommitParents tests --

test('getCommitParents returns parent hashes for a commit', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'second');

    $hash = $this->service->resolveRef($this->tmpDir, 'HEAD');
    $parents = $this->service->getCommitParents($this->tmpDir, $hash);

    expect($parents)->toHaveCount(1)
        ->and($parents[0])->toHaveLength(40);
});

test('getCommitParents returns empty array for root commit', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    $hash = $this->service->resolveRef($this->tmpDir, 'HEAD');
    $parents = $this->service->getCommitParents($this->tmpDir, $hash);

    expect($parents)->toBeEmpty();
});

// -- getMergeBase tests --

test('getMergeBase returns common ancestor of diverged branches', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'shared');

    $shared = $this->service->resolveRef($this->tmpDir, 'HEAD');

    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');
    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'feature only');

    $this->runTestRepoCommand($this->tmpDir, 'git checkout main');

    expect($this->service->getMergeBase($this->tmpDir, 'main', 'feature'))->toBe($shared);
});

test('getMergeBase returns the older ref when one is an ancestor of the other', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    $first = $this->service->resolveRef($this->tmpDir, 'HEAD');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'second');

    expect($this->service->getMergeBase($this->tmpDir, $first, 'HEAD'))->toBe($first);
});

test('getMergeBase returns null for unknown ref', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    expect($this->service->getMergeBase($this->tmpDir, 'HEAD', 'nonexistent-xyz'))->toBeNull();
});

test('getMergeBase returns null for ref starting with dash', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    expect($this->service->getMergeBase($this->tmpDir, 'HEAD', '--exec=bad'))->toBeNull();
});

// -- getChildCommit tests --

test('getChildCommit returns child hash', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    $parentHash = $this->service->resolveRef($this->tmpDir, 'HEAD');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'second');

    $childHash = $this->service->resolveRef($this->tmpDir, 'HEAD');

    expect($this->service->getChildCommit($this->tmpDir, $parentHash))->toBe($childHash);
});

test('getChildCommit returns null for latest commit', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    $hash = $this->service->resolveRef($this->tmpDir, 'HEAD');

    expect($this->service->getChildCommit($this->tmpDir, $hash))->toBeNull();
});

// -- getFileContent tests (moved from GitDiffServiceTest) --

test('getFileContent returns working directory content', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/data.txt', 'hello world');

    expect($this->service->getFileContent($this->tmpDir, 'data.txt'))->toBe('hello world');
});

test('getFileContent returns HEAD content via git show', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', 'original');
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/file.txt', 'modified');

    expect($this->service->getFileContent($this->tmpDir, 'file.txt', 'head'))->toBe('original');
});

test('getFileContent returns null for missing working file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->getFileContent($this->tmpDir, 'nonexistent.txt'))->toBeNull();
});

test('getFileContent returns null for file not in HEAD', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/untracked.txt', 'new');

    expect($this->service->getFileContent($this->tmpDir, 'untracked.txt', 'head'))->toBeNull();
});

// -- getBranches tests (moved from GitDiffServiceTest) --

test('getBranches returns current branch in local list', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $result = $this->service->getBranches($this->tmpDir);

    expect($result['local'])->toHaveCount(1)
        ->and($result['local'][0]->name)->toBe('main')
        ->and($result['local'][0]->isCurrent)->toBeTrue()
        ->and($result['local'][0]->isRemote)->toBeFalse();
});

test('getBranches returns multiple local branches', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $this->runTestRepoCommand($this->tmpDir, [
        'git branch feature-x',
        'git branch bugfix-y',
    ]);

    $result = $this->service->getBranches($this->tmpDir);
    $names = array_map(fn ($b) => $b->name, $result['local']);

    expect($names)->toContain('main')
        ->and($names)->toContain('feature-x')
        ->and($names)->toContain('bugfix-y');

    $current = array_filter($result['local'], fn ($b) => $b->isCurrent);
    expect($current)->toHaveCount(1);
    expect(array_values($current)[0]->name)->toBe('main');
});

test('getBranches returns empty remote list when no remotes', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $result = $this->service->getBranches($this->tmpDir);

    expect($result['remote'])->toBeEmpty();
});

// -- getCommitLog tests (moved from GitDiffServiceTest) --

test('getCommitLog returns commits for current branch', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first commit');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'second commit');

    $commits = $this->service->getCommitLog($this->tmpDir);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('second commit')
        ->and($commits[1]->message)->toBe('first commit');
});

test('getCommitLog respects limit parameter', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'second');

    File::put($this->tmpDir.'/file.txt', "v3\n");
    $this->commitTestRepo($this->tmpDir, 'third');

    $commits = $this->service->getCommitLog($this->tmpDir, limit: 2);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('third')
        ->and($commits[1]->message)->toBe('second');
});

test('getCommitLog respects offset parameter', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'first');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'second');

    File::put($this->tmpDir.'/file.txt', "v3\n");
    $this->commitTestRepo($this->tmpDir, 'third');

    $commits = $this->service->getCommitLog($this->tmpDir, limit: 50, offset: 1);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('second')
        ->and($commits[1]->message)->toBe('first');
});

test('getCommitLog returns commits for specific branch', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'main commit');

    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature-branch');
    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'feature commit');

    $this->runTestRepoCommand($this->tmpDir, 'git checkout main');

    $commits = $this->service->getCommitLog($this->tmpDir, branch: 'feature-branch');

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('feature commit')
        ->and($commits[1]->message)->toBe('main commit');
});

test('getCommitLog returns entries with all fields populated', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'test commit');

    $commits = $this->service->getCommitLog($this->tmpDir);

    expect($commits)->toHaveCount(1);
    $commit = $commits[0];
    expect($commit->hash)->toHaveLength(40)
        ->and($commit->shortHash)->not->toBeEmpty()
        ->and($commit->message)->toBe('test commit')
        ->and($commit->author)->toBe('RFA Test')
        ->and($commit->relativeDate)->not->toBeEmpty()
        ->and($commit->date)->not->toBeEmpty();
});

test('getCommitLog returns empty array for empty repo', function () {
    $this->initTestRepo($this->tmpDir);

    $commits = $this->service->getCommitLog($this->tmpDir);

    expect($commits)->toBeEmpty();
});
