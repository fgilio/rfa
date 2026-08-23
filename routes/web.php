<?php

use App\Actions\CheckForChangesAction;
use App\Actions\GetProjectStatusAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use App\Actions\ResolveStartupRouteAction;
use App\Actions\ServeImageAction;
use App\Http\Requests\BrowserDiagnosticSampleRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): RedirectResponse => redirect(app(ResolveStartupRouteAction::class)->handle()))->name('home');

Route::livewire('/select-repo', 'pages::select-repo-page')->name('select-repo');
Route::livewire('/p/{slug}/context', 'pages::context-page')->name('context-page');
Route::livewire('/p/{slug}/c/{hash}', 'pages::review-page')->where('hash', '[0-9a-fA-F]{4,40}')->name('review-page.commit');
Route::livewire('/p/{slug}/r/{from}..{to}', 'pages::review-page')
    ->where('from', '[0-9a-fA-F]{4,40}')
    ->where('to', '[0-9a-fA-F]{4,40}')
    ->name('review-page.range');
Route::livewire('/p/{slug}/rw/{rangeFromWorking}', 'pages::review-page')
    ->where('rangeFromWorking', '[0-9a-fA-F]{4,40}\^?')
    ->name('review-page.range-to-working');
Route::livewire('/p/{slug}/{ref?}/{baseRef?}', 'pages::review-page')->name('review-page');

Route::get('/api/status/{project}', function (Project $project) {
    $globalGitignorePath = $project->respect_global_gitignore ? $project->global_gitignore_path : null;

    return response()->json(
        app(GetProjectStatusAction::class)->handle($project->path, $globalGitignorePath)
    );
})->name('api.status');

Route::get('/api/changes/{project}', function (Project $project) {
    $globalGitignorePath = $project->respect_global_gitignore ? $project->global_gitignore_path : null;

    return response()->json(
        app(CheckForChangesAction::class)->handle($project->path, $globalGitignorePath)
    );
})->name('api.changes');

Route::post('/api/diagnostics/browser', function (BrowserDiagnosticSampleRequest $request, RecordRuntimeDiagnosticAction $diagnostics) {
    $diagnostics->recordBrowserSample($request->validated());

    return response()->noContent();
})->name('api.diagnostics.browser');

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
