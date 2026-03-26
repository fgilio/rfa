<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RegisterProjectAction;
use Illuminate\Console\Command;

class RegisterProjectCommand extends Command
{
    protected $signature = 'rfa:register {path}';

    protected $description = 'Register a project directory and output its slug';

    public function handle(RegisterProjectAction $action): int
    {
        $path = $this->argument('path');

        try {
            $project = $action->handle($path);
            $this->line($project->slug);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
