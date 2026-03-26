<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenRepositoryDialogAction;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\AutoUpdater;
use Native\Desktop\Facades\Window;

final readonly class HandleMenuItemClicked
{
    public function __construct(
        private OpenRepositoryDialogAction $openRepository,
    ) {}

    public function handle(MenuItemClicked $event): void
    {
        $id = $event->item['id'] ?? null;

        match ($id) {
            'open-repo' => $this->handleOpenRepo(),
            'check-updates' => $this->handleCheckUpdates(),
            default => null,
        };
    }

    private function handleCheckUpdates(): void
    {
        Cache::put('native-update-state', [
            'status' => 'checking',
            'startedAt' => now()->timestamp,
            'simulateTerminalState' => config('app.debug'),
        ], now()->addMinutes(2));

        AutoUpdater::checkForUpdates();
    }

    private function handleOpenRepo(): void
    {
        $project = $this->openRepository->handle();

        if ($project) {
            Window::get('main')->url(route('review-page', ['slug' => $project->slug]));
        }
    }
}
