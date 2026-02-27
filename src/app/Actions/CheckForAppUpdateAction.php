<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

final readonly class CheckForAppUpdateAction
{
    /** @return array{available: bool, count: int} */
    public function handle(?string $repoPath = null): array
    {
        $repoPath ??= base_path('..');
        $localSha = $this->getLocalSha($repoPath);

        if (! $localSha) {
            return ['available' => false, 'count' => 0];
        }

        return Cache::remember(
            "rfa:app-update:{$localSha}",
            now()->addMinutes(55),
            fn () => $this->check($localSha),
        );
    }

    private function getLocalSha(string $repoPath): ?string
    {
        $result = Process::timeout(5)->run(
            ['git', '-C', $repoPath, 'rev-parse', 'HEAD'],
        );

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output()) ?: null;
    }

    /** @return array{available: bool, count: int} */
    private function check(string $localSha): array
    {
        try {
            $repo = config('rfa.github_repo', 'fgilio/rfa');
            $response = Http::timeout(10)
                ->get("https://api.github.com/repos/{$repo}/compare/{$localSha}...main");

            if (! $response->successful()) {
                return ['available' => false, 'count' => 0];
            }

            $aheadBy = (int) $response->json('ahead_by', 0);

            return [
                'available' => $aheadBy > 0,
                'count' => $aheadBy,
            ];
        } catch (\Throwable) {
            return ['available' => false, 'count' => 0];
        }
    }
}
