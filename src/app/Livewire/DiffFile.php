<?php

namespace App\Livewire;

use App\Actions\LoadFileDiffAction;
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

        $this->diffData = Cache::remember($this->diffCacheKey(), $ttl, fn () => app(LoadFileDiffAction::class)->handle($this->repoPath, $this->file['path'], $this->file['isUntracked'] ?? false)
                ?? ['hunks' => [], 'tooLarge' => false]
        );
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
