<?php

use App\Actions\OpenProjectFromPathAction;
use App\Listeners\HandleDeepLink;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Window;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'rfa']);

    app()->bind(OpenProjectFromPathAction::class, fn () => new class($this->project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $path): ?Project
        {
            return $this->project;
        }
    });

    $this->capturedUrl = null;

    $window = Mockery::mock(Native\Desktop\Windows\Window::class);
    $window->shouldReceive('url')
        ->andReturnUsing(function (string $url) {
            $this->capturedUrl = $url;
        });

    Window::shouldReceive('get')->with('main')->andReturn($window);
});

test('routes to context-page when mode=context query param is set', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&mode=context'));

    expect($this->capturedUrl)->toBe(route('context-page', ['slug' => 'rfa']));
});

test('routes to review-page when mode is absent', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo'));

    expect($this->capturedUrl)->toBe(route('review-page', ['slug' => 'rfa']));
});

test('falls back to review-page when mode is anything other than context', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&mode=junk'));

    expect($this->capturedUrl)->toBe(route('review-page', ['slug' => 'rfa']));
});

test('ignores deep-links that are not rfa://open', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('https://example.com/anything'));

    expect($this->capturedUrl)->toBeNull();
});

test('ignores rfa:// deep-links with a different host', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://something-else?path=/some/repo'));

    expect($this->capturedUrl)->toBeNull();
});

test('ignores empty path values', function () {
    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path='));

    expect($this->capturedUrl)->toBeNull();
});

test('emits a canonical deeplink.opened event with completed outcome on success', function () {
    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.route'))->toBe('review-page')
        ->and(Context::get('rfa.project_slug'))->toBe('rfa')
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
    app()->bind(OpenProjectFromPathAction::class, fn () => new class
    {
        public function handle(string $path): ?Project
        {
            return null;
        }
    });

    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('rejected')
        ->and(Context::get('rfa.reason'))->toBe('not_a_project');
});

test('emits a canonical deeplink.opened event with error outcome when registration fails unexpectedly', function () {
    app()->bind(OpenProjectFromPathAction::class, fn () => new class
    {
        public function handle(string $path): ?Project
        {
            // Mirrors the real action's swallowed-Throwable branch.
            Context::add('rfa.reason', 'project_registration_failed');
            Context::add('rfa.error_class', RuntimeException::class);

            return null;
        }
    });

    Log::spy();

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo'));

    Log::shouldHaveReceived('info')->once()->with('deeplink.opened');
    expect(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.reason'))->toBe('project_registration_failed');
});

test('routes to review-page when OpenProjectFromPathAction returns null', function () {
    app()->bind(OpenProjectFromPathAction::class, fn () => new class
    {
        public function handle(string $path): ?Project
        {
            return null;
        }
    });

    app(HandleDeepLink::class)->handle(new OpenedFromURL('rfa://open?path=/some/repo&mode=context'));

    expect($this->capturedUrl)->toBeNull();
});
