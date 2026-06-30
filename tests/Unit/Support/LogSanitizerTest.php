<?php

use App\Support\LogSanitizer;

test('collapses whitespace and newlines into single spaces', function () {
    expect(LogSanitizer::summary("fatal:  bad\n  object   HEAD\n"))
        ->toBe('fatal: bad object HEAD');
});

test('strips ANSI color sequences', function () {
    expect(LogSanitizer::summary("\e[31mfatal:\e[0m not a git repository"))
        ->toBe('fatal: not a git repository');
});

test('replaces the home directory with a tilde', function () {
    $home = $_SERVER['HOME'] ?? getenv('HOME');

    if (! is_string($home) || $home === '' || $home === '/') {
        $this->markTestSkipped('No usable HOME to scrub.');
    }

    expect(LogSanitizer::summary("cannot read {$home}/code/secret-repo/.git/config"))
        ->toBe('cannot read ~/code/secret-repo/.git/config');
});

test('leaves a sibling directory that merely shares the home prefix intact', function () {
    $home = $_SERVER['HOME'] ?? getenv('HOME');

    if (! is_string($home) || $home === '' || $home === '/') {
        $this->markTestSkipped('No usable HOME to scrub.');
    }

    // `<home>2/...` is a different directory and must not be mangled into `~2/...`.
    expect(LogSanitizer::summary("cannot read {$home}2/other/repo"))
        ->toBe('cannot read '.rtrim($home, '/').'2/other/repo');
});

test('truncates overlong text with an ellipsis marker', function () {
    $summary = LogSanitizer::summary(str_repeat('x', 500), 200);

    expect(mb_strlen($summary))->toBe(201) // 200 chars + the … marker
        ->and($summary)->toEndWith('…');
});

test('trims a trailing space at the truncation boundary before the marker', function () {
    // 199 non-spaces, then a space as the 200th char: rtrim drops it so the
    // marker never trails whitespace, yielding 199 chars + the marker.
    $summary = LogSanitizer::summary(str_repeat('a', 199).' '.str_repeat('b', 50), 200);

    expect($summary)->toEndWith('a…')
        ->and(mb_strlen($summary))->toBe(200);
});

test('leaves short clean text untouched', function () {
    expect(LogSanitizer::summary('unknown revision'))->toBe('unknown revision');
});
