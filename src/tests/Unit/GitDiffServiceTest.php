<?php

use App\Services\GitDiffService;
use App\Services\IgnoreService;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->service = new GitDiffService(new IgnoreService);

    $ref = new ReflectionClass($this->service);

    $this->isExcluded = $ref->getMethod('isExcluded');
    $this->isExcluded->setAccessible(true);

    $this->isBinary = $ref->getMethod('isBinary');
    $this->isBinary->setAccessible(true);

    $this->tmpDir = sys_get_temp_dir().'/rfa_git_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

// -- isExcluded tests --

test('isExcluded matches exact filename', function () {
    $name = $this->faker->word().'.'.$this->faker->fileExtension();
    $patterns = [":(exclude){$name}"];

    expect($this->isExcluded->invoke($this->service, $name, $patterns))->toBeTrue();
});

test('isExcluded matches glob wildcard', function () {
    $ext = $this->faker->fileExtension();
    $patterns = [":(exclude)*.{$ext}"];

    $file = $this->faker->word().'.'.$ext;

    expect($this->isExcluded->invoke($this->service, $file, $patterns))->toBeTrue();
});

test('isExcluded matches basename in nested path', function () {
    $name = $this->faker->word().'.'.$this->faker->fileExtension();
    $patterns = [":(exclude){$name}"];
    $nested = 'src/deep/nested/'.$name;

    expect($this->isExcluded->invoke($this->service, $nested, $patterns))->toBeTrue();
});

test('isExcluded returns false when no pattern matches', function () {
    $file = $this->faker->word().'.'.$this->faker->fileExtension();
    $patterns = [
        ':(exclude)unrelated.txt',
        ':(exclude)*.zzz',
    ];

    expect($this->isExcluded->invoke($this->service, $file, $patterns))->toBeFalse();
});

// -- isBinary tests --

test('isBinary detects null bytes', function () {
    $path = $this->tmpDir.'/binary.bin';
    $content = $this->faker->sentence()."\0".$this->faker->sentence();
    File::put($path, $content);

    expect($this->isBinary->invoke($this->service, $path))->toBeTrue();
});

test('isBinary returns false for plain text', function () {
    $path = $this->tmpDir.'/text.txt';
    File::put($path, $this->faker->paragraphs(3, true));

    expect($this->isBinary->invoke($this->service, $path))->toBeFalse();
});
