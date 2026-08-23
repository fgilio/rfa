<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\NotAGitRepositoryException;
use App\Models\Project;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
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
            // The Context fields let the calling owner's canonical event tell a
            // rejected pick apart from a dismissed dialog (both return null).
            Context::add('rfa.reason', 'not_a_git_repository');

            Alert::new()
                ->type('warning')
                ->title('Not a Git Repository')
                ->show('The selected folder is not a git repository.');

            return null;
        } catch (Throwable $e) {
            // A real failure (e.g. a database error) — don't mislabel it as
            // "not a git repository".
            Context::add('rfa.reason', 'project_registration_failed');
            Context::add('rfa.error_class', $e::class);

            // The picked path stays out of the payload: a dialog result is an
            // absolute path with no project to relativize it against.
            Log::warning('project.registration.failed', [
                'reason' => 'project_registration_failed',
                'error_class' => $e::class,
            ]);

            Alert::new()
                ->type('warning')
                ->title('Could Not Open Repository')
                ->show('Something went wrong opening the repository: '.$e->getMessage());

            return null;
        }
    }
}
