<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Pest\Browser\Browsable;
use Tests\Browser\Helpers\CreatesTestRepo;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, Browsable::class, CreatesTestRepo::class)
    ->in('Browser', 'Screenshots');

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
