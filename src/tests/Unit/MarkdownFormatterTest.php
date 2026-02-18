<?php

use App\DTOs\Comment;
use App\Enums\DiffSide;
use App\Services\MarkdownFormatter;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->formatter = new MarkdownFormatter;
});

test('formats heading and global comment', function () {
    $global = $this->faker->paragraph();

    $md = $this->formatter->format([], $global, []);

    expect($md)->toContain('# Code Review Comments');
    expect($md)->toContain("## General\n\n{$global}");
});

test('omits general section when global comment is empty', function () {
    $md = $this->formatter->format([], '', []);

    expect($md)->not->toContain('## General');
});

test('groups comments by file', function () {
    $fileA = $this->faker->word().'.php';
    do {
        $fileB = $this->faker->word().'.php';
    } while ($fileB === $fileA);

    $comments = [
        new Comment($this->faker->uuid(), 'file-1', $fileA, DiffSide::Right, 1, 1, 'comment A'),
        new Comment($this->faker->uuid(), 'file-2', $fileB, DiffSide::Right, 5, 5, 'comment B'),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain("## `{$fileA}`");
    expect($md)->toContain("## `{$fileB}`");
    expect($md)->toContain('comment A');
    expect($md)->toContain('comment B');
});

test('formats single line reference', function () {
    $line = $this->faker->numberBetween(1, 100);
    $comments = [
        new Comment($this->faker->uuid(), 'file-abc', 'f.php', DiffSide::Right, $line, $line, 'body'),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain("**Line {$line}**");
});

test('formats multi-line range', function () {
    $start = $this->faker->numberBetween(1, 50);
    $end = $start + $this->faker->numberBetween(1, 20);
    $comments = [
        new Comment($this->faker->uuid(), 'file-abc', 'f.php', DiffSide::Right, $start, $end, 'body'),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain("**Lines {$start}-{$end}**");
});

test('includes diff context snippet when available', function () {
    $snippet = '+added line';
    $comments = [
        new Comment('id', 'file-abc', 'f.php', DiffSide::Right, 10, 10, 'body'),
    ];

    $md = $this->formatter->format($comments, '', ['f.php:10:10' => $snippet]);

    expect($md)->toContain("```\n{$snippet}\n```");
});

test('handles file-level comment without line reference', function () {
    $body = $this->faker->sentence();
    $comments = [
        new Comment($this->faker->uuid(), 'file-abc', 'f.php', DiffSide::File, null, null, $body),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain($body);
    expect($md)->not->toContain('**Line');
});
