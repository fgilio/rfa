<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, InteractsWithTestRepositories::class);

function rfaTerminalHelperDataPath(string $homePath): string
{
    // Mirror the script's own `uname` branch so the test targets the same inbox
    // base the helper actually writes to: macOS uses Application Support, every
    // other platform (e.g. the Linux CI runner) uses the XDG data dir.
    return PHP_OS_FAMILY === 'Darwin'
        ? $homePath.'/Library/Application Support/com.fgilio.rfa'
        : $homePath.'/.local/share/com.fgilio.rfa';
}

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
    $appSupportPath = rfaTerminalHelperDataPath($this->homePath);
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

test('terminal helper replaces a stale app data file with the app data directory', function () {
    $appSupportPath = rfaTerminalHelperDataPath($this->homePath);

    File::makeDirectory(dirname($appSupportPath), 0755, true);
    File::put($appSupportPath, "/stale/app-data\n");

    $process = new Process([base_path('rfa'), $this->repoPath], base_path(), [
        'HOME' => $this->homePath,
        'PATH' => $this->fakeBinPath.':'.getenv('PATH'),
        'RFA_OPEN_CAPTURE' => $this->openCapturePath,
    ]);
    $process->mustRun();

    $inboxPath = $appSupportPath.'/inbox';
    $inboxFiles = File::glob($inboxPath.'/*.path');
    $backupFiles = File::glob($appSupportPath.'.*.bak');

    expect(File::isDirectory($appSupportPath))->toBeTrue()
        ->and(File::isDirectory($inboxPath))->toBeTrue()
        ->and($inboxFiles)->toHaveCount(1)
        ->and(File::get($inboxFiles[0]))->toBe(realpath($this->repoPath)."\n")
        ->and($backupFiles)->toHaveCount(1)
        ->and(File::get($backupFiles[0]))->toBe("/stale/app-data\n")
        ->and(File::get($this->openCapturePath))->toStartWith('rfa://open?path=');
});

test('terminal helper creates its directories without backups when nothing is stale', function () {
    $appSupportPath = rfaTerminalHelperDataPath($this->homePath);

    $process = new Process([base_path('rfa'), $this->repoPath], base_path(), [
        'HOME' => $this->homePath,
        'PATH' => $this->fakeBinPath.':'.getenv('PATH'),
        'RFA_OPEN_CAPTURE' => $this->openCapturePath,
    ]);
    $process->mustRun();

    expect(File::isDirectory($appSupportPath.'/inbox'))->toBeTrue()
        ->and(File::glob($appSupportPath.'/inbox/*.path'))->toHaveCount(1)
        ->and(File::glob(dirname($appSupportPath).'/*.bak'))->toBeEmpty()
        ->and(File::glob($appSupportPath.'/*.bak'))->toBeEmpty();
});

test('terminal helper leaves a blocked ancestor untouched and fails', function () {
    // `Application Support` (macOS) / `.local/share` (elsewhere) is the user's,
    // not rfa's. A non-directory there must be preserved, not renamed aside.
    $appDataBase = dirname(rfaTerminalHelperDataPath($this->homePath));

    File::makeDirectory(dirname($appDataBase), 0755, true);
    File::put($appDataBase, "user data, not ours\n");

    $process = new Process([base_path('rfa'), $this->repoPath], base_path(), [
        'HOME' => $this->homePath,
        'PATH' => $this->fakeBinPath.':'.getenv('PATH'),
        'RFA_OPEN_CAPTURE' => $this->openCapturePath,
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('rfa only repairs the paths it owns')
        ->and(File::isFile($appDataBase))->toBeTrue()
        ->and(File::get($appDataBase))->toBe("user data, not ours\n")
        ->and(File::glob($appDataBase.'.*.bak'))->toBeEmpty()
        ->and(File::glob(dirname($appDataBase).'/*.bak'))->toBeEmpty()
        ->and(File::exists($this->openCapturePath))->toBeFalse();
});
