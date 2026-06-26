<?php

declare(strict_types=1);

use App\Support\FilePathSorter;

/**
 * Sort plain paths with the comparator, mirroring how GetFileListAction
 * applies it to the file list.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function sortPaths(array $paths): array
{
    usort($paths, FilePathSorter::compare(...));

    return $paths;
}

// -- folders-first ordering --

test('lists sub-directories before loose files in the same folder', function () {
    // The artifact this fixes: a flat byte-wise sort puts CLAUDE.md (C) ahead
    // of the Jobs/ directory (J); folders-first reverses that.
    expect(sortPaths([
        'app/Domains/Content/CLAUDE.md',
        'app/Domains/Content/Jobs/BulkShareIssues.php',
        'app/Domains/Content/Models/Issue.php',
    ]))->toBe([
        'app/Domains/Content/Jobs/BulkShareIssues.php',
        'app/Domains/Content/Models/Issue.php',
        'app/Domains/Content/CLAUDE.md',
    ]);
});

test('sorts directories alphabetically then files alphabetically', function () {
    expect(sortPaths([
        'src/zebra.php',
        'src/alpha.php',
        'src/beta/one.php',
        'src/aardvark/two.php',
    ]))->toBe([
        'src/aardvark/two.php',
        'src/beta/one.php',
        'src/alpha.php',
        'src/zebra.php',
    ]);
});

test('keeps deep nesting grouped under its directory', function () {
    expect(sortPaths([
        'a/readme.md',
        'a/b/c/deep.php',
        'a/b/shallow.php',
    ]))->toBe([
        'a/b/c/deep.php',
        'a/b/shallow.php',
        'a/readme.md',
    ]);
});

test('orders root-level files alphabetically', function () {
    expect(sortPaths([
        'README.md',
        'composer.json',
        '.gitignore',
    ]))->toBe([
        '.gitignore',
        'README.md',
        'composer.json',
    ]);
});

test('is idempotent', function () {
    $paths = [
        'app/Services/Git.php',
        'app/CLAUDE.md',
        'app/Actions/Run.php',
        'tests/Unit/Thing.php',
    ];

    expect(sortPaths(sortPaths($paths)))->toBe(sortPaths($paths));
});

// -- compare() primitive --

test('compare puts a folder before a sibling file', function () {
    expect(FilePathSorter::compare('x/dir/file.php', 'x/file.php'))->toBeLessThan(0);
    expect(FilePathSorter::compare('x/file.php', 'x/dir/file.php'))->toBeGreaterThan(0);
});

test('compare returns zero for identical paths', function () {
    expect(FilePathSorter::compare('a/b/c.php', 'a/b/c.php'))->toBe(0);
});
