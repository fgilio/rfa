<?php

use App\Listeners\HandleDeepLink;
use App\Providers\NativeAppServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Native\Desktop\Events\App\OpenedFromURL;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\Helpers\MainWindowNavigations;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, InteractsWithTestRepositories::class);

/**
 * One `./rfa` invocation announces itself twice — an inbox file drained at
 * boot and a deep link — so these cover the pair, not either transport alone.
 */
beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_delivery_repo_');
    $this->initTestRepo($this->repoPath);
    file_put_contents($this->repoPath.'/README.md', "seed\n");
    $this->commitTestRepo($this->repoPath, 'initial');

    $this->homePath = $this->createTempDirectory('rfa_delivery_home_');
    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->homePath;

    $this->inboxDir = NativeAppServiceProvider::inboxDir();
    File::ensureDirectoryExists($this->inboxDir);

    $this->navigations = MainWindowNavigations::capture();
});

afterEach(function () {
    if ($this->originalHome === null) {
        unset($_SERVER['HOME']);
    } else {
        $_SERVER['HOME'] = $this->originalHome;
    }
});

function writeInboxRequest(string $dir, string $requestId, string $path, ?string $mode = null): string
{
    $file = $dir.'/'.$requestId.'.path';

    File::put($file, $mode === null ? $path."\n" : $path."\n".$mode."\n");

    return $file;
}

function drainInbox(): void
{
    $processInbox = (new ReflectionClass(NativeAppServiceProvider::class))->getMethod('processInbox');
    $processInbox->setAccessible(true);
    $processInbox->invoke(new NativeAppServiceProvider);
}

test('inbox and deep-link delivery of one request produce one open and one navigation', function () {
    writeInboxRequest($this->inboxDir, '1755975000-4242', $this->repoPath);

    drainInbox();

    expect($this->navigations->all())->toHaveCount(1);

    app(HandleDeepLink::class)->handle(new OpenedFromURL(
        'rfa://open?path='.rawurlencode($this->repoPath).'&id=1755975000-4242'
    ));

    expect($this->navigations->all())->toHaveCount(1);
});

test('the deep link wins the claim when it arrives before the inbox is drained', function () {
    writeInboxRequest($this->inboxDir, '1755975000-4242', $this->repoPath);

    app(HandleDeepLink::class)->handle(new OpenedFromURL(
        'rfa://open?path='.rawurlencode($this->repoPath).'&id=1755975000-4242'
    ));

    expect($this->navigations->all())->toHaveCount(1);

    drainInbox();

    expect($this->navigations->all())->toHaveCount(1)
        ->and(File::glob($this->inboxDir.'/*.path'))->toBeEmpty();
});

test('two different requests each open once', function () {
    writeInboxRequest($this->inboxDir, '1755975000-1', $this->repoPath);

    drainInbox();

    app(HandleDeepLink::class)->handle(new OpenedFromURL(
        'rfa://open?path='.rawurlencode($this->repoPath).'&id=1755975000-2'
    ));

    expect($this->navigations->all())->toHaveCount(2);
});

test('only the latest inbox request is opened and the rest are discarded', function () {
    writeInboxRequest($this->inboxDir, '1755975000-1', '/does/not/exist');
    writeInboxRequest($this->inboxDir, '1755975000-2', $this->repoPath, 'context');

    drainInbox();

    expect($this->navigations->all())->toHaveCount(1)
        ->and($this->navigations->all()[0])->toEndWith('/context')
        ->and(File::glob($this->inboxDir.'/*.path'))->toBeEmpty();
});

test('a legacy inbox file and a path-only deep link both still open', function () {
    // Compatibility inputs: neither carries a shared identity, so each opens
    // on its own rather than being deduplicated against the other.
    writeInboxRequest($this->inboxDir, '1755975000-4242', $this->repoPath);

    drainInbox();

    app(HandleDeepLink::class)->handle(new OpenedFromURL(
        'rfa://open?path='.rawurlencode($this->repoPath)
    ));

    expect($this->navigations->all())->toHaveCount(2);
});
