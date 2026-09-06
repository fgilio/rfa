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
        ->and(Context::get('rfa.outcome'))->toBe('partial')
        ->and(Context::get('rfa.reason'))->toBe('manifest_scripts_unusable')
        ->and(Context::get('rfa.compiled_count'))->toBe(1)
        ->and(Context::get('rfa.missing_count'))->toBe(1);
});

test('the warm action reports a completed outcome when every manifest script compiled', function () {
    (new OpcacheWarmService(new FakeOpcacheService(included: [base_path('app/Services/OpcacheWarmService.php')])))->record();
    app()->instance(OpcacheService::class, new FakeOpcacheService);

    Log::shouldReceive('info')->once()->with('opcache.warmed');

    app(WarmOpcacheAction::class)->handle();

    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.reason'))->toBeNull();
});

test('the warm action leaves a diagnostics breadcrumb for the launch report', function () {
    $diagnosticsPath = $this->manifestDir.'/diagnostics.jsonl';
    config()->set('rfa.diagnostics.enabled', true);
    config()->set('rfa.diagnostics.path', $diagnosticsPath);
    (new OpcacheWarmService(new FakeOpcacheService(included: [base_path('app/Services/OpcacheWarmService.php')])))->record();
    app()->instance(OpcacheService::class, new FakeOpcacheService);

    Log::shouldReceive('info')->once()->with('opcache.warmed');

    app(WarmOpcacheAction::class)->handle();

    $entry = json_decode(trim((string) file_get_contents($diagnosticsPath)), true);

    expect($entry['event'])->toBe('opcache.warmed')
        ->and($entry['context']['compiled'])->toBe(1)
        ->and($entry['context']['outcome'])->toBe('completed')
        ->and($entry['context']['duration_ms'])->toBeInt()
        ->and($entry['request']['started_at_ms'])->toBeInt();
});

test('the warm action reports a partial outcome when a manifest script fails to compile', function () {
    $script = base_path('app/Services/OpcacheWarmService.php');
    (new OpcacheWarmService(new FakeOpcacheService(included: [$script])))->record();
    app()->instance(OpcacheService::class, new FakeOpcacheService(failing: [$script]));

    Log::shouldReceive('info')->once()->with('opcache.warmed');

    app(WarmOpcacheAction::class)->handle();

    expect(Context::get('rfa.outcome'))->toBe('partial')
        ->and(Context::get('rfa.reason'))->toBe('manifest_scripts_unusable')
        ->and(Context::get('rfa.failed_count'))->toBe(1);
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

test('the middleware records the manifest after a page response is sent', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService(included: [base_path('app/a.php')]));

    Log::shouldReceive('info')->once()->with('opcache.manifest.recorded');

    $middleware = app(RecordOpcacheWarmManifest::class);
    $request = Request::create('/p/demo', 'GET');
    $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

    $middleware->terminate($request, $response);

    expect(app(OpcacheWarmService::class)->manifestScripts())->toBe([base_path('app/a.php')]);
});

test('the middleware ignores failed responses', function () {
    app()->instance(OpcacheService::class, new FakeOpcacheService(included: [base_path('app/a.php')]));

    Log::shouldReceive('info')->never();

    $middleware = app(RecordOpcacheWarmManifest::class);
    $middleware->terminate(Request::create('/p/demo', 'GET'), new Response('', 500));

    expect(File::exists(app(OpcacheWarmService::class)->manifestPath()))->toBeFalse();
});

test('the manifest recorder logs a failed write instead of throwing', function () {
    // A regular file where the manifest directory should be makes the write fail.
    File::ensureDirectoryExists($this->manifestDir);
    File::put($this->manifestDir.'/blocker', '');
    config()->set('rfa.opcache_warm_manifest_path', $this->manifestDir.'/blocker/manifest.json');
    app()->instance(OpcacheService::class, new FakeOpcacheService(included: [base_path('app/a.php')]));

    Log::shouldReceive('error')->once()->withArgs(fn (string $event, array $payload): bool => $event === 'opcache.manifest.record.failed' && $payload['reason'] === 'opcache_manifest_record_failed');
    Log::shouldReceive('info')->once()->with('opcache.manifest.recorded');

    app(RecordOpcacheWarmManifestAction::class)->handle();

    expect(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.reason'))->toBe('opcache_manifest_record_failed');
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
