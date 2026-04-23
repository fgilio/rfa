<?php

use App\DTOs\RemoteTarget;
use App\Services\RemoteUrlBuilderService;

beforeEach(function () {
    $this->builder = new RemoteUrlBuilderService;
    $this->github = [
        'provider' => 'github',
        'host' => 'github.com',
        'owner' => 'fgilio',
        'repo' => 'rfa',
        'webBaseUrl' => 'https://github.com/fgilio/rfa',
    ];
    $this->gitlab = [
        'provider' => 'gitlab',
        'host' => 'gitlab.com',
        'owner' => 'acme/team',
        'repo' => 'backend',
        'webBaseUrl' => 'https://gitlab.com/acme/team/backend',
    ];
});

test('builds repo url for github', function () {
    expect($this->builder->build($this->github, RemoteTarget::repo()))
        ->toBe('https://github.com/fgilio/rfa');
});

test('builds branch url for github', function () {
    expect($this->builder->build($this->github, RemoteTarget::branch('main')))
        ->toBe('https://github.com/fgilio/rfa/tree/main');
});

test('builds branch url for gitlab uses dash prefix', function () {
    expect($this->builder->build($this->gitlab, RemoteTarget::branch('main')))
        ->toBe('https://gitlab.com/acme/team/backend/-/tree/main');
});

test('preserves slashes in branch names with path segments', function () {
    expect($this->builder->build($this->github, RemoteTarget::branch('release/1.0')))
        ->toBe('https://github.com/fgilio/rfa/tree/release/1.0');
});

test('builds commit url for github', function () {
    expect($this->builder->build($this->github, RemoteTarget::commit('abc123')))
        ->toBe('https://github.com/fgilio/rfa/commit/abc123');
});

test('builds commit url for gitlab with dash prefix', function () {
    expect($this->builder->build($this->gitlab, RemoteTarget::commit('abc123')))
        ->toBe('https://gitlab.com/acme/team/backend/-/commit/abc123');
});

test('builds file url for github with nested path', function () {
    expect($this->builder->build($this->github, RemoteTarget::file('main', 'src/app/Models/Project.php')))
        ->toBe('https://github.com/fgilio/rfa/blob/main/src/app/Models/Project.php');
});

test('builds file url for gitlab with dash prefix', function () {
    expect($this->builder->build($this->gitlab, RemoteTarget::file('main', 'app/Http/Controller.php')))
        ->toBe('https://gitlab.com/acme/team/backend/-/blob/main/app/Http/Controller.php');
});

test('builds single-line url for github', function () {
    expect($this->builder->build($this->github, RemoteTarget::line('main', 'README.md', 42)))
        ->toBe('https://github.com/fgilio/rfa/blob/main/README.md#L42');
});

test('builds line range url for github with L<start>-L<end>', function () {
    expect($this->builder->build($this->github, RemoteTarget::line('main', 'README.md', 10, 20)))
        ->toBe('https://github.com/fgilio/rfa/blob/main/README.md#L10-L20');
});

test('builds line range url for gitlab with L<start>-<end> (no L on end)', function () {
    expect($this->builder->build($this->gitlab, RemoteTarget::line('main', 'README.md', 10, 20)))
        ->toBe('https://gitlab.com/acme/team/backend/-/blob/main/README.md#L10-20');
});

test('normalises line range when end equals start to a single-line anchor', function () {
    expect($this->builder->build($this->github, RemoteTarget::line('main', 'foo.php', 5, 5)))
        ->toBe('https://github.com/fgilio/rfa/blob/main/foo.php#L5');
});

test('swaps line start/end when end precedes start', function () {
    expect($this->builder->build($this->github, RemoteTarget::line('main', 'foo.php', 20, 10)))
        ->toBe('https://github.com/fgilio/rfa/blob/main/foo.php#L10-L20');
});

test('url-encodes branch names with unsafe characters', function () {
    $result = $this->builder->build($this->github, RemoteTarget::branch('feature/my branch'));

    expect($result)->toBe('https://github.com/fgilio/rfa/tree/feature/my%20branch');
});

test('url-encodes path segments but keeps slashes', function () {
    $result = $this->builder->build($this->github, RemoteTarget::file('main', 'a b/c d.php'));

    expect($result)->toBe('https://github.com/fgilio/rfa/blob/main/a%20b/c%20d.php');
});
