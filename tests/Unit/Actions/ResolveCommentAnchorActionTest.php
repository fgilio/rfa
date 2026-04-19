<?php

use App\Actions\ResolveCommentAnchorAction;
use App\DTOs\DiffTarget;
use App\Services\GitFileContentService;
use Faker\Factory as Faker;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->gitFileContent = Mockery::mock(GitFileContentService::class);
    app()->instance(GitFileContentService::class, $this->gitFileContent);

    $this->action = app(ResolveCommentAnchorAction::class);
});

test('marks comment as placed when the stored hash matches the right side of the diff', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'f.php')->andReturn('old');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'f.php')->andReturn('new-match');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'end_line' => 1,
            'file_content_hash' => 'new-match',
            'body' => 'body',
            'origin_ref' => 'to-sha',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
    expect($result[0]['fileId'])->toBe('file-new');
});

test('marks comment as placed when the stored hash matches the left side of the diff', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'from-sha', 'f.php')->andReturn('old-match');
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'to-sha', 'f.php')->andReturn('new');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'left',
            'start_line' => 1,
            'file_content_hash' => 'old-match',
            'body' => 'body',
            'origin_ref' => 'to-sha',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::range('from-sha', 'to-sha'),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
});

test('marks comment as unplaced when the stored hash matches neither side', function () {
    $this->gitFileContent->shouldReceive('hashAt')->andReturn('something-else');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => 'stale-hash',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('unplaced');
});

test('marks legacy comments without stored hash as placed when the file is in the current diff', function () {
    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => null,
            'body' => 'legacy',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
});

test('marks comment as unplaced when the file is not in the current diff', function () {
    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'gone.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => 'some-hash',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'other.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('unplaced');
});

test('uses the working copy as the right side when the target has no `to`', function () {
    $this->gitFileContent->shouldReceive('hashAt')->with('/tmp/repo', 'HEAD', 'f.php')->andReturn('old');
    $this->gitFileContent->shouldReceive('hashAt')
        ->with('/tmp/repo', GitFileContentService::WORKING_REF, 'f.php')
        ->andReturn('working-match');

    $result = $this->action->handle(
        '/tmp/repo',
        [[
            'id' => 'c-1',
            'file_path' => 'f.php',
            'side' => 'right',
            'start_line' => 1,
            'file_content_hash' => 'working-match',
            'body' => 'body',
        ]],
        [['id' => 'file-new', 'path' => 'f.php']],
        DiffTarget::workingDirectory(),
    );

    expect($result[0]['anchorStatus'])->toBe('placed');
});
