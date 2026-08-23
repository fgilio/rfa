<?php

use App\Actions\OpenTerminalRequestAction;
use App\Listeners\HandleDeepLink;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\App\OpenedFromURL;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * Stand in for the shared open action. Claiming, opening and navigating are
 * covered by `OpenTerminalRequestActionTest`; what matters here is that the
 * listener parses the URL into the arguments it delegates, and turns the
 * action's outcome into the right canonical event.
 *
 * @param  string|null  $failureReason  the `rfa.reason` the real action leaves
 *                                      behind when it returns null
 */
function fakeOpenTerminalRequestAction(object $test, ?Project $project, ?string $failureReason = null): void
{
    $test->delegated = [];

    app()->bind(OpenTerminalRequestAction::class, fn () => new class($test, $project, $failureReason)
    {
        public function __construct(
            private object $test,
            private ?Project $project,
            private ?string $failureReason,
        ) {}

        public function handle(string $path, ?string $mode = null, ?string $requestId = null): ?Project
        {
            $this->test->delegated[] = compact('path', 'mode', 'requestId');

            if ($this->failureReason !== null) {
                Context::add('rfa.reason', $this->failureReason);
            }

            return $this->project;
        }
    });
}

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'rfa']);

    fakeOpenTerminalRequestAction($this, $this->project);
});

// -- URL parsing and delegation --

test('delegates the path, mode and request id from the url', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&mode=context&id=1755975000-4242'));

    expect($this->delegated)->toBe([[
        'path' => '/some/repo',
        'mode' => 'context',
        'requestId' => '1755975000-4242',
    ]]);
});

test('delegates a null mode and null request id for a path-only legacy url', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo'));

    expect($this->delegated)->toBe([[
        'path' => '/some/repo',
        'mode' => null,
        'requestId' => null,
    ]]);
});

test('drops a request id that is not the shape the helper emits', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&id='.urlencode('../etc/passwd')));

    expect($this->delegated[0]['requestId'])->toBeNull();
});

test('passes an unknown mode through so the action can fail open', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&mode=junk'));

    expect($this->delegated[0]['mode'])->toBe('junk')
        ->and(Context::get('rfa.route'))->toBe('review-page');
});

test('ignores deep-links that are not rfa://open', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('https://example.com/anything'));

    expect($this->delegated)->toBeEmpty();
});

test('ignores rfa:// deep-links with a different host', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://something-else?path=/some/repo'));

    expect($this->delegated)->toBeEmpty();
});

test('ignores empty path values', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path='));

    expect($this->delegated)->toBeEmpty();
});

// -- canonical events --

test('emits a canonical deeplink.opened event with completed outcome on success', function () {
    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&id=1755975000-4242'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.route'))->toBe('review-page')
        ->and(Context::get('rfa.project_slug'))->toBe('rfa')
        ->and(Context::get('rfa.request_id'))->toBe('1755975000-4242')
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
});

test('emits a canonical deeplink.opened event with rejected outcome for non-rfa urls', function () {
    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('https://example.com/anything'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('rejected')
        ->and(Context::get('rfa.reason'))->toBe('unsupported_url');
});

test('emits a canonical deeplink.opened event with rejected outcome when the path is not a project', function () {
    fakeOpenTerminalRequestAction($this, null);

    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('rejected')
        ->and(Context::get('rfa.reason'))->toBe('not_a_project');
});

test('emits a canonical deeplink.opened event with skipped outcome when the inbox already claimed the request', function () {
    // Cold start: boot drained the inbox copy of this request, so the deep
    // link delivery of the same request must stand down. The request was
    // handled — by the other transport — so this is not a rejection.
    fakeOpenTerminalRequestAction($this, null, 'request_already_claimed');

    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&id=1755975000-4242'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('skipped')
        ->and(Context::get('rfa.reason'))->toBe('request_already_claimed');
});

test('emits a canonical deeplink.opened event with error outcome when registration fails unexpectedly', function () {
    // Mirrors the real action's swallowed-Throwable branch.
    fakeOpenTerminalRequestAction($this, null, 'project_registration_failed');

    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.reason'))->toBe('project_registration_failed');
});
