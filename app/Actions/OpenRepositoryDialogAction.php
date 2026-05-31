<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\NotAGitRepositoryException;
use App\Models\Project;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Alert;
use Throwable;

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
        } catch (NotAGitRepositoryException) {
            Alert::new()
                ->type('warning')
                ->title('Not a Git Repository')
                ->show('The selected folder is not a git repository.');

            return null;
        } catch (Throwable $e) {
            // A real failure (e.g. a database error) — don't mislabel it as
            // "not a git repository".
            Alert::new()
                ->type('warning')
                ->title('Could Not Open Repository')
                ->show('Something went wrong opening the repository: '.$e->getMessage());

            return null;
        }
    }
}
