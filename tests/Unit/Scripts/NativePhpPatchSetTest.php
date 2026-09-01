<?php

use Symfony\Component\Process\Process;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/scripts/patch-nativephp.php';
require_once dirname(__DIR__, 2).'/Helpers/native-php-dist-fixtures.php';

uses(TestCase::class, InteractsWithTestRepositories::class);

/**
 * Build a throwaway `dist` tree holding the stock NativePHP shapes the patch
 * set is written against, so each test starts from an unpatched vendor tree.
 */
function stubDistRoot(string $root, ?callable $mutate = null): string
{
    $root .= '/electron-plugin/dist';

    foreach (['preload', 'server', 'server/api'] as $subdirectory) {
        mkdir($root.'/'.$subdirectory, 0755, true);
    }

    file_put_contents($root.'/preload/index.mjs', stockPreload());
    file_put_contents($root.'/server/api/window.js', stockWindowApi());
    file_put_contents($root.'/server/php.js', stockServer());
    file_put_contents($root.'/index.js', stockIndexForSplash()."\n".stockIndex());
    file_put_contents($root.'/../../php.js', stockPhpInstaller());
    file_put_contents($root.'/../../electron-builder.mjs', stockElectronBuilder());

    if ($mutate !== null) {
        $mutate($root);
    }

    return $root;
}

/** @return array<string, string> */
function distSnapshot(string $root): array
{
    return collect(['preload/index.mjs', 'server/api/window.js', 'server/php.js', 'index.js', '../../php.js', '../../electron-builder.mjs'])
        ->filter(fn (string $file) => is_file($root.'/'.$file))
        ->mapWithKeys(fn (string $file) => [$file => file_get_contents($root.'/'.$file)])
        ->all();
}

// -- The set --

test('the patch set covers every vendored file rfa depends on', function () {
    expect(collect(rfaNativePhpPatchSet())->pluck('name')->all())
        ->toBe(['preload-file-bridge', 'preload-renderer-ready', 'window-theme', 'renderer-ready-window', 'server-optimize', 'preflight-cache', 'splash-window', 'resolved-appearance', 'php-extraction', 'php-build-wait']);
});

test('the dist root points at the vendored electron plugin', function () {
    expect(rfaNativePhpDistRoot())
        ->toEndWith('/vendor/nativephp/desktop/resources/electron/electron-plugin/dist');
});

// -- Applying the whole set --

test('applies every patch in one run', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));

    $outcome = applyRfaNativePhpPatchSet($root);

    expect($outcome['applied'])->toBe(['preload-file-bridge', 'preload-renderer-ready', 'window-theme', 'renderer-ready-window', 'server-optimize', 'preflight-cache', 'splash-window', 'resolved-appearance', 'php-extraction', 'php-build-wait'])
        ->and($outcome['blocked'])->toBeEmpty()
        ->and($outcome['absent'])->toBeEmpty()
        ->and($outcome['error'])->toBeNull();

    expect(file_get_contents($root.'/preload/index.mjs'))
        ->toContain('nativeGetFilePath')
        ->toContain('nativeRendererReady');
    expect(file_get_contents($root.'/server/api/window.js'))
        ->toContain('[rfa window readiness]')
        ->toContain('[rfa window theme]')
        ->toContain('rfaRendererReady')
        ->toContain('rfaPresentationReady')
        ->toContain("opacity: id === 'main' ? 0 : 1")
        ->toContain('window.setOpacity(1);')
        ->toContain("window.emit('rfa:presented')")
        ->toContain('window.webContents.beginFrameSubscription(false')
        ->toContain('window.webContents.invalidate();')
        ->toContain('window.webContents.endFrameSubscription();');
    expect(file_get_contents($root.'/server/php.js'))->toContain('rfaNeedsFullOptimize');
    expect(file_get_contents($root.'/index.js'))
        ->toContain("'preflight_config_'")
        ->toContain('const RFA_SPLASH_HTML')
        ->toContain("window.once('rfa:presented'")
        ->toContain('rfaResolveAppearance()');
    expect(file_get_contents($root.'/../../php.js'))
        ->toContain('[rfa php archive validation]')
        ->toContain('removeSync(binaryDestDir);')
        ->toContain('ensureDirSync(binaryDestDir);')
        ->toContain('fs.chmodSync(binaryPath, 0o755);')
        ->toContain('[rfa php extraction]')
        ->toContain('inflateRawSync(compressed)')
        ->toContain('binary.length !== uncompressedSize')
        ->toContain('export const phpInstallerReady = true;');
    expect(file_get_contents($root.'/../../electron-builder.mjs'))
        ->toContain('[rfa php build wait]')
        ->toContain("execFileSync(process.execPath, ['php.js'")
        ->not->toContain('[rfa php build permission]')
        ->not->toContain('[rfa php build path]')
        ->not->toContain('chmodSync(');
});

test('a remembered maximize stays transparent until the settled frame is presented', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));

    applyRfaNativePhpPatchSet($root);

    expect(file_get_contents($root.'/server/api/window.js'))
        ->toContain("opacity: id === 'main' ? 0 : 1")
        ->toContain("let rfaPaintReady = id === 'main'")
        ->toContain('window.setOpacity(1);')
        ->toContain("window.emit('rfa:presented')")
        ->and(file_get_contents($root.'/index.js'))
        ->toContain("window.once('rfa:presented', () => this.rfaCloseSplash())")
        ->not->toContain("window.once('show', () => this.rfaCloseSplash())");
});

test('all edits to the shared index.js survive each other', function () {
    // These three patches rewrite the same file. Applying them in one pass keeps
    // each edit based on the output of the previous edit.
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));

    applyRfaNativePhpPatchSet($root);

    expect(file_get_contents($root.'/index.js'))
        ->toContain('import Store from "electron-store"; // [rfa preflight cache]')
        ->toContain('powerMonitor, BrowserWindow, nativeTheme')
        ->toContain('rfaResolveAppearance()');
});

test('a second run changes nothing', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));

    applyRfaNativePhpPatchSet($root);
    $afterFirst = distSnapshot($root);

    $outcome = applyRfaNativePhpPatchSet($root);

    expect($outcome['applied'])->toBeEmpty()
        ->and($outcome['unchanged'])->toHaveCount(10)
        ->and($outcome['written'])->toBeEmpty()
        ->and(distSnapshot($root))->toBe($afterFirst);
});

// -- Preflight: one missing block stops the whole set --

test('one reshaped source block leaves every file untouched', function () {
    // The splash anchors are gone, so its edit cannot land. The preload and
    // server edits still match — and must not be written anyway, or the build
    // would ship a vendor tree nobody has run.
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'), function (string $root) {
        file_put_contents($root.'/index.js', str_replace(
            'import { app, session, powerMonitor } from "electron";',
            'import { app, session, powerMonitor } from "electron/renamed";',
            file_get_contents($root.'/index.js'),
        ));
    });
    $before = distSnapshot($root);

    $outcome = applyRfaNativePhpPatchSet($root);

    expect($outcome['blocked'])->toBe(['splash-window', 'resolved-appearance'])
        ->and($outcome['written'])->toBeEmpty()
        ->and(distSnapshot($root))->toBe($before);
});

test('a reshaped block in one file blocks the patches to the other files', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'), function (string $root) {
        file_put_contents($root.'/server/php.js', 'const reshaped = true;');
    });
    $before = distSnapshot($root);

    $outcome = applyRfaNativePhpPatchSet($root);

    expect($outcome['blocked'])->toBe(['server-optimize'])
        ->and(distSnapshot($root))->toBe($before);
});

test('an unreadable target blocks the run rather than half-patching', function () {
    // Snapshot while the tree is still readable: the comparison below is about
    // what the run wrote, so it must not also record the unreadable target.
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));
    $before = distSnapshot($root);

    chmod($root.'/server/php.js', 0000);

    $outcome = applyRfaNativePhpPatchSet($root);

    chmod($root.'/server/php.js', 0644);

    expect($outcome['blocked'])->toBe(['server-optimize'])
        ->and($outcome['written'])->toBeEmpty()
        ->and(distSnapshot($root))->toBe($before);
})->skip(fn () => posix_geteuid() === 0, 'root can read a 0000 file');

// -- Rollback --

test('a failed write restores the files already replaced', function () {
    // index.js is the last file the set writes and is made unwritable, so the
    // preload and server files have already been renamed into place when it
    // fails. Both must come back.
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'), fn (string $root) => chmod($root, 0555));
    $before = distSnapshot($root);

    $outcome = applyRfaNativePhpPatchSet($root);

    chmod($root, 0755);

    expect($outcome['blocked'])->toBeEmpty()
        ->and($outcome['error'])->not->toBeNull()
        ->and($outcome['rolledBack'])->toBeTrue()
        ->and(distSnapshot($root))->toBe($before);
})->skip(fn () => posix_geteuid() === 0, 'root can write into a read-only directory');

test('a run leaves no temporary files behind', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));

    applyRfaNativePhpPatchSet($root);

    expect(glob($root.'/*.rfa-patch-*'))->toBeEmpty()
        ->and(glob($root.'/*/*.rfa-patch-*'))->toBeEmpty();
});

test('a rewritten file keeps its original permissions', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'), fn (string $root) => chmod($root.'/server/php.js', 0640));

    applyRfaNativePhpPatchSet($root);

    expect(fileperms($root.'/server/php.js') & 0777)->toBe(0640);
});

// -- Absent vendor tree --

test('an absent dist tree is reported, not failed', function () {
    // The release build re-runs the hook via `composer install --no-dev` on a
    // pruned copy where the plugin dist is not present.
    $outcome = applyRfaNativePhpPatchSet(sys_get_temp_dir().'/rfa_test_dist_nowhere_'.getmypid());

    expect($outcome['absent'])->toHaveCount(10)
        ->and($outcome['blocked'])->toBeEmpty()
        ->and($outcome['error'])->toBeNull();
});

// -- Composer hook contract --

test('the composer hook fails when a patch cannot apply', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'), fn (string $root) => file_put_contents($root.'/server/php.js', 'const reshaped = true;'));

    $process = new Process(
        ['php', '-r', sprintf(
            'require %s; $o = applyRfaNativePhpPatchSet(%s); exit($o["blocked"] === [] && $o["error"] === null ? 0 : 1);',
            var_export(dirname(__DIR__, 3).'/scripts/patch-nativephp.php', true),
            var_export($root, true),
        )],
    );
    $process->run();

    expect($process->getExitCode())->toBe(1);
});

test('the composer hook succeeds on a clean vendor tree', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));

    $process = new Process(
        ['php', '-r', sprintf(
            'require %s; $o = applyRfaNativePhpPatchSet(%s); exit($o["blocked"] === [] && $o["error"] === null ? 0 : 1);',
            var_export(dirname(__DIR__, 3).'/scripts/patch-nativephp.php', true),
            var_export($root, true),
        )],
    );
    $process->run();

    expect($process->getExitCode())->toBe(0);
});

// -- Write and restore helpers --

test('an atomic write leaves no temporary file when it fails', function () {
    $missingDirectory = sys_get_temp_dir().'/rfa_test_dist_'.getmypid().'_absent/nested/php.js';

    expect(rfaWriteFileAtomically($missingDirectory, 'contents'))->toBeFalse()
        ->and(glob(dirname($missingDirectory).'/*'))->toBeEmpty();
});

test('restoring puts every file back and reports success', function () {
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'));
    $originals = distSnapshot($root);

    foreach (array_keys($originals) as $file) {
        file_put_contents($root.'/'.$file, 'clobbered');
    }

    $restored = rfaRestoreFiles(collect($originals)
        ->mapWithKeys(fn (string $contents, string $file) => [$root.'/'.$file => $contents])
        ->all());

    expect($restored)->toBeTrue()
        ->and(distSnapshot($root))->toBe($originals);
});

test('restoring reports failure when a file cannot be put back', function () {
    $restored = rfaRestoreFiles([
        sys_get_temp_dir().'/rfa_test_dist_'.getmypid().'_absent/nested/php.js' => 'contents',
    ]);

    expect($restored)->toBeFalse();
});

test('a tree holding only some of the targets is refused, not half-patched', function () {
    // The dist directory is dropped whole by a pruned release install, so a
    // tree missing one target is a broken vendor copy rather than a pruned one.
    $root = stubDistRoot($this->createTempDirectory('rfa_test_dist_'), fn (string $root) => unlink($root.'/server/php.js'));
    $before = distSnapshot($root);

    $outcome = applyRfaNativePhpPatchSet($root);

    expect($outcome['absent'])->toBe(['server-optimize'])
        ->and($outcome['blocked'])->toBe(['server-optimize'])
        ->and($outcome['written'])->toBeEmpty()
        ->and(distSnapshot($root))->toBe($before);
});
