<?php

use App\DTOs\CommentThreadSnapshot;
use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use App\Models\Comment;

test('round trips the versioned root and reply envelope', function () {
    $snapshot = CommentThreadSnapshot::fromArray([
        'version' => 1,
        'comment' => [
            'id' => 'c-1',
            'fileId' => 'f-1',
            'file' => 'app/Foo.php',
            'side' => 'right',
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
        'side' => 'right',
        'body' => 'Legacy',
    ]);

    expect($snapshot->commentId())->toBe('c-legacy')
        ->and($snapshot->replies())->toBe([])
        ->and($snapshot->toArray()['version'])->toBe(1);
});

test('rejects unsupported snapshot versions', function () {
    CommentThreadSnapshot::fromArray([
        'version' => 2,
        'comment' => [
            'id' => 'c-1',
            'file' => 'app/Foo.php',
            'side' => 'right',
            'body' => 'Root',
        ],
    ]);
})->throws(InvalidArgumentException::class, 'Unsupported comment thread snapshot version: 2.');

test('carries every field a restore needs instead of leaving them to be invented', function () {
    $comment = [
        'id' => 'c-1',
        'fileId' => 'f-1',
        'file' => 'app/Foo.php',
        'side' => 'right',
        'originalSide' => 'left',
        'startLine' => 12,
        'endLine' => 14,
        'body' => 'Root',
        'originRef' => Comment::ORIGIN_CONTEXT,
        'fileContentHash' => 'abc123',
        'lineSnippet' => '$x = 1;',
        'isDraft' => true,
        'submittedAt' => null,
        'anchorStatus' => 'unplaced',
        'createdAt' => '2026-08-20T10:00:00+00:00',
        'updatedAt' => '2026-08-21T11:00:00+00:00',
    ];

    $snapshot = CommentThreadSnapshot::fromArray(['version' => 1, 'comment' => $comment, 'replies' => []]);

    expect($snapshot->comment->side)->toBe(DiffSide::Right)
        ->and($snapshot->comment->originalSide())->toBe(DiffSide::Left)
        ->and($snapshot->comment->anchorStatus)->toBe(AnchorStatus::Unplaced)
        ->and($snapshot->comment->originRef)->toBe(Comment::ORIGIN_CONTEXT)
        ->and($snapshot->comment->isDraft)->toBeTrue()
        ->and($snapshot->comment->createdAt)->toBe('2026-08-20T10:00:00+00:00')
        ->and($snapshot->comment->updatedAt)->toBe('2026-08-21T11:00:00+00:00')
        ->and($snapshot->toArray()['comment'])->toBe($comment);
});

test('a full snapshot survives a serialize and reload unchanged', function () {
    $stored = CommentThreadSnapshot::fromArray([
        'version' => 1,
        'comment' => [
            'id' => 'c-1',
            'fileId' => 'f-1',
            'file' => 'app/Foo.php',
            'side' => 'right',
            'originalSide' => 'left',
            'startLine' => 12,
            'endLine' => 14,
            'body' => 'Root',
            'originRef' => Comment::ORIGIN_CONTEXT,
            'fileContentHash' => 'abc123',
            'lineSnippet' => '$x = 1;',
            'isDraft' => true,
            'anchorStatus' => 'unplaced',
            'createdAt' => '2026-08-20T10:00:00+00:00',
            'updatedAt' => '2026-08-21T11:00:00+00:00',
        ],
        'replies' => [
            ['id' => 'r-1', 'commentId' => 'c-1', 'authorType' => 'human', 'authorKey' => 'rfa-ui', 'body' => 'First'],
            ['id' => 'r-2', 'commentId' => 'c-1', 'authorType' => 'agent', 'authorKey' => 'claude', 'body' => 'Second'],
        ],
    ])->toArray();

    $reloaded = CommentThreadSnapshot::fromArray(json_decode(json_encode($stored), true));

    expect($reloaded->toArray())->toBe($stored)
        ->and(array_column($reloaded->toArray()['replies'], 'body'))->toBe(['First', 'Second']);
});

test('the comment sub-array holds the thread and replies stay at the envelope level', function () {
    $snapshot = CommentThreadSnapshot::fromArray([
        'version' => 1,
        'comment' => ['id' => 'c-1', 'file' => 'f.php', 'side' => 'right', 'body' => 'Root'],
        'replies' => [['id' => 'r-1', 'commentId' => 'c-1', 'authorType' => 'human', 'authorKey' => 'ui', 'body' => 'R']],
    ]);

    expect($snapshot->toArray()['comment'])->not->toHaveKey('replies')
        ->and($snapshot->toArray()['replies'])->toHaveCount(1)
        ->and($snapshot->toCommentArray()['replies'])->toHaveCount(1);
});

test('a payload with no origin ref adopts the surface default', function () {
    $snapshot = CommentThreadSnapshot::fromArray(
        ['id' => 'c-1', 'file' => 'f.php', 'side' => 'right', 'body' => 'Root'],
        Comment::ORIGIN_CONTEXT,
    );

    expect($snapshot->comment->originRef)->toBe(Comment::ORIGIN_CONTEXT);
});

test('a stored origin ref beats the surface default', function () {
    $snapshot = CommentThreadSnapshot::fromArray(
        ['id' => 'c-1', 'file' => 'f.php', 'side' => 'right', 'body' => 'Root', 'originRef' => 'working'],
        Comment::ORIGIN_CONTEXT,
    );

    expect($snapshot->comment->originRef)->toBe('working');
});
