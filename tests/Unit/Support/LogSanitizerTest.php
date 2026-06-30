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

test('truncates overlong text with an ellipsis marker', function () {
    $summary = LogSanitizer::summary(str_repeat('x', 500), 200);

    expect(mb_strlen($summary))->toBe(201) // 200 chars + the … marker
        ->and($summary)->toEndWith('…');
});

test('leaves short clean text untouched', function () {
    expect(LogSanitizer::summary('unknown revision'))->toBe('unknown revision');
});
