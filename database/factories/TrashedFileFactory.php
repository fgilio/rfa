<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscardOperation;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrashedFile>
 */
class TrashedFileFactory extends Factory
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
            'file_path' => 'src/'.fake()->unique()->slug(2).'.php',
            'operation' => DiscardOperation::ModificationReverted,
            'old_path' => null,
            'is_symlink' => false,
            'comments' => null,
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function renamed(string $oldPath = 'src/OldName.php'): static
    {
        return $this->state(fn () => [
            'operation' => DiscardOperation::RenameReverted,
            'old_path' => $oldPath,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
