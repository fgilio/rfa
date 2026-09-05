<?php

declare(strict_types=1);

use App\Actions\RecordOpcacheWarmManifestAction;
use App\Actions\WarmOpcacheAction;
use App\Http\Middleware\RecordOpcacheWarmManifest;
use App\Services\OpcacheService;
use App\Services\OpcacheWarmService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Helpers\FakeOpcacheService;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->manifestDir = sys_get_temp_dir().'/rfa_test_opcache_'.getmypid().'_'.uniqid('', true);
    config()->set('nativephp.version', '9.9.9');
    config()->set('rfa.opcache_warm_manifest_path', $this->manifestDir.'/manifest.json');
});

afterEach(function () {
    File::deleteDirectory($this->manifestDir);
});

test('the warm route compiles the manifest and reports the counts', function () {
    $recorded = new FakeOpcacheService(included: [
        base_path('app/Services/OpcacheWarmService.php'),
        base_path('app/Services/OpcacheService.php'),
        base_path('app/Services/Missing.php'),
    ]);
    (new OpcacheWarmService($recorded))->record();

    $opcache = new FakeOpcacheService(cached: [base_path('app/Services/OpcacheService.php')]);
    app()->instance(OpcacheService::class, $opcache);

    Log::shouldReceive('info')->once()->with('opcache.warmed');

    $this->get('/_rfa/warm')
        ->assertOk()
        ->assertExactJson(['available' => true, 'compiled' => 1, 'cached' => 1, 'missing' => 1, 'failed' => 0]);

    expect($opcache->compiles)->toBe([base_path('app/Services/OpcacheWarmService.php')])
        ->and(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.compiled_count'))->toBe(1);
});

test('the warm action reports a skipped outcome when opcache is unavailable', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService(enabled: false));

    Log::shouldReceive('info')->once()->with('opcache.warmed');

    app(WarmOpcacheAction::class)->handle();

    expect(Context::get('rfa.outcome'))->toBe('skipped')
        ->and(Context::get('rfa.reason'))->toBe('opcache_unavailable');
});

test('the warm action reports a skipped outcome when the manifest is empty', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService);

    Log::shouldReceive('info')->once()->with('opcache.warmed');

    app(WarmOpcacheAction::class)->handle();

    expect(Context::get('rfa.outcome'))->toBe('skipped')
        ->and(Context::get('rfa.reason'))->toBe('empty_manifest');
});

test('the manifest recorder logs only when the manifest changed', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService(included: [base_path('app/a.php'), base_path('app/b.php')]));

    Log::shouldReceive('info')->once()->with('opcache.manifest.recorded');

    $recorder = app(RecordOpcacheWarmManifestAction::class);
    $recorder->handle();
    $recorder->handle();

    expect(Context::get('rfa.added_count'))->toBe(2)
        ->and(Context::get('rfa.script_count'))->toBe(2)
        ->and(Context::get('rfa.outcome'))->toBe('completed');
});

test('successful HTML page loads and Livewire updates are recorded, other requests are not', function (string $method, int $status, string $contentType, bool $livewire, bool $expected) {
    $request = Request::create('/p/demo', $method);

    if ($livewire) {
        $request->headers->set('X-Livewire', 'true');
    }

    $response = new Response('', $status, ['Content-Type' => $contentType]);

    expect(RecordOpcacheWarmManifest::isRecordable($request, $response))->toBe($expected);
})->with([
    'html page' => ['GET', 200, 'text/html; charset=UTF-8', false, true],
    'livewire update' => ['POST', 200, 'application/json', true, true],
    'livewire navigate' => ['GET', 200, 'text/html; charset=UTF-8', true, false],
    'failed livewire update' => ['POST', 500, 'application/json', true, false],
    'json api' => ['GET', 200, 'application/json', false, false],
    'diagnostics post' => ['POST', 204, '', false, false],
    'redirect' => ['GET', 302, 'text/html; charset=UTF-8', false, false],
    'missing page' => ['GET', 404, 'text/html; charset=UTF-8', false, false],
]);

test('the middleware records the manifest after a page response is sent', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService(included: [base_path('app/a.php')]));

    Log::shouldReceive('info')->once()->with('opcache.manifest.recorded');

    $middleware = app(RecordOpcacheWarmManifest::class);
    $request = Request::create('/p/demo', 'GET');
    $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

    $middleware->terminate($request, $response);

    expect(app(OpcacheWarmService::class)->manifestScripts())->toBe([base_path('app/a.php')]);
});

test('the middleware ignores responses that are not recordable', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService(cached: [base_path('app/a.php')]));

    Log::shouldReceive('info')->never();

    $middleware = app(RecordOpcacheWarmManifest::class);
    $middleware->terminate(Request::create('/api/changes/1', 'GET'), new Response('{}', 200, ['Content-Type' => 'application/json']));

    expect(File::exists(app(OpcacheWarmService::class)->manifestPath()))->toBeFalse();
});

test('the middleware is part of the web group', function () {
    expect(app(Kernel::class)->getMiddlewareGroups()['web'])
        ->toContain(RecordOpcacheWarmManifest::class);
});

test('a page load records its scripts through the web middleware group', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService(included: [base_path('routes/web.php')]));

    Log::shouldReceive('info')->once()->with('opcache.manifest.recorded');
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $this->get('/select-repo')->assertOk();

    expect(app(OpcacheWarmService::class)->manifestScripts())->toBe([base_path('routes/web.php')]);
});
