<?php

use App\Enums\AgentContextFileKind;
use App\Services\AgentContextFileScannerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->repo = $this->createTempDirectory('rfa_ctxscan_');
    $this->initTestRepo($this->repo);
    $this->scanner = app(AgentContextFileScannerService::class);
});

function ctxScan(string $repo, AgentContextFileScannerService $scanner, array $extra = []): array
{
    return $scanner->scan($repo, $extra);
}

test('finds tracked CLAUDE.md and AGENTS.md ordered by path', function () {
    File::put($this->repo.'/CLAUDE.md', "root rules\n");
    File::makeDirectory($this->repo.'/app');
    File::put($this->repo.'/app/CLAUDE.md', "backend rules\n");
    File::put($this->repo.'/AGENTS.md', "agents root\n");
    $this->commitTestRepo($this->repo, 'init');

    $files = ctxScan($this->repo, $this->scanner);

    expect($files)->toHaveCount(3);
    expect(collect($files)->pluck('path')->all())->toBe([
        'AGENTS.md',
        'CLAUDE.md',
        'app/CLAUDE.md',
    ]);
    expect($files[0]->kind)->toBe(AgentContextFileKind::Agents);
    expect($files[1]->kind)->toBe(AgentContextFileKind::Claude);
    expect($files[0]->isTracked)->toBeTrue();
});

test('skips configured artifact dirs', function () {
    File::put($this->repo.'/CLAUDE.md', "root\n");
    File::makeDirectory($this->repo.'/nativephp/electron/dist/x', 0755, true);
    File::put($this->repo.'/nativephp/electron/dist/x/CLAUDE.md', "stale copy\n");
    File::makeDirectory($this->repo.'/vendor/foo', 0755, true);
    File::put($this->repo.'/vendor/foo/CLAUDE.md', "vendor copy\n");
    $this->commitTestRepo($this->repo, 'init');

    $files = ctxScan($this->repo, $this->scanner);

    expect(collect($files)->pluck('path')->all())->toBe(['CLAUDE.md']);
});

test('surfaces untracked but not gitignored files with isTracked=false', function () {
    File::put($this->repo.'/CLAUDE.md', "tracked\n");
    $this->commitTestRepo($this->repo, 'init');

    File::put($this->repo.'/AGENTS.md', "untracked\n");

    $files = ctxScan($this->repo, $this->scanner);

    expect($files)->toHaveCount(2);

    $byPath = collect($files)->keyBy('path');
    expect($byPath['CLAUDE.md']->isTracked)->toBeTrue();
    expect($byPath['AGENTS.md']->isTracked)->toBeFalse();
});

test('hides gitignored files', function () {
    File::put($this->repo.'/CLAUDE.md', "tracked\n");
    File::put($this->repo.'/.gitignore', "secrets/\n");
    $this->commitTestRepo($this->repo, 'init');

    File::makeDirectory($this->repo.'/secrets');
    File::put($this->repo.'/secrets/CLAUDE.md', "secret\n");

    $files = ctxScan($this->repo, $this->scanner);

    expect(collect($files)->pluck('path')->all())->toBe(['CLAUDE.md']);
});

test('basename impostors do not match', function () {
    File::put($this->repo.'/CLAUDE.md', "real\n");
    File::put($this->repo.'/MY_CLAUDE.md', "fake\n");
    File::put($this->repo.'/BAD_AGENTS.md', "fake\n");
    $this->commitTestRepo($this->repo, 'init');

    $files = ctxScan($this->repo, $this->scanner);

    expect(collect($files)->pluck('path')->all())->toBe(['CLAUDE.md']);
});

test('symlinked pairs are deduplicated via realpath', function () {
    File::put($this->repo.'/CLAUDE.md', "shared\n");
    symlink($this->repo.'/CLAUDE.md', $this->repo.'/AGENTS.md');
    $this->commitTestRepo($this->repo, 'init');

    $files = ctxScan($this->repo, $this->scanner);

    // Both git-tracked, but realpath collision keeps a single entry —
    // and we keep the shorter (= real) path, which alphabetically comes
    // after AGENTS.md but is the real file rather than the symlink.
    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('CLAUDE.md');
});

test('records git history dates for tracked files', function () {
    File::put($this->repo.'/CLAUDE.md', "v1\n");
    $this->commitTestRepo($this->repo, 'init');

    sleep(1);

    File::put($this->repo.'/CLAUDE.md', "v2\n");
    $this->commitTestRepo($this->repo, 'edit');

    $files = ctxScan($this->repo, $this->scanner);

    expect($files)->toHaveCount(1);
    expect($files[0]->createdAt)->not->toBeNull();
    expect($files[0]->lastEditedAt)->not->toBeNull();
    expect($files[0]->lastEditedAt->greaterThan($files[0]->createdAt))->toBeTrue();
});

test('untracked entries get filesystem mtime, no created date', function () {
    File::put($this->repo.'/CLAUDE.md', "x\n");

    $files = ctxScan($this->repo, $this->scanner);

    expect($files)->toHaveCount(1);
    expect($files[0]->isTracked)->toBeFalse();
    expect($files[0]->createdAt)->toBeNull();
    expect($files[0]->lastEditedAt)->not->toBeNull();
});

test('extra skip dirs override config defaults additively', function () {
    File::put($this->repo.'/CLAUDE.md', "root\n");
    File::makeDirectory($this->repo.'/docs');
    File::put($this->repo.'/docs/CLAUDE.md', "docs rules\n");
    $this->commitTestRepo($this->repo, 'init');

    $files = ctxScan($this->repo, $this->scanner, ['docs']);

    expect(collect($files)->pluck('path')->all())->toBe(['CLAUDE.md']);
});

test('records line count for discovered files', function () {
    File::put($this->repo.'/CLAUDE.md', "line1\nline2\nline3\n");
    $this->commitTestRepo($this->repo, 'init');

    $files = ctxScan($this->repo, $this->scanner);

    expect($files[0]->lineCount)->toBe(3);
});

// -- defensive git-date parsing --

test('parseGitDate returns null for empty tokens instead of now()', function () {
    $method = new ReflectionMethod(AgentContextFileScannerService::class, 'parseGitDate');
    $method->setAccessible(true);

    expect($method->invoke($this->scanner, ''))->toBeNull();
    expect($method->invoke($this->scanner, '   '))->toBeNull();
    expect($method->invoke($this->scanner, null))->toBeNull();
});

test('parseGitDate returns null for an unparseable token instead of throwing', function () {
    $method = new ReflectionMethod(AgentContextFileScannerService::class, 'parseGitDate');
    $method->setAccessible(true);

    expect($method->invoke($this->scanner, 'not-a-date'))->toBeNull();
});

test('parseGitDate parses a valid ISO author date', function () {
    $method = new ReflectionMethod(AgentContextFileScannerService::class, 'parseGitDate');
    $method->setAccessible(true);

    $parsed = $method->invoke($this->scanner, '2026-01-02T03:04:05+00:00');

    expect($parsed)->toBeInstanceOf(CarbonImmutable::class);
    expect($parsed->year)->toBe(2026);
});
