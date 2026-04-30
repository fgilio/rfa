<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\LinkExternalPathAction;
use Illuminate\Console\Command;

class LinkExternalPathCommand extends Command
{
    protected $signature = 'rfa:link-path {project : Project slug, name, or id} {path : Absolute directory path to link} {--label= : Optional display label (defaults to directory basename)}';

    protected $description = 'Link an external directory to a project so its files appear in review as commentable entries';

    public function handle(LinkExternalPathAction $action): int
    {
        $reference = (string) $this->argument('project');
        $path = (string) $this->argument('path');
        $label = $this->option('label');

        $updated = $action->handleByReference($reference, $path, is_string($label) ? $label : null);

        if ($updated === null) {
            $this->error("Could not link path: project {$reference} not found, or path {$path} is not a directory.");

            return self::FAILURE;
        }

        $this->info('Linked external paths:');
        $this->table(
            ['Label', 'Path'],
            collect($updated)->map(fn (array $row) => [$row['label'], $row['path']]),
        );

        return self::SUCCESS;
    }
}
