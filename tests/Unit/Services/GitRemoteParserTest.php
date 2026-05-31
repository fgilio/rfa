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

test('preserves http scheme for explicitly-insecure self-hosted remotes', function () {
    $result = $this->parser->parse('http://gitlab.internal.corp/team/project.git');

    expect($result['scheme'])->toBe('http');
    expect($result['webBaseUrl'])->toBe('http://gitlab.internal.corp/team/project');
});

test('upgrades ssh:// and git:// remotes to https for the web url', function () {
    $ssh = $this->parser->parse('ssh://git@github.com/fgilio/rfa.git');
    $git = $this->parser->parse('git://github.com/fgilio/rfa.git');

    expect($ssh['scheme'])->toBe('https');
    expect($ssh['webBaseUrl'])->toBe('https://github.com/fgilio/rfa');
    expect($git['scheme'])->toBe('https');
    expect($git['webBaseUrl'])->toBe('https://github.com/fgilio/rfa');
});

test('defaults scp-style remotes to https', function () {
    $result = $this->parser->parse('git@github.com:fgilio/rfa.git');

    expect($result['scheme'])->toBe('https');
    expect($result['webBaseUrl'])->toBe('https://github.com/fgilio/rfa');
});

test('parses ssh:// url with explicit port without leaking the port into the path', function () {
    $result = $this->parser->parse('ssh://git@github.com:2222/fgilio/rfa.git');

    expect($result['provider'])->toBe('github');
    expect($result['host'])->toBe('github.com');
    expect($result['owner'])->toBe('fgilio');
    expect($result['repo'])->toBe('rfa');
    expect($result['webBaseUrl'])->toBe('https://github.com/fgilio/rfa');
});

test('parses self-hosted gitlab ssh:// url with userinfo and port', function () {
    $result = $this->parser->parse('ssh://git@gitlab.acme.com:2222/team/backend/project.git');

    expect($result['provider'])->toBe('gitlab');
    expect($result['host'])->toBe('gitlab.acme.com');
    expect($result['owner'])->toBe('team/backend');
    expect($result['repo'])->toBe('project');
    expect($result['webBaseUrl'])->toBe('https://gitlab.acme.com/team/backend/project');
});

test('parses https url with userinfo and port', function () {
    $result = $this->parser->parse('https://user@github.com:8443/fgilio/rfa.git');

    expect($result['host'])->toBe('github.com');
    expect($result['owner'])->toBe('fgilio');
    expect($result['repo'])->toBe('rfa');
    expect($result['webBaseUrl'])->toBe('https://github.com/fgilio/rfa');
});
