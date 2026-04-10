<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewSession>
 */
class ReviewSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'repo_path' => '/tmp/'.fake()->unique()->slug(2),
            'context_fingerprint' => 'working',
            'reviewed_files' => [],
            'comments' => [],
            'global_comment' => '',
        ];
    }

    public function withComments(int $count = 1): static
    {
        return $this->state(fn () => [
            'comments' => collect(range(1, $count))->map(fn (int $i) => [
                'id' => 'c-'.fake()->unique()->uuid(),
                'fileId' => fake()->sha1(),
                'file' => 'file-'.$i.'.php',
                'side' => 'right',
                'startLine' => $i,
                'endLine' => $i,
                'body' => fake()->sentence(),
            ])->all(),
        ]);
    }
}
