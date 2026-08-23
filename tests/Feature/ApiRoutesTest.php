<?php

use App\Actions\CheckForChangesAction;
use App\Actions\GetProjectStatusAction;
use App\Actions\ServeImageAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

// -- /api/status --

test('status route returns json for valid project', function () {
    $project = Project::factory()->create();

    app()->bind(GetProjectStatusAction::class, fn () => new class
    {
        public function handle(string $repoPath, ?string $globalGitignorePath = null): array
        {
            return ['dirty' => true, 'fileCount' => 3, 'additions' => 10, 'deletions' => 2];
        }
    });

    $this->getJson("/api/status/{$project->id}")
        ->assertOk()
        ->assertJson(['dirty' => true, 'fileCount' => 3]);
});

test('status route returns 404 for missing project', function () {
    $this->getJson('/api/status/999')->assertNotFound();
});

// -- /api/changes --

test('changes route returns fingerprint and count json', function () {
    $project = Project::factory()->create();

    app()->bind(CheckForChangesAction::class, fn () => new class
    {
        public function handle(string $repoPath, ?string $globalGitignorePath = null): array
        {
            return ['fingerprint' => 'abc123fingerprint', 'count' => 7];
        }
    });

    $this->getJson("/api/changes/{$project->id}")
        ->assertOk()
        ->assertJson(['fingerprint' => 'abc123fingerprint', 'count' => 7]);
});

test('changes route returns 404 for missing project', function () {
    $this->getJson('/api/changes/999')->assertNotFound();
});

// -- /api/diagnostics/browser --

test('diagnostics route accepts validated browser samples', function () {
    $diagnosticsDir = sys_get_temp_dir().'/rfa-route-diagnostics-test-'.getmypid().'-'.uniqid('', true);
    $diagnosticsPath = $diagnosticsDir.'/diagnostics.jsonl';

    config([
        'rfa.diagnostics.enabled' => true,
        'rfa.diagnostics.path' => $diagnosticsPath,
    ]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => 'heartbeat',
        'url' => 'http://127.0.0.1:8100/p/acme/context?token=secret',
        'hidden' => false,
        'focused' => true,
        'includeProcessSnapshot' => false,
        'viewport' => ['width' => 1280, 'height' => 720, 'devicePixelRatio' => 2],
        'screen' => ['width' => 2560, 'height' => 1600, 'availWidth' => 2560, 'availHeight' => 1512],
        'visibility' => ['state' => 'visible', 'hidden' => false, 'focused' => true, 'focusAgeMs' => 100],
        'activity' => ['idleMs' => 12000, 'lastEvent' => 'wheel'],
        'scroll' => ['x' => 0, 'y' => 50, 'maxY' => 300],
        'dom' => ['nodes' => 100, 'livewireComponents' => 4, 'animatedElements' => 1],
        'animations' => [
            'activeCount' => 3,
            'runningCount' => 2,
            'cssAnimationCount' => 2,
            'cssTransitionCount' => 1,
            'classSummary' => [
                ['name' => 'animate-spin', 'count' => 2],
            ],
            'elementGroups' => [
                [
                    'signature' => 'svg.animate-spin',
                    'count' => 2,
                    'runningCount' => 2,
                    'animationNames' => ['spin'],
                    'classes' => ['animate-spin'],
                    'nearestLivewireName' => 'update-banner',
                    'nearestTestId' => 'update-banner',
                    'nearestInteractiveSignature' => 'button[data-testid="refresh-button"]',
                    'nearestButtonLabel' => 'Refresh changes',
                    'nearestButtonText' => 'Refresh',
                    'nearestButtonTitle' => 'Check changes',
                    'nearestButtonRole' => 'button',
                    'nearestButtonDisabled' => false,
                    'nearestLoading' => true,
                    'nearestWireClick' => 'softRefresh',
                    'nearestWireTarget' => 'softRefresh',
                ],
            ],
            'elements' => [
                [
                    'signature' => 'svg.animate-spin',
                    'tag' => 'svg',
                    'classes' => ['animate-spin'],
                    'animationNames' => ['spin'],
                    'playStates' => ['running'],
                    'animationCount' => 1,
                    'runningCount' => 1,
                    'maxDurationMs' => 1000,
                    'connected' => true,
                    'visible' => true,
                    'nearestLivewireId' => 'abc123',
                    'nearestLivewireName' => 'update-banner',
                    'nearestTestId' => 'update-banner',
                    'nearestInteractiveSignature' => 'button[data-testid="refresh-button"]',
                    'nearestButtonLabel' => 'Refresh changes',
                    'nearestButtonText' => 'Refresh',
                    'nearestButtonTitle' => 'Check changes',
                    'nearestButtonRole' => 'button',
                    'nearestButtonDisabled' => false,
                    'nearestLoading' => true,
                    'nearestWireClick' => 'softRefresh',
                    'nearestWireTarget' => 'softRefresh',
                    'rectX' => 10,
                    'rectY' => 20,
                    'rectWidth' => 16,
                    'rectHeight' => 16,
                    'computedDisplay' => 'block',
                    'computedVisibility' => 'visible',
                    'computedOpacity' => '1',
                    'computedPointerEvents' => 'auto',
                    'cssAnimationName' => 'spin',
                    'cssAnimationDuration' => '1s',
                    'cssAnimationPlayState' => 'running',
                ],
            ],
        ],
        'poll' => ['source' => 'wire:smart-poll:review-page', 'method' => 'poll', 'intervalMs' => 10000, 'ageMs' => 50],
        'timings' => [
            'diffAction' => ['action' => 'expandContext', 'elapsedMs' => 2500, 'phpMs' => 2200, 'diffLines' => 2247],
            'longTasks' => ['count' => 2, 'totalMs' => 120, 'maxMs' => 80],
            'livewireCommit' => [
                'status' => 'succeeded',
                'elapsedMs' => 70,
                'componentId' => 'abc123',
                'componentName' => 'pages::review-page',
                'callCount' => 1,
                'calls' => ['poll'],
                'updateCount' => 0,
                'updateKeys' => [],
                'pollSource' => 'wire:smart-poll:review-page',
                'pollMethod' => 'poll',
                'pollAgeMs' => 20,
            ],
        ],
    ])->assertNoContent();

    $entry = json_decode(trim((string) file_get_contents($diagnosticsPath)), true);

    expect($entry['event'])->toBe('browser.sample')
        ->and($entry['context']['path'])->toBe('/p/{project}/context')
        ->and($entry['context']['dom']['nodes'])->toBe(100)
        ->and($entry['context']['visibility']['state'])->toBe('visible')
        ->and($entry['context']['animations']['classSummary'][0]['name'])->toBe('animate-spin')
        ->and($entry['context']['animations']['elementGroups'][0]['nearestLivewireName'])->toBe('update-banner')
        ->and($entry['context']['animations']['elementGroups'][0]['nearestButtonLabel'])->toBe('Refresh changes')
        ->and($entry['context']['poll']['method'])->toBe('poll')
        ->and($entry['context']['timings']['diffAction']['elapsedMs'])->toBe(2500);

    foreach (glob($diagnosticsPath.'*') ?: [] as $path) {
        @unlink($path);
    }

    @rmdir($diagnosticsDir);
});

/**
 * routes/AGENTS.md: these routes are loopback-only plumbing inside the packaged
 * app, so no throttling. A dropped sample is a lost diagnostic, and the payload
 * guard in BrowserDiagnosticSampleRequest is what bounds the endpoint.
 */
test('diagnostics route is not rate limited', function () {
    $middleware = collect(Route::getRoutes()->getByName('api.diagnostics.browser')?->gatherMiddleware() ?? []);

    expect($middleware->filter(fn (mixed $entry): bool => str_contains((string) $entry, 'throttle')))->toBeEmpty();
});

test('diagnostics route refuses a payload past the configured budget', function () {
    config([
        'rfa.diagnostics.enabled' => true,
        'rfa.diagnostics.max_browser_payload_bytes' => 64,
    ]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => str_repeat('heartbeat', 50),
    ])->assertStatus(413);
});

test('diagnostics route rejects unknown browser sample fields', function () {
    config(['rfa.diagnostics.enabled' => true]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => 'heartbeat',
        'dom' => ['nodes' => 100, 'ignored' => 'rejected'],
    ])->assertUnprocessable();
});

test('diagnostics route rejects unknown top-level browser sample fields', function () {
    config(['rfa.diagnostics.enabled' => true]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => 'heartbeat',
        'cookies' => 'rejected',
    ])->assertUnprocessable();
});

test('diagnostics route rejects nested values outside their bounds', function () {
    config(['rfa.diagnostics.enabled' => true]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => 'heartbeat',
        'viewport' => ['width' => 99_999, 'height' => 720],
    ])->assertUnprocessable();
});

test('diagnostics route rejects unknown timing fields', function () {
    config(['rfa.diagnostics.enabled' => true]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => 'diff.action',
        'timings' => [
            'diffAction' => [
                'action' => 'expandContext',
                'elapsedMs' => 120,
                'unexpected' => 'rejected',
            ],
        ],
    ])->assertUnprocessable();
});

test('diagnostics route rejects unknown animation fields', function () {
    config(['rfa.diagnostics.enabled' => true]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => 'heartbeat',
        'animations' => [
            'elements' => [
                ['signature' => 'svg.animate-spin', 'text' => 'rejected'],
            ],
        ],
    ])->assertUnprocessable();
});

test('diagnostics route rejects oversized browser samples', function () {
    config([
        'rfa.diagnostics.enabled' => true,
        'rfa.diagnostics.max_browser_payload_bytes' => 128,
    ]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => str_repeat('x', 512),
    ])->assertStatus(413);
});

// -- /api/image --

test('image route returns 404 when image not found', function () {
    $project = Project::factory()->create();

    app()->bind(ServeImageAction::class, fn () => new class
    {
        public function handle(int $projectId, string $path, string $ref): ?array
        {
            return null;
        }
    });

    $this->get("/api/image/{$project->id}/HEAD/missing.png")->assertNotFound();
});

test('image route returns content with correct mime type', function () {
    $project = Project::factory()->create();

    app()->bind(ServeImageAction::class, fn () => new class
    {
        public function handle(int $projectId, string $path, string $ref): ?array
        {
            return ['content' => 'fake-png-data', 'mimeType' => 'image/png'];
        }
    });

    $response = $this->get("/api/image/{$project->id}/HEAD/test.png");

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->getContent())->toBe('fake-png-data');
});

test('image route rejects path traversal', function () {
    $project = Project::factory()->create();

    $this->get("/api/image/{$project->id}/HEAD/../../etc/passwd")
        ->assertNotFound();
});

test('image route preserves encoded special characters in paths', function (string $path) {
    $project = Project::factory()->create();
    $received = (object) ['path' => null, 'ref' => null];

    app()->bind(ServeImageAction::class, fn () => new class($received)
    {
        public function __construct(
            private readonly stdClass $received,
        ) {}

        public function handle(int $projectId, string $path, string $ref): ?array
        {
            $this->received->path = $path;
            $this->received->ref = $ref;

            return ['content' => 'fake-png-data', 'mimeType' => 'image/png'];
        }
    });

    $encodedPath = collect(explode('/', $path))
        ->map(fn (string $segment): string => rawurlencode($segment))
        ->implode('/');

    $this->get("/api/image/{$project->id}/HEAD/{$encodedPath}")
        ->assertOk();

    expect($received->path)->toBe($path)
        ->and($received->ref)->toBe('HEAD');
})->with([
    'space' => ['screenshots/has space.png'],
    'hash' => ['screenshots/has#hash.png'],
    'question' => ['screenshots/has?query.png'],
    'percent' => ['screenshots/has%percent.png'],
]);
