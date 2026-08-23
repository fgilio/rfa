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

test('always excludes lock files, with or without an rfaignore', function () {
    $rules = $this->service->rules($this->tmpDir);

    foreach (IgnoreService::ALWAYS_EXCLUDE as $lockfile) {
        expect($this->service->isPathExcluded($lockfile, $rules))->toBeTrue();
        expect($this->service->isPathExcluded("packages/app/{$lockfile}", $rules))->toBeTrue();
    }
});

test('a negation cannot re-include a lock file', function () {
    File::put($this->tmpDir.'/.rfaignore', "!composer.lock\n");

    $rules = $this->service->rules($this->tmpDir);

    expect($this->service->isPathExcluded('composer.lock', $rules))->toBeTrue();
});

test('reads custom patterns from rfaignore', function () {
    $patterns = [];
    $count = $this->faker->numberBetween(2, 5);
    for ($i = 0; $i < $count; $i++) {
        $patterns[] = $this->faker->word().'.'.$this->faker->fileExtension();
    }

    File::put($this->tmpDir.'/.rfaignore', implode("\n", $patterns));

    $rules = $this->service->rules($this->tmpDir);

    expect($rules)->toHaveCount($count);
    foreach ($patterns as $pattern) {
        expect($this->service->isPathExcluded($pattern, $rules))->toBeTrue();
        expect($this->service->isPathExcluded("src/{$pattern}", $rules))->toBeTrue();
    }
});

test('ignores comments and blank lines in rfaignore', function () {
    $validPattern = $this->faker->word().'.log';

    File::put($this->tmpDir.'/.rfaignore', "# This is a comment\n\n{$validPattern}\n   \n# Another comment\n");

    $rules = $this->service->rules($this->tmpDir);

    expect($rules)->toHaveCount(1);
    expect($this->service->isPathExcluded($validPattern, $rules))->toBeTrue();
});

test('handles glob patterns in rfaignore', function () {
    $ext = $this->faker->fileExtension();

    File::put($this->tmpDir.'/.rfaignore', "*.{$ext}");

    $rules = $this->service->rules($this->tmpDir);

    expect($this->service->isPathExcluded("report.{$ext}", $rules))->toBeTrue();
});

test('a negation line re-includes rather than excluding the literal name', function () {
    File::put($this->tmpDir.'/.rfaignore', "build/\n!notes.txt\n");

    $rules = $this->service->rules($this->tmpDir);

    expect($this->service->isPathExcluded('build/out.js', $rules))->toBeTrue()
        ->and($this->service->isPathExcluded('notes.txt', $rules))->toBeFalse()
        ->and($this->service->isPathExcluded('!notes.txt', $rules))->toBeFalse();
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

test('isPathExcluded matches a mid-path ** across zero or more whole segments', function () {
    $rules = $this->service->compile(['a/**/b']);

    expect($this->service->isPathExcluded('a/b', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('a/x/b', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('a/x/y/b', $rules))->toBeTrue();
    // `**/` must not bridge into a partial segment name: `a/xb` is not `a/.../b`.
    expect($this->service->isPathExcluded('a/xb', $rules))->toBeFalse();
});

test('isPathExcluded leading **/ matches a name at any depth without over-matching a partial segment', function () {
    $rules = $this->service->compile(['**/build']);

    expect($this->service->isPathExcluded('build', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('build/out.js', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('pkg/a/build', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('pkg/a/build/out.js', $rules))->toBeTrue();
    // A bare `.*` body would wrongly swallow these — the segment boundary forbids it.
    expect($this->service->isPathExcluded('prebuild', $rules))->toBeFalse();
    expect($this->service->isPathExcluded('pkg/prebuild/out.js', $rules))->toBeFalse();
});

test('isPathExcluded trailing ** matches everything under a directory', function () {
    $rules = $this->service->compile(['logs/**']);

    expect($this->service->isPathExcluded('logs/a.txt', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('logs/sub/b.txt', $rules))->toBeTrue();
    expect($this->service->isPathExcluded('src/a.txt', $rules))->toBeFalse();
});
