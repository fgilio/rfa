<?php

use App\DTOs\Comment;
use App\Services\CommentExporter;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->exporter = new CommentExporter;
    $this->tmpDir = sys_get_temp_dir().'/rfa_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('exports JSON with schema version', function () {
    $file = $this->faker->word().'.php';
    $line = $this->faker->numberBetween(1, 200);
    $body = $this->faker->sentence();
    $global = $this->faker->paragraph();

    $comments = [
        new Comment($this->faker->uuid(), $file, 'right', $line, $line, $body),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, $global);

    expect($result['json'])->toMatch('/\/\.rfa\/\d{8}_\d{6}_comments_/');
    expect(File::exists($result['json']))->toBeTrue();

    $json = json_decode(File::get($result['json']), true);
    expect($json['schema_version'])->toBe(1);
    expect($json['global_comment'])->toBe($global);
    expect($json['comments'])->toHaveCount(1);
    expect($json['comments'][0]['file'])->toBe($file);
    expect($json['comments'][0]['start_line'])->toBe($line);
    expect($json['comments'][0]['body'])->toBe($body);
    expect($json['markdown_file'])->toMatch('/^\.rfa\/\d{8}_\d{6}_comments_.*\.md$/');
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
        new Comment($this->faker->uuid(), $fileA, 'right', $lineA, $lineA, $bodyA),
        new Comment($this->faker->uuid(), $fileA, 'right', $lineB1, $lineB2, $bodyB),
        new Comment($this->faker->uuid(), $fileB, 'right', $lineC, $lineC, $bodyC),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, $global);

    $md = File::get($result['md']);
    expect($md)->toMatch('/^<!-- json: \.rfa\/\d{8}_\d{6}_comments_[a-f0-9]+\.json -->/');
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

    expect($result['clipboard'])->toMatch('/^review my comments on these changes in @\.rfa\/\d{8}_\d{6}_comments_.*\.md$/');
});

test('creates .rfa directory if missing', function () {
    $result = $this->exporter->export($this->tmpDir, [], '');

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

    expect(basename($result['json']))->toMatch('/^\d{8}_\d{6}_comments_[a-f0-9]{8}\.json$/');
    expect(basename($result['md']))->toMatch('/^\d{8}_\d{6}_comments_[a-f0-9]{8}\.md$/');
});

test('cross-references are consistent between files', function () {
    $result = $this->exporter->export($this->tmpDir, [], 'test');

    $json = json_decode(File::get($result['json']), true);
    expect($json['markdown_file'])->toBe('.rfa/'.basename($result['md']));

    $md = File::get($result['md']);
    $firstLine = strtok($md, "\n");
    preg_match('/<!-- json: (.+?) -->/', $firstLine, $matches);
    expect($matches[1])->toBe('.rfa/'.basename($result['json']));
});
