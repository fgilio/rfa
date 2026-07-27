<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommentAuthorType;
use App\Models\Comment;
use App\Models\CommentReply;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommentReply>
 */
class CommentReplyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => 'r-'.Str::ulid(),
            'comment_id' => Comment::factory(),
            'author_type' => CommentAuthorType::Human,
            'author_key' => 'rfa-ui',
            'author_label' => null,
            'body' => fake()->sentence(),
        ];
    }

    public function agent(string $key = 'codex-cli', ?string $label = 'Codex'): self
    {
        return $this->state(fn (): array => [
            'author_type' => CommentAuthorType::Agent,
            'author_key' => $key,
            'author_label' => $label,
        ]);
    }
}
