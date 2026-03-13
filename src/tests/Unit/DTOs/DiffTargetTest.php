<?php

use App\DTOs\DiffTarget;

// -- factory methods --

test('workingDirectory factory sets HEAD from and null to', function () {
    $target = DiffTarget::workingDirectory();

    expect($target->from())->toBe('HEAD')
        ->and($target->to())->toBeNull();
});

test('commit factory uses parent hash as from', function () {
    $target = DiffTarget::commit('abc123', 'parent1');

    expect($target->from())->toBe('parent1')
        ->and($target->to())->toBe('abc123');
});

test('commit factory uses empty tree hash when no parent', function () {
    $target = DiffTarget::commit('abc123');

    expect($target->from())->toBe(DiffTarget::EMPTY_TREE_HASH)
        ->and($target->to())->toBe('abc123');
});

test('range factory sets explicit from and to', function () {
    $target = DiffTarget::range('ref-a', 'ref-b');

    expect($target->from())->toBe('ref-a')
        ->and($target->to())->toBe('ref-b');
});

test('fromRefs returns working directory when to is null', function () {
    $target = DiffTarget::fromRefs('some-ref', null);

    expect($target->from())->toBe('HEAD')
        ->and($target->to())->toBeNull()
        ->and($target->isWorkingDirectory())->toBeTrue();
});

test('fromRefs returns range when to is provided', function () {
    $target = DiffTarget::fromRefs('ref-a', 'ref-b');

    expect($target->from())->toBe('ref-a')
        ->and($target->to())->toBe('ref-b');
});

// -- query methods --

test('isWorkingDirectory returns true when to is null', function () {
    expect(DiffTarget::workingDirectory()->isWorkingDirectory())->toBeTrue()
        ->and(DiffTarget::range('a', 'b')->isWorkingDirectory())->toBeFalse();
});

test('isImmutable returns true when to is set', function () {
    expect(DiffTarget::range('a', 'b')->isImmutable())->toBeTrue()
        ->and(DiffTarget::workingDirectory()->isImmutable())->toBeFalse();
});

// -- conversion methods --

test('contextKey returns working for working directory', function () {
    expect(DiffTarget::workingDirectory()->contextKey())->toBe('working');
});

test('contextKey returns from..to for commits', function () {
    expect(DiffTarget::range('abc', 'def')->contextKey())->toBe('abc..def');
});

test('toDiffArgs omits to for working directory', function () {
    expect(DiffTarget::workingDirectory()->toDiffArgs())->toBe(['diff', 'HEAD']);
});

test('toDiffArgs includes both refs for range', function () {
    expect(DiffTarget::range('a', 'b')->toDiffArgs())->toBe(['diff', 'a', 'b']);
});

test('cacheTtlHours returns 720 for immutable targets', function () {
    expect(DiffTarget::commit('abc', 'parent')->cacheTtlHours())->toBe(720);
});

test('toArray serializes from and to', function () {
    $target = DiffTarget::range('ref-a', 'ref-b');

    expect($target->toArray())->toBe(['from' => 'ref-a', 'to' => 'ref-b']);
});

test('toArray serializes null to for working directory', function () {
    expect(DiffTarget::workingDirectory()->toArray())->toBe(['from' => 'HEAD', 'to' => null]);
});
