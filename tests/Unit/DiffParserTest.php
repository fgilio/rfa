<?php

use App\Enums\LineType;
use App\Services\DiffParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

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
    expect($lines[0]->type)->toBe(LineType::Context);
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

test('resolves a rename whose new path needs control-char quoting', function () {
    // Git quotes only the side with the tab, so the combined `diff --git` line
    // is asymmetrically quoted and cannot be split apart. The rename markers
    // carry each path alone and properly quoted.
    $diff = 'diff --git a/normal.txt "b/has\ttab.txt"'."\n"
        .'similarity index 90%'."\n"
        .'rename from normal.txt'."\n"
        .'rename to "has\ttab.txt"'."\n"
        .'--- a/normal.txt'."\n"
        .'+++ "b/has\ttab.txt"'."\n"
        .'@@ -1 +1 @@'."\n"
        .'-old'."\n"
        .'+new'."\n";

    $files = $this->parser->parse($diff);

    expect($files)->toHaveCount(1)
        ->and($files[0]->status)->toBe('renamed')
        ->and($files[0]->oldPath)->toBe('normal.txt')
        ->and($files[0]->path)->toBe("has\ttab.txt");
});

test('resolves a rename whose old path contains a " b/" substring', function () {
    $diff = 'diff --git a/x y b/z b/p q'."\n"
        .'similarity index 80%'."\n"
        .'rename from x y b/z'."\n"
        .'rename to p q'."\n"
        .'--- a/x y b/z'."\n"
        .'+++ b/p q'."\n"
        .'@@ -1 +1 @@'."\n"
        .'-old'."\n"
        .'+new'."\n";

    $files = $this->parser->parse($diff);

    expect($files)->toHaveCount(1)
        ->and($files[0]->oldPath)->toBe('x y b/z')
        ->and($files[0]->path)->toBe('p q');
});

test('resolves a space-rename that shares an interior remainder', function () {
    // `git mv "d/x yx" "d/x"`: an interior space yields matching after-slash
    // remainders, so a heuristic split of the header lands at the wrong spot.
    $diff = 'diff --git a/d/x yx b/d/x'."\n"
        .'similarity index 70%'."\n"
        .'rename from d/x yx'."\n"
        .'rename to d/x'."\n"
        .'--- a/d/x yx'."\n"
        .'+++ b/d/x'."\n"
        .'@@ -1 +1 @@'."\n"
        .'-old'."\n"
        .'+new'."\n";

    $files = $this->parser->parse($diff);

    expect($files)->toHaveCount(1)
        ->and($files[0]->oldPath)->toBe('d/x yx')
        ->and($files[0]->path)->toBe('d/x');
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

test('parses diff without git prefixes', function () {
    $files = $this->parser->parse(File::get(fixture('no_prefix.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('src/no-prefix.php');
    expect($files[0]->status)->toBe('modified');
    expect($files[0]->additions)->toBe(1);
    expect($files[0]->deletions)->toBe(1);
});

test('parses diff with multi-character git prefixes', function () {
    $files = $this->parser->parse(File::get(fixture('multi_character_prefix.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('src/prefixed.php');
    expect($files[0]->status)->toBe('modified');
    expect($files[0]->additions)->toBe(1);
    expect($files[0]->deletions)->toBe(1);
});

test('parses moved lines from ansi colored git diff', function () {
    $diff = implode("\n", [
        "\e[1mdiff --git a/file.txt b/file.txt\e[m",
        "\e[1mindex 1aa432c..9f8af6f 100644\e[m",
        "\e[1m--- a/file.txt\e[m",
        "\e[1m+++ b/file.txt\e[m",
        "\e[36m@@ -1,4 +1,4 @@\e[m",
        " alpha\e[m",
        "\e[1;35m-one\e[m",
        " two\e[m",
        "\e[1;36m+\e[m\e[1;36mone\e[m",
        " omega\e[m",
        '',
    ]);

    $files = $this->parser->parse($diff, detectMovedLines: true);

    expect($files)->toHaveCount(1);

    $lines = $files[0]->hunks[0]->lines;

    expect($lines[1]->type)->toBe(LineType::Remove)
        ->and($lines[1]->content)->toBe('one')
        ->and($lines[1]->moved)->toBe('old')
        ->and($lines[3]->type)->toBe(LineType::Add)
        ->and($lines[3]->content)->toBe('one')
        ->and($lines[3]->moved)->toBe('new');

    expect(collect($lines)->pluck('content')->implode("\n"))->not->toContain("\e[");
    expect($files[0]->toArray()['hunks'][0]['lines'][1]['moved'])->toBe('old');
});

test('does not strip embedded ansi sequences unless moved line detection is enabled', function () {
    $diff = implode("\n", [
        'diff --git a/script.sh b/script.sh',
        'index 1aa432c..9f8af6f 100644',
        '--- a/script.sh',
        '+++ b/script.sh',
        '@@ -1 +1 @@',
        "-printf '\e[1;35m-old\e[0m'",
        "+printf '\e[1;36m+new\e[0m'",
        '',
    ]);

    $files = $this->parser->parse($diff);

    expect($files)->toHaveCount(1);

    $lines = $files[0]->hunks[0]->lines;

    expect($lines[0]->content)->toContain("\e[1;35m")
        ->and($lines[0]->moved)->toBeNull()
        ->and($lines[1]->content)->toContain("\e[1;36m")
        ->and($lines[1]->moved)->toBeNull();
});

test('parses dimmed moved line markers when enabled', function () {
    $diff = implode("\n", [
        "\e[1mdiff --git a/file.txt b/file.txt\e[m",
        "\e[1mindex 1aa432c..9f8af6f 100644\e[m",
        "\e[1m--- a/file.txt\e[m",
        "\e[1m+++ b/file.txt\e[m",
        "\e[36m@@ -1 +1 @@\e[m",
        "\e[2m-old\e[m",
        "\e[2m+new\e[m",
        '',
    ]);

    $files = $this->parser->parse($diff, detectMovedLines: true);

    expect($files)->toHaveCount(1)
        ->and($files[0]->hunks[0]->lines[0]->moved)->toBe('old')
        ->and($files[0]->hunks[0]->lines[1]->moved)->toBe('new');
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

// -- Symlink tests --

test('parses new symlink', function () {
    $files = $this->parser->parse(File::get(fixture('symlink_new.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('AGENTS.md');
    expect($files[0]->status)->toBe('added');
    expect($files[0]->isSymlink)->toBeTrue();
    expect($files[0]->symlinkTarget)->toBe('CLAUDE.md');
    expect($files[0]->additions)->toBe(1);
});

test('parses deleted symlink', function () {
    $files = $this->parser->parse(File::get(fixture('symlink_deleted.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->path)->toBe('AGENTS.md');
    expect($files[0]->status)->toBe('deleted');
    expect($files[0]->isSymlink)->toBeTrue();
    expect($files[0]->symlinkTarget)->toBe('CLAUDE.md');
});

test('parses modified symlink target', function () {
    $files = $this->parser->parse(File::get(fixture('symlink_modified.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->isSymlink)->toBeTrue();
    expect($files[0]->symlinkTarget)->toBe('NEW_TARGET.md');
    expect($files[0]->additions)->toBe(1);
    expect($files[0]->deletions)->toBe(1);
});

test('regular new file is not detected as symlink', function () {
    $files = $this->parser->parse(File::get(fixture('new_file.diff')));

    expect($files)->toHaveCount(1);
    expect($files[0]->isSymlink)->toBeFalse();
    expect($files[0]->symlinkTarget)->toBeNull();
});

test('parses paths containing " b/" without splitting at the wrong boundary', function () {
    $diff = <<<'DIFF'
diff --git a/lib b/util.js b/lib b/util.js
index abc1234..def5678 100644
--- a/lib b/util.js
+++ b/lib b/util.js
@@ -1 +1 @@
-old
+new
DIFF;

    $files = $this->parser->parse($diff);

    expect($files)->toHaveCount(1)
        ->and($files[0]->path)->toBe('lib b/util.js')
        ->and($files[0]->oldPath)->toBeNull()
        ->and($files[0]->status)->toBe('modified');
});
