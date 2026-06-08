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

// -- assertWithinRepo --

test('assertWithinRepo accepts a path inside the repo', function () {
    $repo = sys_get_temp_dir().'/rfa_pathguard_'.getmypid().'_'.uniqid('', true);
    mkdir($repo.'/sub', 0755, true);

    PathGuard::assertWithinRepo($repo, 'sub/file.txt');
    expect(true)->toBeTrue();

    exec('rm -rf '.escapeshellarg($repo));
});

test('assertWithinRepo accepts a leaf that is itself a symlink (about to be replaced)', function () {
    $repo = sys_get_temp_dir().'/rfa_pathguard_'.getmypid().'_'.uniqid('', true);
    $outside = sys_get_temp_dir().'/rfa_pathguard_out_'.getmypid().'_'.uniqid('', true);
    mkdir($repo, 0755, true);
    mkdir($outside, 0755, true);
    file_put_contents($outside.'/target.txt', 'x');
    symlink($outside.'/target.txt', $repo.'/leaf.txt');

    // The leaf escaping is fine because the writer unlinks it first. Only the parent dir matters.
    PathGuard::assertWithinRepo($repo, 'leaf.txt');
    expect(true)->toBeTrue();

    exec('rm -rf '.escapeshellarg($repo).' '.escapeshellarg($outside));
});

test('assertWithinRepo rejects a path whose parent directory escapes via a symlink', function () {
    $repo = sys_get_temp_dir().'/rfa_pathguard_'.getmypid().'_'.uniqid('', true);
    $outside = sys_get_temp_dir().'/rfa_pathguard_out_'.getmypid().'_'.uniqid('', true);
    mkdir($repo, 0755, true);
    mkdir($outside, 0755, true);
    symlink($outside, $repo.'/escape');

    try {
        expect(fn () => PathGuard::assertWithinRepo($repo, 'escape/x.txt'))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        exec('rm -rf '.escapeshellarg($repo).' '.escapeshellarg($outside));
    }
});

// -- resolveWithinRepo --

test('resolveWithinRepo returns the real path for an existing in-repo file', function () {
    $repo = sys_get_temp_dir().'/rfa_pathguard_'.getmypid().'_'.uniqid('', true);
    mkdir($repo.'/sub', 0755, true);
    file_put_contents($repo.'/sub/file.txt', 'x');

    try {
        expect(PathGuard::resolveWithinRepo($repo, 'sub/file.txt'))
            ->toBe(realpath($repo.'/sub/file.txt'));
    } finally {
        exec('rm -rf '.escapeshellarg($repo));
    }
});

test('resolveWithinRepo rejects a readable leaf symlink that escapes the repo', function () {
    $repo = sys_get_temp_dir().'/rfa_pathguard_'.getmypid().'_'.uniqid('', true);
    $outside = sys_get_temp_dir().'/rfa_pathguard_out_'.getmypid().'_'.uniqid('', true);
    mkdir($repo, 0755, true);
    mkdir($outside, 0755, true);
    file_put_contents($outside.'/target.txt', 'x');
    symlink($outside.'/target.txt', $repo.'/leaf.txt');

    try {
        expect(fn () => PathGuard::resolveWithinRepo($repo, 'leaf.txt'))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        exec('rm -rf '.escapeshellarg($repo).' '.escapeshellarg($outside));
    }
});

test('resolveWithinRepo can return a leaf symlink identity without following it', function () {
    $repo = sys_get_temp_dir().'/rfa_pathguard_'.getmypid().'_'.uniqid('', true);
    $outside = sys_get_temp_dir().'/rfa_pathguard_out_'.getmypid().'_'.uniqid('', true);
    mkdir($repo, 0755, true);
    mkdir($outside, 0755, true);
    file_put_contents($outside.'/target.txt', 'x');
    symlink($outside.'/target.txt', $repo.'/leaf.txt');

    try {
        expect(PathGuard::resolveWithinRepo($repo, 'leaf.txt', followLeaf: false))
            ->toBe(realpath($repo).'/leaf.txt');
    } finally {
        exec('rm -rf '.escapeshellarg($repo).' '.escapeshellarg($outside));
    }
});
