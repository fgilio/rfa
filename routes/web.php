<?php

use App\Actions\CheckForChangesAction;
use App\Actions\GetProjectStatusAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use App\Actions\ResolveStartupRouteAction;
use App\Actions\ServeImageAction;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

Route::post('/api/diagnostics/browser', function (Request $request, RecordRuntimeDiagnosticAction $diagnostics) {
    abort_if(
        strlen($request->getContent()) > (int) config('rfa.diagnostics.max_browser_payload_bytes', 64 * 1024),
        413,
    );

    $diagnostics->recordBrowserSample($request->validate([
        'reason' => ['nullable', 'string', 'max:64'],
        'url' => ['nullable', 'string', 'max:2048'],
        'hidden' => ['nullable', 'boolean'],
        'focused' => ['nullable', 'boolean'],
        'includeProcessSnapshot' => ['nullable', 'boolean'],
        'viewport' => ['nullable', 'array:width,height,devicePixelRatio'],
        'viewport.width' => ['nullable', 'integer', 'min:0', 'max:10000'],
        'viewport.height' => ['nullable', 'integer', 'min:0', 'max:10000'],
        'viewport.devicePixelRatio' => ['nullable', 'numeric', 'min:0', 'max:16'],
        'heap' => ['nullable', 'array:usedJSHeapSize,totalJSHeapSize,jsHeapSizeLimit,usedJSHeapSizeMb,totalJSHeapSizeMb'],
        'heap.usedJSHeapSize' => ['nullable', 'numeric', 'min:0'],
        'heap.totalJSHeapSize' => ['nullable', 'numeric', 'min:0'],
        'heap.jsHeapSizeLimit' => ['nullable', 'numeric', 'min:0'],
        'heap.usedJSHeapSizeMb' => ['nullable', 'numeric', 'min:0'],
        'heap.totalJSHeapSizeMb' => ['nullable', 'numeric', 'min:0'],
        'dom' => ['nullable', 'array:nodes,livewireComponents,diffFiles,expandedDiffFiles,diffLines,comments'],
        'dom.nodes' => ['nullable', 'integer', 'min:0'],
        'dom.livewireComponents' => ['nullable', 'integer', 'min:0'],
        'dom.diffFiles' => ['nullable', 'integer', 'min:0'],
        'dom.expandedDiffFiles' => ['nullable', 'integer', 'min:0'],
        'dom.diffLines' => ['nullable', 'integer', 'min:0'],
        'dom.comments' => ['nullable', 'integer', 'min:0'],
        'navigation' => ['nullable', 'array:type,domCompleteMs,resources'],
        'navigation.type' => ['nullable', 'string', 'max:64'],
        'navigation.domCompleteMs' => ['nullable', 'integer', 'min:0'],
        'navigation.resources' => ['nullable', 'integer', 'min:0'],
        'timings' => ['nullable', 'array:longTasks,longTasksDuringAction,longTasksDuringCommit,diffAction,livewireCommit'],
        'timings.longTasks' => ['nullable', 'array:count,totalMs,maxMs'],
        'timings.longTasks.count' => ['nullable', 'integer', 'min:0'],
        'timings.longTasks.totalMs' => ['nullable', 'integer', 'min:0'],
        'timings.longTasks.maxMs' => ['nullable', 'integer', 'min:0'],
        'timings.longTasksDuringAction' => ['nullable', 'array:count,totalMs,maxMs'],
        'timings.longTasksDuringAction.count' => ['nullable', 'integer', 'min:0'],
        'timings.longTasksDuringAction.totalMs' => ['nullable', 'integer', 'min:0'],
        'timings.longTasksDuringAction.maxMs' => ['nullable', 'integer', 'min:0'],
        'timings.longTasksDuringCommit' => ['nullable', 'array:count,totalMs,maxMs'],
        'timings.longTasksDuringCommit.count' => ['nullable', 'integer', 'min:0'],
        'timings.longTasksDuringCommit.totalMs' => ['nullable', 'integer', 'min:0'],
        'timings.longTasksDuringCommit.maxMs' => ['nullable', 'integer', 'min:0'],
        'timings.diffAction' => ['nullable', 'array:fileId,action,elapsedMs,phpMs,hunkCount,diffLines,lineContentBytes,tooLarge,binary,cached'],
        'timings.diffAction.fileId' => ['nullable', 'string', 'max:128'],
        'timings.diffAction.action' => ['nullable', 'string', 'max:64'],
        'timings.diffAction.elapsedMs' => ['nullable', 'integer', 'min:0'],
        'timings.diffAction.phpMs' => ['nullable', 'integer', 'min:0'],
        'timings.diffAction.hunkCount' => ['nullable', 'integer', 'min:0'],
        'timings.diffAction.diffLines' => ['nullable', 'integer', 'min:0'],
        'timings.diffAction.lineContentBytes' => ['nullable', 'integer', 'min:0'],
        'timings.diffAction.tooLarge' => ['nullable', 'boolean'],
        'timings.diffAction.binary' => ['nullable', 'boolean'],
        'timings.diffAction.cached' => ['nullable', 'boolean'],
        'timings.livewireCommit' => ['nullable', 'array:status,elapsedMs'],
        'timings.livewireCommit.status' => ['nullable', 'string', 'max:32'],
        'timings.livewireCommit.elapsedMs' => ['nullable', 'integer', 'min:0'],
    ]));

    return response()->noContent();
})->middleware('throttle:diagnostics')->name('api.diagnostics.browser');

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
