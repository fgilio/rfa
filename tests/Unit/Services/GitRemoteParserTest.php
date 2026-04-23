<?php

use App\Services\GitRemoteParser;

beforeEach(function () {
    $this->parser = new GitRemoteParser;
});

test('parses github https url', function () {
    $result = $this->parser->parse('https://github.com/fgilio/rfa.git');

    expect($result)->not->toBeNull();
    expect($result['provider'])->toBe('github');
    expect($result['host'])->toBe('github.com');
    expect($result['owner'])->toBe('fgilio');
    expect($result['repo'])->toBe('rfa');
    expect($result['webBaseUrl'])->toBe('https://github.com/fgilio/rfa');
});

test('parses github https url without .git suffix', function () {
    $result = $this->parser->parse('https://github.com/fgilio/rfa');

    expect($result['repo'])->toBe('rfa');
    expect($result['webBaseUrl'])->toBe('https://github.com/fgilio/rfa');
});

test('parses github ssh scp-style url', function () {
    $result = $this->parser->parse('git@github.com:fgilio/rfa.git');

    expect($result['provider'])->toBe('github');
    expect($result['host'])->toBe('github.com');
    expect($result['owner'])->toBe('fgilio');
    expect($result['repo'])->toBe('rfa');
});

test('parses ssh:// url form', function () {
    $result = $this->parser->parse('ssh://git@github.com/fgilio/rfa.git');

    expect($result['provider'])->toBe('github');
    expect($result['owner'])->toBe('fgilio');
    expect($result['repo'])->toBe('rfa');
});

test('parses gitlab https url with nested group', function () {
    $result = $this->parser->parse('https://gitlab.com/acme/team/backend.git');

    expect($result['provider'])->toBe('gitlab');
    expect($result['host'])->toBe('gitlab.com');
    expect($result['owner'])->toBe('acme/team');
    expect($result['repo'])->toBe('backend');
    expect($result['webBaseUrl'])->toBe('https://gitlab.com/acme/team/backend');
});

test('parses gitlab ssh scp-style url with nested group', function () {
    $result = $this->parser->parse('git@gitlab.com:acme/team/backend.git');

    expect($result['provider'])->toBe('gitlab');
    expect($result['owner'])->toBe('acme/team');
    expect($result['repo'])->toBe('backend');
});

test('recognises self-hosted github enterprise by hostname', function () {
    $result = $this->parser->parse('git@github.acme.com:internal/repo.git');

    expect($result['provider'])->toBe('github');
    expect($result['host'])->toBe('github.acme.com');
    expect($result['webBaseUrl'])->toBe('https://github.acme.com/internal/repo');
});

test('recognises self-hosted gitlab by hostname', function () {
    $result = $this->parser->parse('https://gitlab.mycorp.local/team/project.git');

    expect($result['provider'])->toBe('gitlab');
    expect($result['host'])->toBe('gitlab.mycorp.local');
});

test('returns null for unknown provider', function () {
    $result = $this->parser->parse('https://bitbucket.org/team/project.git');

    expect($result)->toBeNull();
});

test('returns null for empty input', function () {
    expect($this->parser->parse(''))->toBeNull();
    expect($this->parser->parse('   '))->toBeNull();
});

test('returns null for malformed url', function () {
    expect($this->parser->parse('not-a-url'))->toBeNull();
    expect($this->parser->parse('git@github.com'))->toBeNull();
});

test('handles trailing slash', function () {
    $result = $this->parser->parse('https://github.com/fgilio/rfa/');

    expect($result['repo'])->toBe('rfa');
});

test('lowercases the host for stable comparisons', function () {
    $result = $this->parser->parse('https://GitHub.com/fgilio/rfa.git');

    expect($result['host'])->toBe('github.com');
    expect($result['provider'])->toBe('github');
});
