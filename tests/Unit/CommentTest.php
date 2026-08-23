<?php

use App\DTOs\Comment;
use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toArray returns camelCase keys for internal use', function () {
    $id = $this->faker->uuid();
    $fileId = 'file-'.$this->faker->uuid();
    $file = $this->faker->filePath();
    $side = $this->faker->randomElement(DiffSide::cases());
    $startLine = $this->faker->numberBetween(1, 500);
    $endLine = $this->faker->numberBetween($startLine, $startLine + 50);
    $body = $this->faker->paragraph();

    $comment = new Comment($id, $fileId, $file, $side, $startLine, $endLine, $body);
    $array = $comment->toArray();

    expect($array)->toBe([
        'id' => $id,
        'fileId' => $fileId,
        'file' => $file,
        'side' => $side->value,
        'originalSide' => $side->value,
        'startLine' => $startLine,
        'endLine' => $endLine,
        'body' => $body,
        'originRef' => 'working',
        'fileContentHash' => null,
        'lineSnippet' => null,
        'isDraft' => false,
        'submittedAt' => null,
        'anchorStatus' => 'placed',
        'createdAt' => null,
        'updatedAt' => null,
        'replies' => [],
    ]);
});

test('toArray handles null lines', function () {
    $comment = new Comment(
        $this->faker->uuid(),
        'file-abc',
        $this->faker->filePath(),
        DiffSide::File,
        null,
        null,
        $this->faker->sentence(),
    );

    $array = $comment->toArray();

    expect($array['startLine'])->toBeNull();
    expect($array['endLine'])->toBeNull();
});

test('toArray preserves special characters in body', function () {
    $body = "Line with <html> & \"quotes\" and 'apostrophes' \n\ttabs too";

    $comment = new Comment($this->faker->uuid(), 'file-abc', 'file.php', DiffSide::Right, 1, 1, $body);

    expect($comment->toArray()['body'])->toBe($body);
});

test('fromArray constructs from camelCase array', function () {
    $data = [
        'id' => $this->faker->uuid(),
        'fileId' => 'file-'.$this->faker->uuid(),
        'file' => 'src/app.php',
        'side' => 'left',
        'startLine' => 10,
        'endLine' => 15,
        'body' => 'test body',
    ];

    $comment = Comment::fromArray($data);

    expect($comment->id)->toBe($data['id'])
        ->and($comment->fileId)->toBe($data['fileId'])
        ->and($comment->file)->toBe('src/app.php')
        ->and($comment->side)->toBe(DiffSide::Left)
        ->and($comment->startLine)->toBe(10)
        ->and($comment->endLine)->toBe(15)
        ->and($comment->body)->toBe('test body');
});

test('fromArray rejects missing required fields', function (array $comment) {
    Comment::fromArray($comment);
})->with([
    'id' => [['file' => 'src/app.php', 'side' => 'right', 'body' => 'Body']],
    'file' => [['id' => 'c-1', 'side' => 'right', 'body' => 'Body']],
    'side' => [['id' => 'c-1', 'file' => 'src/app.php', 'body' => 'Body']],
    'body' => [['id' => 'c-1', 'file' => 'src/app.php', 'side' => 'right']],
])->throws(InvalidArgumentException::class);

test('side property is DiffSide enum', function () {
    $comment = new Comment($this->faker->uuid(), 'file-abc', 'f.php', DiffSide::Right, 1, 1, 'body');

    expect($comment->side)->toBeInstanceOf(DiffSide::class)
        ->and($comment->side)->toBe(DiffSide::Right);
});

test('properties are readonly', function () {
    $comment = new Comment($this->faker->uuid(), 'file-abc', 'f.php', DiffSide::Right, 1, 1, 'body');

    $ref = new ReflectionClass($comment);
    foreach ($ref->getProperties() as $prop) {
        expect($prop->isReadOnly())->toBeTrue("Property {$prop->getName()} should be readonly");
    }
});

test('fromArray reads the complete thread shape from a database row', function () {
    $comment = Comment::fromArray([
        'id' => 'c-1',
        'file_path' => 'src/app.php',
        'side' => 'right',
        'original_side' => 'left',
        'start_line' => 10,
        'end_line' => 15,
        'body' => 'body',
        'origin_ref' => 'context-file',
        'file_content_hash' => 'abc123',
        'line_snippet' => '$x = 1;',
        'is_draft' => 1,
        'submitted_at' => '2026-08-23T18:49:27+00:00',
        'anchorStatus' => 'unplaced',
        'created_at' => '2026-08-20T10:00:00+00:00',
        'updated_at' => '2026-08-21T11:00:00+00:00',
    ]);

    expect($comment->side)->toBe(DiffSide::Right)
        ->and($comment->originalSide)->toBe(DiffSide::Left)
        ->and($comment->originalSide())->toBe(DiffSide::Left)
        ->and($comment->originRef)->toBe('context-file')
        ->and($comment->isDraft)->toBeTrue()
        ->and($comment->anchorStatus)->toBe(AnchorStatus::Unplaced)
        ->and($comment->submittedAt)->toBe('2026-08-23T18:49:27+00:00')
        ->and($comment->createdAt)->toBe('2026-08-20T10:00:00+00:00')
        ->and($comment->updatedAt)->toBe('2026-08-21T11:00:00+00:00');
});

test('originalSide falls back to the current side when the anchor never moved', function () {
    $comment = Comment::fromArray([
        'id' => 'c-1',
        'file' => 'src/app.php',
        'side' => 'left',
        'body' => 'body',
    ]);

    expect($comment->originalSide)->toBeNull()
        ->and($comment->originalSide())->toBe(DiffSide::Left)
        ->and($comment->toArray()['originalSide'])->toBe('left');
});

test('an unknown anchor status degrades to placed rather than throwing', function () {
    expect(Comment::fromArray([
        'id' => 'c-1',
        'file' => 'f.php',
        'side' => 'right',
        'body' => 'body',
        'anchorStatus' => 'who-knows',
    ])->anchorStatus)->toBe(AnchorStatus::Placed);
});

test('timestamps normalize to ISO 8601 whatever format they arrive in', function () {
    $comment = Comment::fromArray([
        'id' => 'c-1',
        'file' => 'f.php',
        'side' => 'right',
        'body' => 'body',
        'created_at' => '2026-08-20 10:00:00',
        'updated_at' => new DateTimeImmutable('2026-08-21 11:00:00', new DateTimeZone('UTC')),
    ]);

    expect($comment->createdAt)->toBe('2026-08-20T10:00:00+00:00')
        ->and($comment->updatedAt)->toBe('2026-08-21T11:00:00+00:00');
});

test('toArray round trips through fromArray unchanged', function () {
    $original = Comment::fromArray([
        'id' => 'c-1',
        'fileId' => 'f-1',
        'file' => 'src/app.php',
        'side' => 'right',
        'originalSide' => 'left',
        'startLine' => 3,
        'endLine' => 4,
        'body' => 'body',
        'originRef' => 'context-file',
        'fileContentHash' => 'abc123',
        'lineSnippet' => 'snippet',
        'isDraft' => true,
        'submittedAt' => '2026-08-23T18:49:27+00:00',
        'anchorStatus' => 'unplaced',
        'createdAt' => '2026-08-20T10:00:00+00:00',
        'updatedAt' => '2026-08-21T11:00:00+00:00',
        'replies' => [[
            'id' => 'r-1',
            'commentId' => 'c-1',
            'authorType' => 'human',
            'authorKey' => 'rfa-ui',
            'body' => 'Reply',
        ]],
    ]);

    expect(Comment::fromArray($original->toArray())->toArray())->toBe($original->toArray());
});
