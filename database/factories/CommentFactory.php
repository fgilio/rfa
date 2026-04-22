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
}
