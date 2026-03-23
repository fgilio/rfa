<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Alert;

final readonly class OpenRepositoryDialogAction
{
    public function __construct(
        private RegisterProjectAction $register,
    ) {}

    public function handle(): ?Project
    {
        $path = app(Dialog::class)
            ->title('Open Repository')
            ->folders()
            ->open();

        if (! $path) {
            return null;
        }

        try {
            return $this->register->handle($path);
        } catch (\RuntimeException) {
            Alert::new()
                ->type('warning')
                ->title('Not a Git Repository')
                ->show('The selected folder is not a git repository.');

            return null;
        }
    }
}
