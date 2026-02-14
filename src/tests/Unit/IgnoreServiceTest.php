<?php

use App\Services\IgnoreService;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->service = new IgnoreService;
    $this->tmpDir = sys_get_temp_dir().'/rfa_ignore_test_'.uniqid();
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    $ignoreFile = $this->tmpDir.'/.rfaignore';
    if (file_exists($ignoreFile)) {
        unlink($ignoreFile);
    }
    rmdir($this->tmpDir);
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

    file_put_contents($this->tmpDir.'/.rfaignore', implode("\n", $patterns));

    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toHaveCount(5 + $count);
    foreach ($patterns as $pattern) {
        expect($pathspecs)->toContain(":(glob,exclude)**/{$pattern}");
    }
});

test('ignores comments and blank lines in rfaignore', function () {
    $validPattern = $this->faker->word().'.log';
    $content = "# This is a comment\n\n{$validPattern}\n   \n# Another comment\n";

    file_put_contents($this->tmpDir.'/.rfaignore', $content);

    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toHaveCount(6); // 5 defaults + 1 valid
    expect($pathspecs)->toContain(":(glob,exclude)**/{$validPattern}");
});

test('handles glob patterns in rfaignore', function () {
    $ext = $this->faker->fileExtension();
    $globPattern = "*.{$ext}";

    file_put_contents($this->tmpDir.'/.rfaignore', $globPattern);

    $pathspecs = $this->service->getExcludePathspecs($this->tmpDir);

    expect($pathspecs)->toContain(":(exclude){$globPattern}");
});
