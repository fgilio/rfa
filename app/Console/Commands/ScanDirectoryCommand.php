<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ScanDirectoryAction;
use Illuminate\Console\Command;

class ScanDirectoryCommand extends Command
{
    protected $signature = 'rfa:scan {path}';

    protected $description = 'Scan a directory for git repositories and register any not yet tracked';

    public function handle(ScanDirectoryAction $action): int
    {
        $path = (string) $this->argument('path');

        try {
            $result = $action->handle($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result->found === 0) {
            $this->info('No git repositories found.');

            return self::SUCCESS;
        }

        if ($result->registered > 0) {
            $this->table(
                ['Name', 'Path', 'Branch'],
                collect($result->newProjects)->map(fn ($p) => [$p->name, $p->path, $p->branch]),
            );
        }

        $this->info("Found {$result->found} git repos: {$result->registered} newly registered, {$result->alreadyTracked} already tracked.");

        if ($result->failed > 0) {
            foreach ($result->errors as $errorPath => $message) {
                $this->warn("Failed: {$errorPath} - {$message}");
            }
        }

        return self::SUCCESS;
    }
}
