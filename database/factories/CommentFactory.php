<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'project_id' => Project::factory(),
            'repo_path' => fn (array $attrs) => Project::find($attrs['project_id'])?->path ?? '/tmp/test-repo',
            'origin_ref' => 'working',
            'file_path' => 'a.php',
            'side' => 'right',
            'body' => fake()->sentence(),
        ];
    }

    /**
     * Match the shape ContextCommentWorkflowAction creates: c-prefixed ULID,
     * context-file origin_ref, and a CLAUDE.md file_path so re-anchor tests
     * can drop in without overriding the same fields every time.
     */
    public function context(): self
    {
        return $this->state(fn (): array => [
            'id' => 'c-'.Str::ulid(),
            'origin_ref' => Comment::ORIGIN_CONTEXT,
            'file_path' => 'CLAUDE.md',
            'start_line' => 2,
            'end_line' => 2,
        ]);
    }
}
