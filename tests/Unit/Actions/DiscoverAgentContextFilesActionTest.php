<?php

use App\Actions\DiscoverAgentContextFilesAction;
use App\DTOs\AgentContextFile;
use App\Enums\AgentContextFileKind;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->repo = $this->createTempDirectory('rfa_ctxdiscover_');
    $this->initTestRepo($this->repo);
});

test('returns AgentContextFile DTOs ordered by path', function () {
    File::put($this->repo.'/CLAUDE.md', "root\n");
    File::makeDirectory($this->repo.'/tests');
    File::put($this->repo.'/tests/AGENTS.md', "tests\n");
    $this->commitTestRepo($this->repo, 'init');

    $result = app(DiscoverAgentContextFilesAction::class)->handle($this->repo);

    expect($result)->toHaveCount(2);
    expect($result[0])->toBeInstanceOf(AgentContextFile::class);
    expect($result[0]->path)->toBe('CLAUDE.md');
    expect($result[0]->kind)->toBe(AgentContextFileKind::Claude);
    expect($result[1]->path)->toBe('tests/AGENTS.md');
    expect($result[1]->kind)->toBe(AgentContextFileKind::Agents);
});

test('returns empty array for repo with no context files', function () {
    File::put($this->repo.'/README.md', "readme\n");
    $this->commitTestRepo($this->repo, 'init');

    $result = app(DiscoverAgentContextFilesAction::class)->handle($this->repo);

    expect($result)->toBe([]);
});

test('AgentContextFile toArray exposes the diff-file shape fields', function () {
    File::put($this->repo.'/CLAUDE.md', "x\n");
    $this->commitTestRepo($this->repo, 'init');

    $result = app(DiscoverAgentContextFilesAction::class)->handle($this->repo);

    $array = $result[0]->toArray();
    expect($array)
        ->toHaveKey('id')
        ->toHaveKey('path')
        ->toHaveKey('absolutePath')
        ->toHaveKey('kind')
        ->toHaveKey('directory')
        ->toHaveKey('isTracked')
        ->toHaveKey('isSymlink')
        ->toHaveKey('symlinkTarget')
        ->toHaveKey('createdAt')
        ->toHaveKey('lastEditedAt')
        ->toHaveKey('lineCount');

    expect($array['id'])->toStartWith('ctx-');
    expect($array['kind'])->toBe('CLAUDE');
});
