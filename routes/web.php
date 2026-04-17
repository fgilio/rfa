<?php

use App\Actions\CheckForChangesAction;
use App\Actions\GetProjectStatusAction;
use App\Actions\ServeImageAction;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::no-projects-page')->name('no-projects');
Route::livewire('/p/{slug}/c/{hash}', 'pages::review-page')->where('hash', '[0-9a-fA-F]{4,40}')->name('review-page.commit');
Route::livewire('/p/{slug}/{ref?}/{baseRef?}', 'pages::review-page')->name('review-page');

Route::get('/api/status/{project}', function (Project $project) {
    $globalGitignorePath = $project->respect_global_gitignore ? $project->global_gitignore_path : null;

    return response()->json(
        app(GetProjectStatusAction::class)->handle($project->path, $globalGitignorePath)
    );
})->name('api.status');

Route::get('/api/changes/{project}', function (Project $project) {
    $globalGitignorePath = $project->respect_global_gitignore ? $project->global_gitignore_path : null;

    $fingerprint = app(CheckForChangesAction::class)->handle($project->path, $globalGitignorePath);

    return response()->json(['fingerprint' => $fingerprint]);
})->name('api.changes');

Route::get('/api/image/{project}/{ref}/{path}', function (Project $project, string $ref, string $path) {
    $result = app(ServeImageAction::class)->handle($project->id, $path, $ref);

    if ($result === null) {
        abort(404);
    }

    return response($result['content'], 200, [
        'Content-Type' => $result['mimeType'],
        'Cache-Control' => 'no-store',
    ]);
})->where('path', '.*')->name('api.image');
