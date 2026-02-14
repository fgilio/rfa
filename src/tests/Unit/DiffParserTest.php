<?php

use App\Services\DiffParser;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->parser = new DiffParser;
});

test('parses simple modification', function () {
    $files = $this->parser->parse(File::get(fixture('simple.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('src/hello.php');
    expect($files[0]->status)->toBe('modified');
    expect($files[0]->additions)->toBe(3);
    expect($files[0]->deletions)->toBe(2);
    expect($files[0]->hunks)->toHaveCount(1);

    $lines = $files[0]->hunks[0]->lines;
    expect($lines[0]->type)->toBe('context');
    expect($lines[0]->content)->toBe('<?php');
    expect($lines[0]->oldLineNum)->toBe(1);
    expect($lines[0]->newLineNum)->toBe(1);
});

test('parses new file', function () {
    $files = $this->parser->parse(File::get(fixture('new_file.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('src/new.php');
    expect($files[0]->status)->toBe('added');
    expect($files[0]->additions)->toBe(3);
    expect($files[0]->deletions)->toBe(0);
});

test('parses deleted file', function () {
    $files = $this->parser->parse(File::get(fixture('deleted_file.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('old.txt');
    expect($files[0]->status)->toBe('deleted');
    expect($files[0]->additions)->toBe(0);
    expect($files[0]->deletions)->toBe(3);
});

test('parses renamed file', function () {
    $files = $this->parser->parse(File::get(fixture('renamed.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('new_name.php');
    expect($files[0]->status)->toBe('renamed');
    expect($files[0]->oldPath)->toBe('old_name.php');
});

test('parses binary file', function () {
    $files = $this->parser->parse(File::get(fixture('binary.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('image.png');
    expect($files[0]->isBinary)->toBeTrue();
    expect($files[0]->hunks)->toBeEmpty();
});

test('parses multiple hunks', function () {
    $files = $this->parser->parse(File::get(fixture('multi_hunk.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->hunks)->toHaveCount(2);
    expect($files[0]->hunks[0]->oldStart)->toBe(1);
    expect($files[0]->hunks[1]->oldStart)->toBe(20);
});

test('handles no newline at end of file', function () {
    $files = $this->parser->parse(File::get(fixture('no_newline.diff')));

    expect($files)->toHaveCount(1);
    // Should not include the marker as a diff line
    $lines = $files[0]->hunks[0]->lines;
    $contents = array_map(fn ($l) => $l->content, $lines);
    expect($contents)->not->toContain('\ No newline at end of file');
});

test('returns empty for empty input', function () {
    expect($this->parser->parse(''))->toBeEmpty();
    expect($this->parser->parse('  '))->toBeEmpty();
});

test('parses diff with non-standard git prefixes', function () {
    $files = $this->parser->parse(File::get(fixture('custom_prefix.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('src/hello.php');
    expect($files[0]->status)->toBe('modified');
    expect($files[0]->additions)->toBe(1);
    expect($files[0]->deletions)->toBe(1);
});

test('parses multiple files in one diff', function () {
    $diff = File::get(fixture('simple.diff'))."\n".File::get(fixture('new_file.diff'));
    $files = $this->parser->parse($diff);

    expect($files)->toHaveCount(2);
});

// -- parseSingle tests --

test('parseSingle returns FileDiff for single-file diff', function () {
    $result = $this->parser->parseSingle(File::get(fixture('simple.diff')));

    expect($result)->not->toBeNull();
    expect($result->path)->toBe('src/hello.php');
    expect($result->hunks)->toHaveCount(1);
});

test('parseSingle returns null for empty input', function () {
    expect($this->parser->parseSingle(''))->toBeNull();
    expect($this->parser->parseSingle('  '))->toBeNull();
});
