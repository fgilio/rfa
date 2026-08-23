<?php

use App\Actions\OpenTerminalRequestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Native\Desktop\Facades\Window;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, InteractsWithTestRepositories::class);

beforeEach(function () {
    // The collaborators in this chain are all final, so there is nothing to
    // fake below the action: give it a real repository on disk and let it
    // register a real project.
    $this->repoPath = $this->createTempDirectory('rfa_terminal_request_repo_');
    $this->initTestRepo($this->repoPath);
    file_put_contents($this->repoPath.'/README.md', "seed\n");
    $this->commitTestRepo($this->repoPath, 'initial');

    $this->notARepoPath = $this->createTempDirectory('rfa_terminal_request_plain_');

    $this->capturedUrl = null;

    $window = Mockery::mock(Native\Desktop\Windows\Window::class);
    $window->shouldReceive('url')->andReturnUsing(function (string $url) {
        $this->capturedUrl = $url;
    });

    Window::shouldReceive('get')->with('main')->andReturn($window);

    $this->action = app(OpenTerminalRequestAction::class);
});

// -- claim --

test('a request without an id is always opened', function () {
    $project = $this->action->handle($this->repoPath);

    expect($project)->not->toBeNull()
        ->and($this->capturedUrl)->toBe(route('review-page', ['slug' => $project->slug]));
});

test('two claims on the same request id have exactly one winner', function () {
    $first = $this->action->handle($this->repoPath, null, '1755975000-4242');
    $this->capturedUrl = null;
    $second = $this->action->handle($this->repoPath, null, '1755975000-4242');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($this->capturedUrl)->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('request_already_claimed');
});

test('distinct request ids are claimed independently', function () {
    expect($this->action->handle($this->repoPath, null, '1755975000-1'))->not->toBeNull()
        ->and($this->action->handle($this->repoPath, null, '1755975000-2'))->not->toBeNull();
});

test('a claim is recorded in the cache under the request id', function () {
    $this->action->handle($this->repoPath, null, '1755975000-4242');

    expect(Cache::get('terminal-open-request:1755975000-4242'))->toBeTrue();
});

test('a malformed request id is treated as unidentified rather than claimed', function () {
    expect($this->action->handle($this->repoPath, null, 'not a valid/id'))->not->toBeNull()
        ->and($this->action->handle($this->repoPath, null, 'not a valid/id'))->not->toBeNull();
});

// -- open and navigation --

test('mode=context navigates to the context page', function () {
    $project = $this->action->handle($this->repoPath, 'context');

    expect($this->capturedUrl)->toBe(route('context-page', ['slug' => $project->slug]));
});

test('an unknown mode falls open to the review page', function () {
    $project = $this->action->handle($this->repoPath, 'junk');

    expect($this->capturedUrl)->toBe(route('review-page', ['slug' => $project->slug]));
});

test('a path that is not a project neither navigates nor reports a claim conflict', function () {
    expect($this->action->handle($this->notARepoPath, null, '1755975000-4242'))->toBeNull()
        ->and($this->capturedUrl)->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('not_a_git_repository');
});

// -- request identity --

test('the inbox request id is the filename stem', function () {
    expect(OpenTerminalRequestAction::inboxRequestId('/inbox/1755975000-4242.path'))
        ->toBe('1755975000-4242');
});

test('an inbox filename that is not a usable request id yields null', function () {
    expect(OpenTerminalRequestAction::inboxRequestId('/inbox/.path'))->toBeNull();
});

test('normalizing rejects ids outside the shape the helper emits', function () {
    expect(OpenTerminalRequestAction::normalizeRequestId('1755975000-4242'))->toBe('1755975000-4242')
        ->and(OpenTerminalRequestAction::normalizeRequestId('a/b'))->toBeNull()
        ->and(OpenTerminalRequestAction::normalizeRequestId(''))->toBeNull()
        ->and(OpenTerminalRequestAction::normalizeRequestId(null))->toBeNull()
        ->and(OpenTerminalRequestAction::normalizeRequestId(str_repeat('a', 129)))->toBeNull();
});

// -- inbox parser --

test('two-line inbox with context mode parses the mode', function () {
    expect(OpenTerminalRequestAction::parseInboxContents("/some/repo\ncontext\n"))
        ->toBe(['path' => '/some/repo', 'mode' => 'context']);
});

test('single-line legacy inbox file parses with no mode', function () {
    expect(OpenTerminalRequestAction::parseInboxContents("/some/repo\n"))
        ->toBe(['path' => '/some/repo', 'mode' => null]);

    expect(OpenTerminalRequestAction::parseInboxContents('/some/repo'))
        ->toBe(['path' => '/some/repo', 'mode' => null]);
});

test('two-line inbox with empty second line parses with no mode', function () {
    expect(OpenTerminalRequestAction::parseInboxContents("/some/repo\n\n"))
        ->toBe(['path' => '/some/repo', 'mode' => null]);
});

test('a junk mode is parsed through and routed to the review page (fail open)', function () {
    expect(OpenTerminalRequestAction::parseInboxContents("/some/repo\nxyz\n"))
        ->toBe(['path' => '/some/repo', 'mode' => 'xyz']);

    expect(OpenTerminalRequestAction::routeName('xyz'))->toBe('review-page');
});

test('trailing newline does not drop the mode line', function () {
    // `printf '%s\n%s\n'` always emits a trailing newline. Split-then-trim
    // per line keeps the mode intact even with extra blank lines after it.
    expect(OpenTerminalRequestAction::parseInboxContents("/some/repo\ncontext\n\n"))
        ->toBe(['path' => '/some/repo', 'mode' => 'context']);
});

test('CRLF line endings are handled the same as LF', function () {
    expect(OpenTerminalRequestAction::parseInboxContents("/some/repo\r\ncontext\r\n"))
        ->toBe(['path' => '/some/repo', 'mode' => 'context']);
});
