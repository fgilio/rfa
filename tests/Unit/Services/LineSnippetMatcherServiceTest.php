<?php

use App\Services\LineSnippetMatcherService;

beforeEach(function () {
    $this->matcher = new LineSnippetMatcherService;
});

test('locates a single-line snippet at its shifted position', function () {
    $content = "alpha\nbeta\nTARGET\ngamma\n";

    expect($this->matcher->shiftedLines($content, 'TARGET', 1))->toBe([3, 3]);
});

test('recovered range spans the snippet length, not the original line span', function () {
    // The original comment spanned lines 10-20 (11 lines) but the captured snippet
    // is only 3 lines. The recovered end must be start + 2, never start + 10.
    $content = "x\nA\nB\nC\ny\n";

    expect($this->matcher->shiftedLines($content, "A\nB\nC", 10))->toBe([2, 4]);
});

test('picks the occurrence closest to the original line', function () {
    $content = "T\nx\nx\nx\nx\nT\n";

    // Matches at lines 1 and 6; original line 5 is closer to 6.
    expect($this->matcher->shiftedLines($content, 'T', 5))->toBe([6, 6]);
    // Original line 2 is closer to 1.
    expect($this->matcher->shiftedLines($content, 'T', 2))->toBe([1, 1]);
});

test('ignores trailing whitespace differences when matching', function () {
    $content = "head\nbody line  \ntail\n";

    expect($this->matcher->shiftedLines($content, 'body line', 1))->toBe([2, 2]);
});

test('returns null when the snippet is absent', function () {
    expect($this->matcher->shiftedLines("a\nb\nc\n", 'missing', 1))->toBeNull();
});

test('returns null for empty or null snippet and null start line', function () {
    expect($this->matcher->shiftedLines("a\nb\n", null, 1))->toBeNull();
    expect($this->matcher->shiftedLines("a\nb\n", '', 1))->toBeNull();
    expect($this->matcher->shiftedLines("a\nb\n", 'a', null))->toBeNull();
});

test('returns null when the snippet is longer than the content', function () {
    expect($this->matcher->shiftedLines("a\n", "a\nb\nc", 1))->toBeNull();
});
