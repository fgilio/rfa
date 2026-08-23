<?php

use App\DTOs\DiffLine;
use App\DTOs\FileDiff;
use App\DTOs\Hunk;
use App\DTOs\LoadedDiff;
use App\Enums\DiffLoadOutcome;
use App\Enums\LineType;

test('a loaded diff round-trips through the cache array', function () {
    $fileDiff = new FileDiff('src/App.php', 'modified', null, [
        new Hunk('@@ -1 +1 @@', 1, 1, 1, 1, [new DiffLine(LineType::Add, 'new', null, 1)]),
    ], 1, 0);

    $loaded = LoadedDiff::loaded($fileDiff, syntaxStyles: '.hl{color:red}', newFileLineCount: 12, syntaxHighlighter: 'phiki');
    $restored = LoadedDiff::tryFrom($loaded->toArray());

    expect($restored?->outcome)->toBe(DiffLoadOutcome::Loaded)
        ->and($restored?->syntaxStyles)->toBe('.hl{color:red}')
        ->and($restored?->newFileLineCount)->toBe(12)
        ->and($restored?->syntaxHighlighter)->toBe('phiki')
        ->and($restored?->toArray())->toBe($loaded->toArray());
});

test('every skip outcome round-trips with no hunks', function (LoadedDiff $skipped, DiffLoadOutcome $outcome) {
    $restored = LoadedDiff::tryFrom($skipped->toArray());

    expect($restored?->outcome)->toBe($outcome)
        ->and($restored?->hunks())->toBe([]);
})->with([
    'empty' => fn () => [LoadedDiff::empty('a.php'), DiffLoadOutcome::Empty],
    'too large' => fn () => [LoadedDiff::tooLarge('a.php'), DiffLoadOutcome::TooLarge],
    'unparsable' => fn () => [LoadedDiff::unparsable('a.php'), DiffLoadOutcome::Unparsable],
    'transient error' => fn () => [LoadedDiff::transientError('a.php'), DiffLoadOutcome::TransientError],
]);

test('only a transient error is uncacheable', function () {
    expect(DiffLoadOutcome::TransientError->isCacheable())->toBeFalse()
        ->and(DiffLoadOutcome::Loaded->isCacheable())->toBeTrue()
        ->and(DiffLoadOutcome::Empty->isCacheable())->toBeTrue()
        ->and(DiffLoadOutcome::TooLarge->isCacheable())->toBeTrue()
        ->and(DiffLoadOutcome::Unparsable->isCacheable())->toBeTrue();
});

test('unsupported cache entries read as a miss', function (mixed $entry) {
    expect(LoadedDiff::tryFrom($entry))->toBeNull();
})->with([
    'not an array' => 'stale',
    'null' => null,
    'no version' => [['outcome' => 'loaded', 'hunks' => []]],
    'another version' => [['cacheVersion' => LoadedDiff::VERSION + 1, 'outcome' => 'loaded', 'hunks' => []]],
    'unknown outcome' => [['cacheVersion' => LoadedDiff::VERSION, 'outcome' => 'partially-loaded', 'hunks' => []]],
    'missing outcome' => [['cacheVersion' => LoadedDiff::VERSION, 'hunks' => []]],
]);

test('an expanded rewrite stays a readable envelope', function () {
    $loaded = LoadedDiff::loaded(
        new FileDiff('src/App.php', 'modified', null, [], 0, 0),
        syntaxStyles: '.a{}',
        newFileLineCount: 40,
        syntaxHighlighter: 'phiki',
    );

    $expanded = $loaded->withExpandedHunks([['header' => '@@', 'lines' => []]], '.b{}');
    $restored = LoadedDiff::tryFrom($expanded->toArray());

    expect($restored?->outcome)->toBe(DiffLoadOutcome::Loaded)
        ->and($restored?->hunks())->toHaveCount(1)
        ->and($restored?->syntaxStyles)->toBe('.a{}.b{}')
        ->and($restored?->newFileLineCount)->toBe(40);
});
