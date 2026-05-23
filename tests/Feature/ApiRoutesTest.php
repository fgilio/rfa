<?php

use App\Actions\CheckForChangesAction;
use App\Actions\GetProjectStatusAction;
use App\Actions\ServeImageAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        'dom' => ['nodes' => 100, 'livewireComponents' => 4],
    ])->assertNoContent();

    $entry = json_decode(trim((string) file_get_contents($diagnosticsPath)), true);

    expect($entry['event'])->toBe('browser.sample')
        ->and($entry['context']['path'])->toBe('/p/{project}/context')
        ->and($entry['context']['dom']['nodes'])->toBe(100);

    foreach (glob($diagnosticsPath.'*') ?: [] as $path) {
        @unlink($path);
    }

    @rmdir($diagnosticsDir);
});

test('diagnostics route rejects unknown browser sample fields', function () {
    config(['rfa.diagnostics.enabled' => true]);

    $this->postJson('/api/diagnostics/browser', [
        'reason' => 'heartbeat',
        'dom' => ['nodes' => 100, 'ignored' => 'rejected'],
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
