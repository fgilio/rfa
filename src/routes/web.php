<?php

use App\Actions\CheckForChangesAction;
use App\Actions\ServeImageAction;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::dashboard-page');
Route::livewire('/p/{slug}', 'pages::review-page');

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
