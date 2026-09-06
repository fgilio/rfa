<?php

declare(strict_types=1);

use App\Actions\CompileViewsAction;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = (string) realpath(sys_get_temp_dir()).'/rfa_test_compile_views_'.getmypid().'_'.uniqid('', true);
    $this->viewsDir = $this->root.'/views';
    $this->packageDir = $this->root.'/package-views';
    $this->compiledDir = $this->root.'/compiled';

    File::ensureDirectoryExists($this->viewsDir.'/nested');
    File::ensureDirectoryExists($this->viewsDir.'/vendor/skipped');
    File::ensureDirectoryExists($this->packageDir);
    File::ensureDirectoryExists($this->compiledDir);

    File::put($this->viewsDir.'/home.blade.php', '<p>{{ $greeting }}</p>');
    File::put($this->viewsDir.'/nested/panel.blade.php', '@if($open)<div>open</div>@endif');
    File::put($this->viewsDir.'/plain.php', '<?php echo "not blade";');
    File::put($this->viewsDir.'/vendor/skipped/published.blade.php', '<p>published</p>');
    File::put($this->packageDir.'/widget.blade.php', '<span>{{ $label }}</span>');

    $files = new Filesystem;
    $finder = new FileViewFinder($files, [$this->viewsDir]);
    $finder->addNamespace('package', $this->packageDir);
    $finder->addNamespace('nested-again', $this->viewsDir.'/nested');

    $this->compiler = new BladeCompiler($files, $this->compiledDir);
    $this->action = new CompileViewsAction(new Factory(new EngineResolver, $finder, new Dispatcher), $this->compiler);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('compiles every blade template under the view roots and namespace hints', function () {
    $result = $this->action->handle();

    expect($result)->toBe(['paths' => 2, 'compiled' => 3])
        ->and(File::exists($this->compiler->getCompiledPath($this->viewsDir.'/home.blade.php')))->toBeTrue()
        ->and(File::exists($this->compiler->getCompiledPath($this->viewsDir.'/nested/panel.blade.php')))->toBeTrue()
        ->and(File::exists($this->compiler->getCompiledPath($this->packageDir.'/widget.blade.php')))->toBeTrue()
        ->and(File::get($this->compiler->getCompiledPath($this->viewsDir.'/home.blade.php')))->toContain('<?php echo e($greeting); ?>');
});

test('leaves files already in the compiled directory in place', function () {
    File::put($this->compiledDir.'/live-request.php', '<?php return "in use";');

    $this->action->handle();

    expect(File::get($this->compiledDir.'/live-request.php'))->toBe('<?php return "in use";');
});

test('skips published vendor templates and non-blade files', function () {
    $this->action->handle();

    expect(File::exists($this->compiler->getCompiledPath($this->viewsDir.'/vendor/skipped/published.blade.php')))->toBeFalse()
        ->and(File::exists($this->compiler->getCompiledPath($this->viewsDir.'/plain.php')))->toBeFalse();
});

test('a view root that does not exist compiles nothing instead of failing', function () {
    $files = new Filesystem;
    $action = new CompileViewsAction(
        new Factory(new EngineResolver, new FileViewFinder($files, [$this->root.'/missing']), new Dispatcher),
        new BladeCompiler($files, $this->compiledDir),
    );

    expect($action->handle())->toBe(['paths' => 1, 'compiled' => 0]);
});

test('a sibling root sharing a name prefix is not mistaken for a nested root', function () {
    $sibling = $this->root.'/views-extra';
    File::ensureDirectoryExists($sibling);
    File::put($sibling.'/extra.blade.php', '<b>extra</b>');

    $files = new Filesystem;
    $action = new CompileViewsAction(
        new Factory(new EngineResolver, new FileViewFinder($files, [$this->viewsDir, $sibling]), new Dispatcher),
        $this->compiler,
    );

    expect($action->handle())->toBe(['paths' => 2, 'compiled' => 3])
        ->and(File::exists($this->compiler->getCompiledPath($sibling.'/extra.blade.php')))->toBeTrue();
});
