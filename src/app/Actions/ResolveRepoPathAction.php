<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\File;

final readonly class ResolveRepoPathAction
{
    public function handle(): string
    {
        if (isset($_ENV['RFA_REPO_PATH'])) {
            return $_ENV['RFA_REPO_PATH'];
        }

        $repoPathFile = base_path('.rfa_repo_path');
        if (File::exists($repoPathFile)) {
            return trim(File::get($repoPathFile));
        }

        return getcwd();
    }
}
