<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'slug' => $name,
            'name' => $name,
            'path' => '/tmp/'.$name,
            'git_common_dir' => '/tmp/'.$name.'/.git',
            'is_worktree' => false,
            'branch' => 'main',
            'global_gitignore_path' => null,
            'respect_global_gitignore' => true,
        ];
    }
}
