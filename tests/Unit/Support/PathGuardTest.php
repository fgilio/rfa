<?php

use App\Support\PathGuard;

test('accepts simple relative paths', function (string $path) {
    PathGuard::assertRelative($path);
    expect(true)->toBeTrue(); // no exception
})->with([
    'file.txt',
    'src/app/Models/User.php',
    'deeply/nested/path/to/file.js',
    '.hidden',
    'name with spaces.txt',
]);

test('rejects absolute paths', function (string $path) {
    PathGuard::assertRelative($path);
})->with([
    '/etc/passwd',
    '/tmp/file.txt',
])->throws(InvalidArgumentException::class);

test('rejects path traversal', function (string $path) {
    PathGuard::assertRelative($path);
})->with([
    '../etc/passwd',
    'foo/../../etc/passwd',
    '..',
    'foo/../bar',
])->throws(InvalidArgumentException::class);
