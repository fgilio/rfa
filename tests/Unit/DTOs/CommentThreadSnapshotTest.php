<?php

use App\DTOs\CommentThreadSnapshot;

test('round trips the versioned root and reply envelope', function () {
    $snapshot = CommentThreadSnapshot::fromArray([
        'version' => 1,
        'comment' => [
            'id' => 'c-1',
            'fileId' => 'f-1',
            'file' => 'app/Foo.php',
            'body' => 'Root',
        ],
        'replies' => [[
            'id' => 'r-1',
            'commentId' => 'c-1',
            'authorType' => 'human',
            'authorKey' => 'rfa-ui',
            'body' => 'Reply',
        ]],
    ]);

    expect($snapshot->commentId())->toBe('c-1')
        ->and($snapshot->fileId())->toBe('f-1')
        ->and($snapshot->toArray()['replies'][0]['body'])->toBe('Reply')
        ->and($snapshot->toCommentArray()['replies'][0]['commentId'])->toBe('c-1');
});

test('accepts legacy raw comment payloads without replies', function () {
    $snapshot = CommentThreadSnapshot::fromArray([
        'id' => 'c-legacy',
        'file' => 'legacy.php',
        'body' => 'Legacy',
    ]);

    expect($snapshot->commentId())->toBe('c-legacy')
        ->and($snapshot->replies)->toBe([])
        ->and($snapshot->toArray()['version'])->toBe(1);
});

test('rejects unsupported snapshot versions', function () {
    CommentThreadSnapshot::fromArray([
        'version' => 2,
        'comment' => [
            'id' => 'c-1',
            'file' => 'app/Foo.php',
            'body' => 'Root',
        ],
    ]);
})->throws(InvalidArgumentException::class, 'Unsupported comment thread snapshot version: 2.');
