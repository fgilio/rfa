<?php

use App\DTOs\Comment;
use App\Enums\CommentExportKind;
use App\Enums\DiffSide;
use App\Services\CommentExporter;
use App\Services\MarkdownFormatter;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->exporter = new CommentExporter(new MarkdownFormatter);
    $this->tmpDir = $this->createTempDirectory('rfa_test_');
});

test('exports Markdown with file grouping', function () {
    $fileA = $this->faker->word().'.php';
    do {
        $fileB = $this->faker->word().'.php';
    } while ($fileB === $fileA);
    $bodyA = $this->faker->sentence();
    $bodyB = $this->faker->sentence();
    $bodyC = $this->faker->sentence();
    $global = $this->faker->paragraph();
    $lineA = $this->faker->numberBetween(1, 100);
    $lineB1 = $this->faker->numberBetween(101, 200);
    $lineB2 = $lineB1 + $this->faker->numberBetween(1, 20);
    $lineC = $this->faker->numberBetween(1, 100);

    $comments = [
        new Comment($this->faker->uuid(), 'file-1', $fileA, DiffSide::Right, $lineA, $lineA, $bodyA),
        new Comment($this->faker->uuid(), 'file-1', $fileA, DiffSide::Right, $lineB1, $lineB2, $bodyB),
        new Comment($this->faker->uuid(), 'file-2', $fileB, DiffSide::Right, $lineC, $lineC, $bodyC),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, $global);

    $md = File::get($result['md']);
    expect($md)->toContain('# Code Review Comments');
    expect($md)->toContain('## General');
    expect($md)->toContain($global);
    expect($md)->toContain("## `{$fileA}`");
    expect($md)->toContain("## `{$fileB}`");
    expect($md)->toContain("**Line {$lineA}**");
    expect($md)->toContain("**Lines {$lineB1}-{$lineB2}**");
});

test('returns clipboard text', function () {
    $result = $this->exporter->export($this->tmpDir, [], 'test');

    $expectedPrefix = 'address my comments on these changes in '.$this->tmpDir.'/.rfa/';
    expect($result['clipboard'])->toStartWith($expectedPrefix)
        ->and(substr($result['clipboard'], strlen($expectedPrefix)))->toMatch('/^\d{8}_\d{6}_comments_[a-zA-Z0-9]{8}\.md$/');
});

test('creates .rfa directory if missing', function () {
    $this->exporter->export($this->tmpDir, [], '');

    expect(File::isDirectory($this->tmpDir.'/.rfa'))->toBeTrue();
});

test('handles empty comments', function () {
    $result = $this->exporter->export($this->tmpDir, [], '');

    $md = File::get($result['md']);
    expect($md)->toContain('# Code Review Comments');
    expect($md)->not->toContain('## `');
});

test('uses timestamp prefix in filenames', function () {
    $result = $this->exporter->export($this->tmpDir, [], 'test');

    expect(basename($result['md']))->toMatch('/^\d{8}_\d{6}_comments_[a-zA-Z0-9]{8}\.md$/');
});

test('throws when the markdown write fails', function () {
    File::shouldReceive('ensureDirectoryExists')->andReturnTrue();
    File::shouldReceive('put')->andReturn(false);

    expect(fn () => $this->exporter->export($this->tmpDir, [], 'test'))
        ->toThrow(RuntimeException::class, 'Failed to write review file');
});

// -- edge cases --

test('preserves unicode and emoji round-trip in markdown', function () {
    $body = 'café 日本語 ✨';
    $comments = [
        new Comment('id', 'file-1', 'f.php', DiffSide::Right, 1, 1, $body),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, '🎉 hola');

    $md = File::get($result['md']);
    expect($md)->toContain($body)
        ->and($md)->toContain('🎉 hola');
});

test('preserves embedded fenced code blocks in markdown output', function () {
    $body = "see here:\n```php\n\$foo = 'bar';\n```";
    $comments = [
        new Comment('id', 'file-1', 'f.php', DiffSide::Right, 1, 1, $body),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, '');

    $md = File::get($result['md']);
    expect($md)->toContain("```php\n\$foo = 'bar';\n```");
});

test('exports multi-file diff context blocks under their respective headings', function () {
    $comments = [
        new Comment('id1', 'file-1', 'a.php', DiffSide::Right, 1, 1, 'body a'),
        new Comment('id2', 'file-2', 'b.php', DiffSide::Right, 2, 2, 'body b'),
    ];
    $context = [
        'a.php:right:1:1' => '+ snippet a',
        'b.php:right:2:2' => '+ snippet b',
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, '', $context);

    $md = File::get($result['md']);

    $aHeading = strpos($md, '## `a.php`');
    $bHeading = strpos($md, '## `b.php`');
    $aSnippet = strpos($md, '+ snippet a');
    $bSnippet = strpos($md, '+ snippet b');

    expect($aHeading)->not->toBeFalse()
        ->and($bHeading)->not->toBeFalse()
        ->and($aSnippet)->toBeGreaterThan($aHeading)
        ->and($aSnippet)->toBeLessThan($bHeading)
        ->and($bSnippet)->toBeGreaterThan($bHeading);
});

// -- kind parameter --

test('default review kind keeps the existing intro and clipboard text', function () {
    $result = $this->exporter->export($this->tmpDir, [], 'global');

    $md = File::get($result['md']);
    expect($md)->toContain('# Code Review Comments');
    expect($md)->not->toContain('# Agent Context Feedback');
    expect($result['clipboard'])->toStartWith('address my comments on these changes in '.$this->tmpDir.'/.rfa/');
});

test('context-file kind swaps intro, outro and clipboard prose', function () {
    $comments = [
        new Comment('id', 'file-1', 'CLAUDE.md', DiffSide::Right, 1, 1, 'tighten this'),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, 'general feedback', kind: CommentExportKind::ContextFile);

    $md = File::get($result['md']);
    expect($md)->toContain('# Agent Context Feedback');
    expect($md)->toContain('Improve the agent context files');
    expect($md)->toContain('## `CLAUDE.md`');
    expect($md)->toContain('tighten this');

    expect($result['clipboard'])->toStartWith('improve the agent context files based on my comments in '.$this->tmpDir.'/.rfa/');
});

test('handles an empty comment body without truncating later comments', function () {
    $comments = [
        new Comment('id1', 'file-1', 'f.php', DiffSide::Right, 1, 1, ''),
        new Comment('id2', 'file-1', 'f.php', DiffSide::Right, 5, 5, 'second body'),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, '');

    $md = File::get($result['md']);
    expect($md)->toContain('**Line 1**')
        ->and($md)->toContain('**Line 5**')
        ->and($md)->toContain('second body');
});
