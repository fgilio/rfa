<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('outputs project slug on success', function () {
    $repoPath = $this->createTempDirectory('rfa_cmd_register_');
    $this->initTestRepo($repoPath);
    File::put($repoPath.'/file.txt', 'hello');
    $this->commitTestRepo($repoPath, 'init');

    $this->artisan('rfa:register', ['path' => $repoPath])
        ->assertExitCode(0);
});

test('outputs error on invalid directory', function () {
    $nonGit = $this->createTempDirectory('rfa_cmd_nongit_');

    $this->artisan('rfa:register', ['path' => $nonGit])
        ->assertExitCode(1);
});
