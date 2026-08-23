<?php

use App\DTOs\FileSourceSpec;
use App\Services\ReviewConfigService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\Matcher\Closure;
use Pest\Browser\Browsable;
use Tests\Browser\Helpers\CreatesTestRepo;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

/**
 * Mockery matcher for a git FileSourceSpec pinned to a given ref and path.
 * The comment/anchor/reviewed actions resolve each diff side through
 * FileSourceSpec::forSide(), so the content-hash seam receives a spec
 * rather than loose ref/path arguments.
 */
function gitSourceSpec(string $ref, string $path): Closure
{
    return Mockery::on(fn ($source): bool => $source instanceof FileSourceSpec
        && $source->type === FileSourceSpec::TYPE_GIT
        && $source->ref === $ref
        && $source->path === $path);
}

/** Mockery matcher for an absolute FileSourceSpec pinned to a given on-disk path. */
function absoluteSourceSpec(string $absolutePath): Closure
{
    return Mockery::on(fn ($source): bool => $source instanceof FileSourceSpec
        && $source->type === FileSourceSpec::TYPE_ABSOLUTE
        && $source->absolutePath === $absolutePath);
}

/**
 * The effective review fingerprint every diff cache key is built from. Resolved
 * from a fresh service so a test's config changes are never masked by the
 * memoization on the container's singleton.
 */
function reviewFingerprint(): string
{
    return (new ReviewConfigService)->resolve()->cacheFingerprint();
}

/**
 * Write a benchmark snapshot to a temp file the suite cleans up.
 *
 * Accepts a raw string so a test can hand over malformed JSON, which is half of
 * what the snapshot decoder has to reject.
 */
function perfSnapshotFile(mixed $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'rfa-perf-snapshot-'.getmypid().'-');

    if ($path === false) {
        throw new RuntimeException('Unable to allocate benchmark snapshot path.');
    }

    file_put_contents($path, is_string($contents) ? $contents : json_encode($contents, JSON_THROW_ON_ERROR));

    return $path;
}

uses(TestCase::class, LazilyRefreshDatabase::class, Browsable::class, CreatesTestRepo::class)
    ->in('Browser');

uses(TestCase::class, LazilyRefreshDatabase::class)
    ->in('Performance');

uses(InteractsWithTestRepositories::class)
    ->in('Unit', 'Performance');

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/rfa-perf-snapshot-'.getmypid().'-*') ?: [] as $path) {
        @unlink($path);
    }

    if (method_exists($this, 'tearDownTrackedTestRepos')) {
        $this->tearDownTrackedTestRepos();
    }

    if (method_exists($this, 'cleanupTrackedTempDirectories')) {
        $this->cleanupTrackedTempDirectories();
    }
});
