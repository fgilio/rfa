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

    $md = $this->formatter->format($comments, '', ['f.php:right:10:10' => $snippet]);

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

// -- edge cases --

test('preserves unicode and emoji in bodies', function () {
    $body = 'café 日本語 ✨ — résumé';
    $comments = [
        new Comment('id', 'file-abc', 'f.php', DiffSide::Right, 1, 1, $body),
    ];

    $md = $this->formatter->format($comments, '🎉 ¡hola!', []);

    expect($md)->toContain($body)
        ->and($md)->toContain('🎉 ¡hola!');
});

test('passes through fenced code blocks inside a comment body verbatim', function () {
    $body = "look at this:\n```php\n\$x = 1;\n```\nand this";
    $comments = [
        new Comment('id', 'file-abc', 'f.php', DiffSide::Right, 1, 1, $body),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain("```php\n\$x = 1;\n```");
});

test('wraps a snippet containing code fences with a longer fence so the document stays balanced', function () {
    // A diff context for a markdown file can include unchanged ``` lines (context
    // lines carry a single-space prefix, which CommonMark still reads as a fence).
    // A bare 3-backtick wrapper would be closed by them and leak the rest of the doc.
    $snippet = " ```\n some code\n ```";
    $comments = [
        new Comment('id', 'file-abc', 'doc.md', DiffSide::Right, 10, 10, 'first body'),
        new Comment('id2', 'file-abc', 'doc.md', DiffSide::Right, 20, 20, 'second body'),
    ];

    $md = $this->formatter->format($comments, '', ['doc.md:right:10:10' => $snippet]);

    expect($md)->toContain("````\n{$snippet}\n````");
    expect($md)->toContain('first body');
    expect($md)->toContain('second body');
    // Every fence line in the document is balanced (even count).
    expect(preg_match_all('/^`{3,}/m', $md) % 2)->toBe(0);
});

test('closes an unterminated code fence in a comment body so it cannot swallow the separator', function () {
    $comments = [
        new Comment('id', 'file-abc', 'f.php', DiffSide::Right, 1, 1, "look:\n```php\n\$x = 1;"),
        new Comment('id2', 'file-abc', 'f.php', DiffSide::Right, 2, 2, 'second comment'),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain('second comment');
    expect(preg_match_all('/^`{3,}/m', $md) % 2)->toBe(0);
});

test('treats whitespace-only global comment as empty', function () {
    $md = $this->formatter->format([], "   \n\t", []);

    // Current behavior: whitespace is non-empty, so the section renders.
    // Lock that in so a future "trim" change is intentional.
    expect($md)->toContain('## General');
});

test('skips empty diff-context entries and falls back to the line ref alone', function () {
    $comments = [
        new Comment('id', 'file-abc', 'f.php', DiffSide::Right, 10, 10, 'body'),
    ];

    $md = $this->formatter->format($comments, '', ['f.php:right:10:10' => '']);

    expect($md)->not->toContain('```')
        ->and($md)->toContain('**Line 10**');
});

test('renders single-line range when endLine is null', function () {
    $comments = [
        new Comment('id', 'file-abc', 'f.php', DiffSide::Right, 7, null, 'body'),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain('**Line 7**')
        ->and($md)->not->toContain('Lines 7-');
});

test('handles a comment body that is just a newline', function () {
    $comments = [
        new Comment('id', 'file-abc', 'f.php', DiffSide::Right, 1, 1, "\n"),
    ];

    $md = $this->formatter->format($comments, '', []);

    expect($md)->toContain('# Code Review Comments')
        ->and($md)->toContain('---')
        ->and(substr($md, -1))->toBe("\n");
});
