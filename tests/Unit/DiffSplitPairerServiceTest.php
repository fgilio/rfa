<?php

use App\Services\DiffSplitPairerService;

beforeEach(function () {
    $this->pairer = new DiffSplitPairerService;
});

test('returns empty array for empty input', function () {
    expect($this->pairer->pair([]))->toBe([]);
});

test('emits context line on both sides', function () {
    $line = ['type' => 'context', 'content' => 'foo', 'oldLineNum' => 5, 'newLineNum' => 7];

    $rows = $this->pairer->pair([$line]);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['left'])->toBe($line);
    expect($rows[0]['right'])->toBe($line);
});

test('zips equal runs of removes and adds', function () {
    $lines = [
        ['type' => 'remove', 'content' => 'old1', 'oldLineNum' => 1, 'newLineNum' => null],
        ['type' => 'remove', 'content' => 'old2', 'oldLineNum' => 2, 'newLineNum' => null],
        ['type' => 'add', 'content' => 'new1', 'oldLineNum' => null, 'newLineNum' => 1],
        ['type' => 'add', 'content' => 'new2', 'oldLineNum' => null, 'newLineNum' => 2],
    ];

    $rows = $this->pairer->pair($lines);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['left']['content'])->toBe('old1');
    expect($rows[0]['right']['content'])->toBe('new1');
    expect($rows[1]['left']['content'])->toBe('old2');
    expect($rows[1]['right']['content'])->toBe('new2');
});

test('fills excess removes with null right', function () {
    $lines = [
        ['type' => 'remove', 'content' => 'old1', 'oldLineNum' => 1, 'newLineNum' => null],
        ['type' => 'remove', 'content' => 'old2', 'oldLineNum' => 2, 'newLineNum' => null],
        ['type' => 'add', 'content' => 'new1', 'oldLineNum' => null, 'newLineNum' => 1],
    ];

    $rows = $this->pairer->pair($lines);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['right']['content'])->toBe('new1');
    expect($rows[1]['left']['content'])->toBe('old2');
    expect($rows[1]['right'])->toBeNull();
});

test('fills excess adds with null left', function () {
    $lines = [
        ['type' => 'remove', 'content' => 'old1', 'oldLineNum' => 1, 'newLineNum' => null],
        ['type' => 'add', 'content' => 'new1', 'oldLineNum' => null, 'newLineNum' => 1],
        ['type' => 'add', 'content' => 'new2', 'oldLineNum' => null, 'newLineNum' => 2],
    ];

    $rows = $this->pairer->pair($lines);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['left']['content'])->toBe('old1');
    expect($rows[0]['right']['content'])->toBe('new1');
    expect($rows[1]['left'])->toBeNull();
    expect($rows[1]['right']['content'])->toBe('new2');
});

test('handles pure additions with no removes', function () {
    $lines = [
        ['type' => 'add', 'content' => 'new1', 'oldLineNum' => null, 'newLineNum' => 1],
        ['type' => 'add', 'content' => 'new2', 'oldLineNum' => null, 'newLineNum' => 2],
    ];

    $rows = $this->pairer->pair($lines);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['left'])->toBeNull();
    expect($rows[0]['right']['content'])->toBe('new1');
    expect($rows[1]['left'])->toBeNull();
    expect($rows[1]['right']['content'])->toBe('new2');
});

test('handles pure deletions with no adds', function () {
    $lines = [
        ['type' => 'remove', 'content' => 'old1', 'oldLineNum' => 1, 'newLineNum' => null],
        ['type' => 'remove', 'content' => 'old2', 'oldLineNum' => 2, 'newLineNum' => null],
    ];

    $rows = $this->pairer->pair($lines);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['left']['content'])->toBe('old1');
    expect($rows[0]['right'])->toBeNull();
    expect($rows[1]['left']['content'])->toBe('old2');
    expect($rows[1]['right'])->toBeNull();
});

test('preserves context lines surrounding change blocks', function () {
    $lines = [
        ['type' => 'context', 'content' => 'before', 'oldLineNum' => 1, 'newLineNum' => 1],
        ['type' => 'remove', 'content' => 'old', 'oldLineNum' => 2, 'newLineNum' => null],
        ['type' => 'add', 'content' => 'new', 'oldLineNum' => null, 'newLineNum' => 2],
        ['type' => 'context', 'content' => 'after', 'oldLineNum' => 3, 'newLineNum' => 3],
    ];

    $rows = $this->pairer->pair($lines);

    expect($rows)->toHaveCount(3);
    expect($rows[0]['left']['content'])->toBe('before');
    expect($rows[0]['right']['content'])->toBe('before');
    expect($rows[1]['left']['content'])->toBe('old');
    expect($rows[1]['right']['content'])->toBe('new');
    expect($rows[2]['left']['content'])->toBe('after');
    expect($rows[2]['right']['content'])->toBe('after');
});

test('handles multiple change blocks separated by context', function () {
    $lines = [
        ['type' => 'remove', 'content' => 'r1', 'oldLineNum' => 1, 'newLineNum' => null],
        ['type' => 'add', 'content' => 'a1', 'oldLineNum' => null, 'newLineNum' => 1],
        ['type' => 'context', 'content' => 'c', 'oldLineNum' => 2, 'newLineNum' => 2],
        ['type' => 'add', 'content' => 'a2', 'oldLineNum' => null, 'newLineNum' => 3],
    ];

    $rows = $this->pairer->pair($lines);

    expect($rows)->toHaveCount(3);
    expect($rows[0]['left']['content'])->toBe('r1');
    expect($rows[0]['right']['content'])->toBe('a1');
    expect($rows[1]['left']['content'])->toBe('c');
    expect($rows[1]['right']['content'])->toBe('c');
    expect($rows[2]['left'])->toBeNull();
    expect($rows[2]['right']['content'])->toBe('a2');
});
