<?php

use App\DTOs\CommitEntry;

test('toArray returns all fields', function () {
    $entry = new CommitEntry(
        hash: 'abc123def456abc123def456abc123def456abc1',
        shortHash: 'abc123d',
        message: 'Fix login bug',
        author: 'Jane Doe',
        relativeDate: '2 hours ago',
        date: '2025-01-15T14:30:22+00:00',
    );

    $array = $entry->toArray();

    expect($array)->toBe([
        'hash' => 'abc123def456abc123def456abc123def456abc1',
        'shortHash' => 'abc123d',
        'message' => 'Fix login bug',
        'author' => 'Jane Doe',
        'relativeDate' => '2 hours ago',
        'date' => '2025-01-15T14:30:22+00:00',
    ]);
});

test('properties are readonly', function () {
    $entry = new CommitEntry(
        hash: 'abc123',
        shortHash: 'abc',
        message: 'test',
        author: 'test',
        relativeDate: 'now',
        date: '2025-01-01',
    );

    $ref = new ReflectionProperty($entry, 'hash');

    expect($ref->isReadOnly())->toBeTrue();
});
