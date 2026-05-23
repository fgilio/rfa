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
    'docs/v1..v2/readme.md',
    'name..with..dots.txt',
    'name:with-colon.txt',
    'name with spaces.txt',
]);

test('rejects empty paths', function () {
    PathGuard::assertRelative('');
})->throws(InvalidArgumentException::class);

test('rejects absolute paths', function (string $path) {
    PathGuard::assertRelative($path);
})->with([
    '/etc/passwd',
    '/tmp/file.txt',
    'C:\\Users\\franco\\file.txt',
    'C:/Users/franco/file.txt',
])->throws(InvalidArgumentException::class);

test('rejects path traversal', function (string $path) {
    PathGuard::assertRelative($path);
})->with([
    '../etc/passwd',
    'foo/../../etc/passwd',
    'foo\\..\\bar',
    '..',
    'foo/../bar',
])->throws(InvalidArgumentException::class);
