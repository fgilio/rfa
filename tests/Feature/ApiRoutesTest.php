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
        ->assertStatus(500);
});
