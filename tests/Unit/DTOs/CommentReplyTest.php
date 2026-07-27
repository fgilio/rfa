<?php

use App\DTOs\CommentAuthor;
use App\DTOs\CommentReply;
use App\Enums\CommentAuthorType;

test('normalizes database reply rows to the public camel-case contract', function () {
    $reply = CommentReply::fromArray([
        'id' => 'r-1',
        'comment_id' => 'c-1',
        'author_type' => 'agent',
        'author_key' => 'codex-cli',
        'author_label' => 'Codex',
        'body' => 'Done.',
        'created_at' => '2026-07-27 10:00:00',
        'updated_at' => '2026-07-27 10:01:00',
    ]);

    expect($reply->toArray())->toMatchArray([
        'id' => 'r-1',
        'commentId' => 'c-1',
        'authorType' => 'agent',
        'authorKey' => 'codex-cli',
        'authorLabel' => 'Codex',
        'body' => 'Done.',
    ])->and($reply->wasEdited())->toBeTrue();
});

test('human and agent authors have stable identities', function () {
    $human = CommentAuthor::human();
    $agent = CommentAuthor::agent('claude-code', 'Claude');

    expect($human->type)->toBe(CommentAuthorType::Human)
        ->and($human->key)->toBe('rfa-ui')
        ->and($agent->type)->toBe(CommentAuthorType::Agent)
        ->and($agent->key)->toBe('claude-code')
        ->and($agent->label)->toBe('Claude');
});

test('rejects invalid author identities', function (string $key, ?string $label) {
    CommentAuthor::agent($key, $label);
})->with([
    'blank key' => ['   ', null],
    'long key' => [str_repeat('a', 101), null],
    'long label' => ['agent', str_repeat('a', 101)],
])->throws(InvalidArgumentException::class);
