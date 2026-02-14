<?php

namespace App\Livewire;

use App\Services\DiffParser;
use App\Services\GitDiffService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class DiffFile extends Component
{
    /** @var array<string, mixed> */
    #[Locked]
    public array $file = [];

    #[Locked]
    public string $repoPath = '';

    #[Reactive]
    public bool $isViewed = false;

    /** @var array<int, array<string, mixed>> */
    #[Reactive]
    public array $fileComments = [];

    /** @var array<string, mixed>|null */
    protected ?array $diffData = null;

    public function hydrate(): void
    {
        $this->diffData = Cache::get($this->diffCacheKey());
    }

    public function dehydrate(): void
    {
        if ($this->diffData !== null) {
            $ttl = now()->addHours(config('rfa.cache_ttl_hours', 24));
            Cache::put($this->diffCacheKey(), $this->diffData, $ttl);
        }
    }

    public function loadFileDiff(): void
    {
        if ($this->diffData !== null) {
            return;
        }

        $ttl = now()->addHours(config('rfa.cache_ttl_hours', 24));

        $this->diffData = Cache::remember($this->diffCacheKey(), $ttl, function () {
            $gitDiff = app(GitDiffService::class);
            $rawDiff = $gitDiff->getFileDiff($this->repoPath, $this->file['path'], $this->file['isUntracked'] ?? false);

            if ($rawDiff === null || trim($rawDiff) === '') {
                return ['hunks' => [], 'tooLarge' => $rawDiff === null];
            }

            $parser = app(DiffParser::class);
            $fileDiff = $parser->parseSingle($rawDiff);

            if (! $fileDiff) {
                return ['hunks' => [], 'tooLarge' => false];
            }

            return [
                'hunks' => collect($fileDiff->hunks)->map(fn ($hunk) => [
                    'header' => $hunk->header,
                    'oldStart' => $hunk->oldStart,
                    'newStart' => $hunk->newStart,
                    'lines' => collect($hunk->lines)->map(fn ($line) => [
                        'type' => $line->type,
                        'content' => $line->content,
                        'oldLineNum' => $line->oldLineNum,
                        'newLineNum' => $line->newLineNum,
                    ])->all(),
                ])->all(),
                'tooLarge' => false,
            ];
        });
    }

    private function diffCacheKey(): string
    {
        return 'rfa_diff_'.md5($this->repoPath.':'.$this->file['id']);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.diff-file', [
            'diffData' => $this->diffData,
        ]);
    }
}
