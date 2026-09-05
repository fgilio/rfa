<?php

declare(strict_types=1);

use App\Services\OpcacheWarmService;
use Illuminate\Support\Facades\File;
use Tests\Helpers\FakeOpcacheService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->manifestDir = sys_get_temp_dir().'/rfa_test_opcache_'.getmypid().'_'.uniqid('', true);
    config()->set('nativephp.version', '9.9.9');
    config()->set('rfa.opcache_warm_manifest_path', $this->manifestDir.'/manifest.json');
});

afterEach(function () {
    File::deleteDirectory($this->manifestDir);
});

test('record writes the scripts of this install and ignores foreign paths', function () {
    $service = new OpcacheWarmService(new FakeOpcacheService(included: [
        base_path('app/Actions/WarmOpcacheAction.php'),
        storage_path('framework/views/abc.php'),
        '/usr/local/lib/php/pear.php',
    ]));

    expect($service->record())->toBe(['total' => 2, 'added' => 2, 'written' => true])
        ->and($service->manifestScripts())->toBe([
            base_path('app/Actions/WarmOpcacheAction.php'),
            storage_path('framework/views/abc.php'),
        ])
        ->and(json_decode((string) File::get($service->manifestPath()), true)['version'])->toBe('9.9.9');
});

test('record merges new scripts into an existing manifest and skips the write when nothing changed', function () {
    $first = new OpcacheWarmService(new FakeOpcacheService(included: [base_path('app/a.php')]));
    $first->record();

    $second = new OpcacheWarmService(new FakeOpcacheService(included: [base_path('app/a.php'), base_path('app/b.php')]));

    expect($second->record())->toBe(['total' => 2, 'added' => 1, 'written' => true])
        ->and($second->record())->toBe(['total' => 2, 'added' => 0, 'written' => false])
        ->and($second->manifestScripts())->toBe([base_path('app/a.php'), base_path('app/b.php')]);
});

test('record reports opcache as unavailable without touching the manifest', function () {
    $service = new OpcacheWarmService(new FakeOpcacheService(enabled: false, included: [base_path('app/a.php')]));

    expect($service->record())->toBe(['total' => 0, 'added' => 0, 'written' => false])
        ->and(File::exists($service->manifestPath()))->toBeFalse();
});

test('a manifest from another app version is discarded', function () {
    $service = new OpcacheWarmService(new FakeOpcacheService(included: [base_path('app/a.php')]));
    $service->record();

    config()->set('nativephp.version', '10.0.0');

    expect($service->manifestScripts())->toBe([])
        ->and($service->record()['written'])->toBeTrue()
        ->and($service->manifestScripts())->toBe([base_path('app/a.php')]);
});

test('manifest entries from another install sharing the user data directory are ignored', function () {
    $service = new OpcacheWarmService(new FakeOpcacheService);

    File::ensureDirectoryExists(dirname($service->manifestPath()));
    File::put($service->manifestPath(), json_encode([
        'version' => '9.9.9',
        'scripts' => ['/Applications/other.app/Contents/Resources/build/app/vendor/x/functions.php', base_path('app/a.php')],
    ]));

    expect($service->manifestScripts())->toBe([base_path('app/a.php')]);
});

test('a corrupt manifest reads as empty', function () {
    $service = new OpcacheWarmService(new FakeOpcacheService);

    File::ensureDirectoryExists(dirname($service->manifestPath()));
    File::put($service->manifestPath(), '{not json');

    expect($service->manifestScripts())->toBe([]);
});

test('warm compiles manifest scripts that exist and are not already cached', function () {
    $existing = base_path('app/Services/OpcacheWarmService.php');
    $alreadyCached = base_path('app/Services/OpcacheService.php');
    $missing = base_path('app/Services/DoesNotExist.php');
    $broken = base_path('app/Services/broken.php');

    File::put($broken, '<?php syntax error');

    try {
        (new OpcacheWarmService(new FakeOpcacheService(included: [$existing, $alreadyCached, $missing, $broken])))->record();

        $opcache = new FakeOpcacheService(cached: [$alreadyCached], failing: [$broken]);

        expect((new OpcacheWarmService($opcache))->warm())
            ->toBe(['available' => true, 'compiled' => 1, 'cached' => 1, 'missing' => 1, 'failed' => 1])
            ->and($opcache->compiles)->toBe([$existing, $broken]);
    } finally {
        File::delete($broken);
    }
});

test('warm does nothing without opcache', function () {
    $opcache = new FakeOpcacheService(enabled: false);

    expect((new OpcacheWarmService($opcache))->warm())
        ->toBe(['available' => false, 'compiled' => 0, 'cached' => 0, 'missing' => 0, 'failed' => 0])
        ->and($opcache->compiles)->toBe([]);
});
