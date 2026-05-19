<?php

use App\Support\LogSanitizer;

test('returns first non-empty line of multi-line input', function () {
    $raw = "\n\nfatal: not a git repository\nfatal: cannot read tree\n";

    expect(LogSanitizer::summarize($raw))->toBe('fatal: not a git repository');
});

test('trims surrounding whitespace from the summary', function () {
    expect(LogSanitizer::summarize('   error happened   '))->toBe('error happened');
});

test('truncates with an ellipsis when above max length', function () {
    $line = str_repeat('a', 250);

    $summary = LogSanitizer::summarize($line);

    expect(mb_strlen($summary))->toBe(200)
        ->and(str_ends_with($summary, '...'))->toBeTrue();
});

test('respects a custom max length', function () {
    expect(LogSanitizer::summarize('abcdef', 4))->toBe('a...');
});

test('returns an empty string for null', function () {
    expect(LogSanitizer::summarize(null))->toBe('');
});

test('returns an empty string for whitespace-only input', function () {
    expect(LogSanitizer::summarize("   \n\t  "))->toBe('');
});

test('hashPath returns a stable hex digest', function () {
    $first = LogSanitizer::hashPath('/Users/alice/code/rfa');
    $second = LogSanitizer::hashPath('/Users/alice/code/rfa');

    expect($first)->toBe($second)
        ->and($first)->toMatch('/^[0-9a-f]{32}$/');
});

test('hashPath produces different hashes for different paths', function () {
    expect(LogSanitizer::hashPath('/Users/alice/code/rfa'))
        ->not->toBe(LogSanitizer::hashPath('/Users/alice/code/other'));
});
