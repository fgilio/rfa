<?php

use App\Actions\DeleteReviewFilesAction;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->action = app(DeleteReviewFilesAction::class);
    $this->tempDir = sys_get_temp_dir().'/rfa_delete_review_'.uniqid();
    $this->rfaDir = $this->tempDir.'/.rfa';
    mkdir($this->rfaDir, 0755, true);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

test('deletes both json and md files', function () {
    $basename = '20250115_143022_comments_AbCd1234';
    File::put($this->rfaDir."/{$basename}.json", '{}');
    File::put($this->rfaDir."/{$basename}.md", '# Review');

    $deleted = $this->action->handle($this->tempDir, $basename);

    expect($deleted)->toBe(2)
        ->and(File::exists($this->rfaDir."/{$basename}.json"))->toBeFalse()
        ->and(File::exists($this->rfaDir."/{$basename}.md"))->toBeFalse();
});

test('handles missing md file gracefully', function () {
    $basename = '20250115_143022_comments_AbCd1234';
    File::put($this->rfaDir."/{$basename}.json", '{}');

    $deleted = $this->action->handle($this->tempDir, $basename);

    expect($deleted)->toBe(1)
        ->and(File::exists($this->rfaDir."/{$basename}.json"))->toBeFalse();
});

test('handles missing json file gracefully', function () {
    $basename = '20250115_143022_comments_AbCd1234';
    File::put($this->rfaDir."/{$basename}.md", '# Review');

    $deleted = $this->action->handle($this->tempDir, $basename);

    expect($deleted)->toBe(1)
        ->and(File::exists($this->rfaDir."/{$basename}.md"))->toBeFalse();
});

test('rejects path traversal in basename', function () {
    $basename = '../../etc/passwd';

    $deleted = $this->action->handle($this->tempDir, $basename);

    expect($deleted)->toBe(0);
});

test('rejects basename with slashes', function () {
    $deleted = $this->action->handle($this->tempDir, 'foo/20250115_143022_comments_AbCd1234');

    expect($deleted)->toBe(0);
});

test('rejects basename not matching expected pattern', function () {
    $deleted = $this->action->handle($this->tempDir, 'random_file_name');

    expect($deleted)->toBe(0);
});

test('bulk deletes multiple basenames', function () {
    $basename1 = '20250115_143022_comments_AbCd1234';
    $basename2 = '20250116_100000_comments_EfGh5678';

    File::put($this->rfaDir."/{$basename1}.json", '{}');
    File::put($this->rfaDir."/{$basename1}.md", '# Review 1');
    File::put($this->rfaDir."/{$basename2}.json", '{}');
    File::put($this->rfaDir."/{$basename2}.md", '# Review 2');

    $deleted = $this->action->handle($this->tempDir, [$basename1, $basename2]);

    expect($deleted)->toBe(4)
        ->and(File::exists($this->rfaDir."/{$basename1}.json"))->toBeFalse()
        ->and(File::exists($this->rfaDir."/{$basename2}.md"))->toBeFalse();
});

test('bulk delete skips invalid basenames', function () {
    $valid = '20250115_143022_comments_AbCd1234';
    File::put($this->rfaDir."/{$valid}.json", '{}');

    $deleted = $this->action->handle($this->tempDir, [$valid, '../../etc/passwd', 'invalid']);

    expect($deleted)->toBe(1);
});
