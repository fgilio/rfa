<?php

use App\Services\ProjectRankerService;

beforeEach(function () {
    $this->ranker = new ProjectRankerService;
});

test('returns null when nothing matches', function () {
    expect($this->ranker->rank('alpha', 'main', '/r/alpha', 'zebra'))->toBeNull();
});

test('ranks an exact name match best', function () {
    expect($this->ranker->rank('alpha', 'main', '/r/alpha', 'alpha'))->toBe(0);
});

test('tier-1 beats tier-2 on the same field', function () {
    $startsWith = $this->ranker->rank('alphabet', 'main', '/r/x', 'alpha');
    $substring = $this->ranker->rank('omegalpha', 'main', '/r/x', 'alpha');

    expect($startsWith)->toBeLessThan($substring);
});

test('name field outranks branch outranks path in every tier', function () {
    expect($this->ranker->rank('alphabet', 'beta', '/r/gamma', 'alpha'))->toBe(10)
        ->and($this->ranker->rank('zebra', 'alphabet', '/r/gamma', 'alpha'))->toBe(11)
        ->and($this->ranker->rank('zebra', 'main', '/r/alphabet', 'alpha'))->toBe(12)
        ->and($this->ranker->rank('xalphax', 'main', '/r/gamma', 'alpha'))->toBe(20)
        ->and($this->ranker->rank('zebra', 'xalphax', '/r/gamma', 'alpha'))->toBe(21)
        ->and($this->ranker->rank('zebra', 'main', '/r/xalphax', 'alpha'))->toBe(22);
});

test('matches word boundaries as tier-1 (not tier-2 substring)', function () {
    expect($this->ranker->rank('my-alpha-project', 'main', '/r/x', 'alpha'))->toBe(10);
});

test('is case-insensitive', function () {
    expect($this->ranker->rank('ALPHA', 'MAIN', '/R/x', 'alpha'))->toBe(0);
});
