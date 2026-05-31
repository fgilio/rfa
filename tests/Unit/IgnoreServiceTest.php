<?php

use App\Services\IgnoreService;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->service = new IgnoreService;
    $this->tmpDir = $this->createTempDirectory('rfa_ignore_test_');
});

test('always excludes lock files without rfaignore', function () {
    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toContain(':(glob,exclude)**/package-lock.json');
    expect($pathspecs)->toContain(':(glob,exclude)**/pnpm-lock.yaml');
    expect($pathspecs)->toContain(':(glob,exclude)**/yarn.lock');
    expect($pathspecs)->toContain(':(glob,exclude)**/bun.lock');
    expect($pathspecs)->toContain(':(glob,exclude)**/composer.lock');
    expect($pathspecs)->toHaveCount(5);
});

test('returns only defaults when no rfaignore exists', function () {
    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toHaveCount(5);
    foreach ($pathspecs as $ps) {
        expect($ps)->toStartWith(':(glob,exclude)**/');
    }
});

test('reads custom patterns from rfaignore', function () {
    $patterns = [];
    $count = $this->faker->numberBetween(2, 5);
    for ($i = 0; $i < $count; $i++) {
        $patterns[] = $this->faker->word().'.'.$this->faker->fileExtension();
    }

    File::put($this->tmpDir.'/.rfaignore', implode("\n", $patterns));

    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toHaveCount(5 + $count);
    foreach ($patterns as $pattern) {
        expect($pathspecs)->toContain(":(glob,exclude)**/{$pattern}");
    }
});

test('ignores comments and blank lines in rfaignore', function () {
    $validPattern = $this->faker->word().'.log';
    $content = "# This is a comment\n\n{$validPattern}\n   \n# Another comment\n";

    File::put($this->tmpDir.'/.rfaignore', $content);

    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toHaveCount(6); // 5 defaults + 1 valid
    expect($pathspecs)->toContain(":(glob,exclude)**/{$validPattern}");
});

test('handles glob patterns in rfaignore', function () {
    $ext = $this->faker->fileExtension();
    $globPattern = "*.{$ext}";

    File::put($this->tmpDir.'/.rfaignore', $globPattern);

    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toContain(":(exclude){$globPattern}");
});

test('getExcludePathspecs skips negation lines', function () {
    File::put($this->tmpDir.'/.rfaignore', "*.log\n!keep.log\n");

    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toContain(':(exclude)*.log');
    // The negation must NOT become a literal exclude for a file named "!keep.log".
    expect($pathspecs)->not->toContain(':(glob,exclude)**/!keep.log');
    expect($pathspecs)->not->toContain(':(exclude)!keep.log');
});

// -- isPathExcluded tests (operate on compiled rules) --

test('isPathExcluded matches exact filename', function () {
    $name = $this->faker->word().'.'.$this->faker->fileExtension();

    expect($this->service->isPathExcluded($name, $this->service->compile([$name])))->toBeTrue();
});

test('isPathExcluded matches glob wildcard', function () {
    $ext = $this->faker->fileExtension();
    $file = $this->faker->word().'.'.$ext;

    expect($this->service->isPathExcluded($file, $this->service->compile(["*.{$ext}"])))->toBeTrue();
});

test('isPathExcluded matches basename in nested path', function () {
    $name = $this->faker->word().'.'.$this->faker->fileExtension();
    $nested = 'src/deep/nested/'.$name;

    expect($this->service->isPathExcluded($nested, $this->service->compile([$name])))->toBeTrue();
});

test('isPathExcluded returns false when no pattern matches', function () {
    $file = $this->faker->word().'.zzy';

    expect($this->service->isPathExcluded($file, $this->service->compile([
        'unrelated.txt',
        '*.zzz',
    ])))->toBeFalse();
});

test('isPathExcluded matches files inside a directory-only pattern', function () {
    $rules = $this->service->compile(['build/']);

    expect($this->service->isPathExcluded('build/app.js', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('build/sub/chunk.js', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('build', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('src/main.js', $rules))->toBeFalse();
});

test('isPathExcluded matches a nested directory pattern at any depth', function () {
    $rules = $this->service->compile(['node_modules/']);

    expect($this->service->isPathExcluded('node_modules/pkg/index.js', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('packages/a/node_modules/pkg/index.js', $rules))->toBeTrue();
});

test('isPathExcluded honors negation to re-include a file (last match wins)', function () {
    $rules = $this->service->compile(['*.log', '!keep.log']);

    expect($this->service->isPathExcluded('debug.log', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('keep.log', $rules))->toBeFalse();
    expect($this->service->isPathExcluded('logs/keep.log', $rules))->toBeFalse();
});

test('isPathExcluded anchors a leading-slash pattern to the repo root', function () {
    $rules = $this->service->compile(['/dist/']);

    expect($this->service->isPathExcluded('dist/a.js', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('packages/x/dist/a.js', $rules))->toBeFalse();
});
