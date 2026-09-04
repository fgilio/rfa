<?php

use App\Listeners\HandleDeepLink;
use App\Providers\NativeAppServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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

test('cold-start inbox delivery opens and focuses a repository file', function () {
    $file = $this->repoPath.'/reports/audit.md';
    File::ensureDirectoryExists(dirname($file));
    File::put($file, "# Audit\n");
    writeInboxRequest($this->inboxDir, '1755975000-4242', $file);

    drainInbox();

    $navigation = $this->navigations->latest();
    parse_str((string) parse_url($navigation, PHP_URL_QUERY), $query);

    expect($this->navigations->all())->toHaveCount(1)
        ->and($query)->toBe(['file' => 'reports/audit.md'])
        ->and(Context::get('rfa.outcome'))->toBe('completed');
});

test('the deep link wins the claim when it arrives before the inbox is drained', function () {
    $inboxFile = writeInboxRequest($this->inboxDir, '1755975000-4242', $this->repoPath);

    app(HandleDeepLink::class)->handle(new OpenedFromURL(
        'rfa://open?path='.rawurlencode($this->repoPath).'&id=1755975000-4242'
    ));

    expect($this->navigations->all())->toHaveCount(1)
        ->and(File::exists($inboxFile))->toBeFalse();

    drainInbox();

    expect($this->navigations->all())->toHaveCount(1)
        ->and(File::glob($this->inboxDir.'/*.path'))->toBeEmpty();
});

test('successful deep-link delivery removes only its matching inbox request', function () {
    $matchingInboxFile = writeInboxRequest($this->inboxDir, '1755975000-1', $this->repoPath);
    $otherInboxFile = writeInboxRequest($this->inboxDir, '1755975000-2', $this->repoPath);

    app(HandleDeepLink::class)->handle(new OpenedFromURL(
        'rfa://open?path='.rawurlencode($this->repoPath).'&id=1755975000-1'
    ));

    expect(File::exists($matchingInboxFile))->toBeFalse()
        ->and(File::exists($otherInboxFile))->toBeTrue();
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

// -- canonical events --

test('draining a queued request emits a canonical inbox.opened event', function () {
    writeInboxRequest($this->inboxDir, '1755975000-4242', $this->repoPath, 'context');

    Log::spy();

    drainInbox();

    Log::shouldHaveReceived('info')->once()->with('inbox.opened');
    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.request_id'))->toBe('1755975000-4242')
        ->and(Context::get('rfa.route'))->toBe('context-page')
        ->and(Context::get('rfa.path_hash'))->toBe(hash('xxh128', $this->repoPath))
        ->and(Context::get('rfa.project_slug'))->not->toBeNull()
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
});

test('a duplicate request the deep link already claimed drains as skipped', function () {
    writeInboxRequest($this->inboxDir, '1755975000-4242', $this->repoPath);

    app(HandleDeepLink::class)->handle(new OpenedFromURL(
        'rfa://open?path='.rawurlencode($this->repoPath).'&id=1755975000-4242'
    ));

    writeInboxRequest($this->inboxDir, '1755975000-4242', $this->repoPath);

    Log::spy();

    drainInbox();

    Log::shouldHaveReceived('info')->once()->with('inbox.opened');
    expect(Context::get('rfa.outcome'))->toBe('skipped')
        ->and(Context::get('rfa.reason'))->toBe('request_already_claimed');
});

test('an inbox request for a non-repository drains as rejected', function () {
    writeInboxRequest($this->inboxDir, '1755975000-4242', '/does/not/exist');

    Log::spy();

    drainInbox();

    Log::shouldHaveReceived('info')->once()->with('inbox.opened');
    expect(Context::get('rfa.outcome'))->toBe('rejected')
        ->and(Context::get('rfa.reason'))->toBe('path_not_found');
});

test('an empty inbox stays silent', function () {
    Log::spy();

    drainInbox();

    Log::shouldNotHaveReceived('info');
});
