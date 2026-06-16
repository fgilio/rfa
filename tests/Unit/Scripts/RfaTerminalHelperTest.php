<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, InteractsWithTestRepositories::class);

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_terminal_helper_repo_');
    $this->homePath = $this->createTempDirectory('rfa_terminal_helper_home_');
    $this->fakeBinPath = $this->createTempDirectory('rfa_terminal_helper_bin_');
    $this->openCapturePath = $this->createTempDirectory('rfa_terminal_helper_capture_').'/open.txt';

    $this->initTestRepo($this->repoPath);

    File::put($this->fakeBinPath.'/open', <<<'SH'
#!/bin/sh
printf '%s\n' "$1" > "$RFA_OPEN_CAPTURE"
SH);
    chmod($this->fakeBinPath.'/open', 0755);
});

test('terminal helper replaces a stale inbox file with the inbox directory', function () {
    $appSupportPath = $this->homePath.'/Library/Application Support/com.fgilio.rfa';

    File::makeDirectory($appSupportPath, 0755, true);
    File::put($appSupportPath.'/inbox', "/stale/repo\n");

    $process = new Process([base_path('rfa'), $this->repoPath], base_path(), [
        'HOME' => $this->homePath,
        'PATH' => $this->fakeBinPath.':'.getenv('PATH'),
        'RFA_OPEN_CAPTURE' => $this->openCapturePath,
    ]);
    $process->mustRun();

    $inboxPath = $appSupportPath.'/inbox';
    $inboxFiles = File::glob($inboxPath.'/*.path');
    $backupFiles = File::glob($appSupportPath.'/inbox.*.bak');

    expect(File::isDirectory($inboxPath))->toBeTrue()
        ->and($inboxFiles)->toHaveCount(1)
        ->and(File::get($inboxFiles[0]))->toBe(realpath($this->repoPath)."\n")
        ->and($backupFiles)->toHaveCount(1)
        ->and(File::get($backupFiles[0]))->toBe("/stale/repo\n")
        ->and(File::get($this->openCapturePath))->toStartWith('rfa://open?path=');
});
