<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\LinkExternalPathAction;
use Illuminate\Console\Command;

class LinkExternalPathCommand extends Command
{
    protected $signature = 'rfa:link-path {project : Project slug, name, or id} {path : Absolute path to a directory or single file to link} {--label= : Optional display label (defaults to the path basename)}';

    protected $description = 'Link an external directory or single file to a project so it appears in review as a commentable entry';

    public function handle(LinkExternalPathAction $action): int
    {
        $reference = (string) $this->argument('project');
        $path = (string) $this->argument('path');
        $label = $this->option('label');

        $updated = $action->handleByReference($reference, $path, is_string($label) ? $label : null);

        if ($updated === null) {
            $this->error("Could not link path: project {$reference} not found, or path {$path} is not a file or directory.");

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
