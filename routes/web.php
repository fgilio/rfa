<?php

use App\Actions\CheckForChangesAction;
use App\Actions\GetProjectStatusAction;
use App\Actions\ServeImageAction;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::dashboard-page')->name('dashboard');
Route::livewire('/p/{slug}/c/{hash}', 'pages::review-page')->where('hash', '[0-9a-fA-F]{4,40}')->name('review-page.commit');
Route::livewire('/p/{slug}/{ref?}/{baseRef?}', 'pages::review-page')->name('review-page');

Route::get('/api/status/{project}', function (int $project) {
    $p = Project::findOrFail($project);
    $globalGitignorePath = $p->respect_global_gitignore ? $p->global_gitignore_path : null;

    return response()->json(
        app(GetProjectStatusAction::class)->handle($p->path, $globalGitignorePath)
    );
});

Route::get('/api/changes/{project}', function (int $project) {
    $p = Project::findOrFail($project);
    $globalGitignorePath = $p->respect_global_gitignore ? $p->global_gitignore_path : null;

    $fingerprint = app(CheckForChangesAction::class)->handle($p->path, $globalGitignorePath);

    return response()->json(['fingerprint' => $fingerprint]);
});

Route::get('/api/image/{project}/{ref}/{path}', function (int $project, string $ref, string $path) {
    $result = app(ServeImageAction::class)->handle($project, $path, $ref);

    if ($result === null) {
        abort(404);
    }

    return response($result['content'], 200, [
        'Content-Type' => $result['mimeType'],
        'Cache-Control' => 'no-store',
    ]);
})->where('path', '.*');
