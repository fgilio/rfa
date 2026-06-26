<?php

use App\DTOs\FileSourceSpec;
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

uses(TestCase::class, LazilyRefreshDatabase::class, Browsable::class, CreatesTestRepo::class)
    ->in('Browser');

uses(TestCase::class, LazilyRefreshDatabase::class)
    ->in('Performance');

uses(InteractsWithTestRepositories::class)
    ->in('Unit', 'Performance');

afterEach(function () {
    if (method_exists($this, 'tearDownTrackedTestRepos')) {
        $this->tearDownTrackedTestRepos();
    }

    if (method_exists($this, 'cleanupTrackedTempDirectories')) {
        $this->cleanupTrackedTempDirectories();
    }
});
