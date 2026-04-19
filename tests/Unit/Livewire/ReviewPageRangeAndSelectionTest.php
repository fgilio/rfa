<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetFileListAction;
use App\Actions\LoadCommitMetadataAction;
use App\Actions\ResolveCommitAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Services\GitFileContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->files = [];

    $this->project = $this->createTestProject([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/repo',
        'branch' => 'main',
        'global_gitignore_path' => null,
        'respect_global_gitignore' => false,
    ]);

    $project = $this->project;
    $files = $this->files;

    app()->bind(ResolveProjectAction::class, fn () => new class($project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $slug, bool $touch = false): ?array
        {
            return $this->project->toArray();
        }
    });

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return $this->files;
        }
    });

    app()->bind(RestoreSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, ?DiffTarget $target = null): array
        {
            return ['comments' => [], 'reviewedFiles' => [], 'globalComment' => '', 'orphanedPaths' => []];
        }
    });

    app()->bind(SaveSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $globalComment, ?int $projectId = null): void {}
    });

    app()->bind(BackfillGlobalGitignoreAction::class, fn () => new class
    {
        public function handle(int $projectId, string $repoPath): ?string
        {
            return null;
        }
    });

    app()->bind(LoadCommitMetadataAction::class, fn () => new class
    {
        /** @return array<string, mixed> */
        public function handle(string $repoPath, string $hash, string $parentHash): array
        {
            return [
                'shortHash' => substr($hash, 0, 7),
                'message' => 'range head commit',
                'author' => 'tester',
                'prevHash' => null,
                'nextHash' => null,
            ];
        }
    });

    app()->bind(ResolveCommitAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $hash): ?DiffTarget
        {
            return DiffTarget::commit($hash, $hash.'^');
        }
    });

    $gitFileContentMock = Mockery::mock(GitFileContentService::class);
    $gitFileContentMock->shouldReceive('hashAt')->andReturn('mock-hash');
    app()->instance(GitFileContentService::class, $gitFileContentMock);
});

// -- Range route mount --

test('mounting with /r/{from}..{to} sets diffFrom and diffTo explicitly', function () {
    $component = Livewire::test('pages::review-page', [
        'slug' => 'test-project',
        'from' => 'aaaa111',
        'to' => 'bbbb222',
    ]);

    expect($component->get('diffFrom'))->toBe('aaaa111');
    expect($component->get('diffTo'))->toBe('bbbb222');
});

test('mounting with only {ref} sets diffTo=ref and diffFrom=ref^', function () {
    $component = Livewire::test('pages::review-page', [
        'slug' => 'test-project',
        'ref' => 'branchname',
    ]);

    expect($component->get('diffFrom'))->toBe('branchname^');
    expect($component->get('diffTo'))->toBe('branchname');
});

test('mounting with {ref}/{baseRef} uses baseRef as diffFrom', function () {
    $component = Livewire::test('pages::review-page', [
        'slug' => 'test-project',
        'ref' => 'tip',
        'baseRef' => 'base^',
    ]);

    expect($component->get('diffFrom'))->toBe('base^');
    expect($component->get('diffTo'))->toBe('tip');
});

test('mounting with no params defaults to working directory (HEAD, null)', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);

    expect($component->get('diffFrom'))->toBe('HEAD');
    expect($component->get('diffTo'))->toBeNull();
});

// -- Selection badge label --

test('selection badge shows "working" when no commit is selected', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);

    $component->assertSee('working');
});

test('selection badge shows the short hash in single-commit mode', function () {
    $component = Livewire::test('pages::review-page', [
        'slug' => 'test-project',
        'hash' => 'abc1234567890',
    ]);

    $component->assertSee('abc1234');
});

test('selection badge shows from..to when an explicit range is set', function () {
    $component = Livewire::test('pages::review-page', [
        'slug' => 'test-project',
        'from' => 'aaaa111bbb',
        'to' => 'cccc222ddd',
    ]);

    $component->assertSee('aaaa111..cccc222');
});
