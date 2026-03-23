<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenRepositoryDialogAction;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\Window;

final readonly class HandleMenuItemClicked
{
    public function __construct(
        private OpenRepositoryDialogAction $openRepository,
    ) {}

    public function handle(MenuItemClicked $event): void
    {
        $id = $event->item['id'] ?? null;

        if ($id === 'open-repo') {
            $project = $this->openRepository->handle();

            if ($project) {
                Window::get('main')->url(route('review-page', ['slug' => $project->slug]));
            }
        }
    }
}
