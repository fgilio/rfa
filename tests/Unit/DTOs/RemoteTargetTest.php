<?php

use App\DTOs\RemoteTarget;

test('repo factory has no params', function () {
    $t = RemoteTarget::repo();

    expect($t->type)->toBe(RemoteTarget::TYPE_REPO);
    expect($t->params)->toBe([]);
});

test('branch factory rejects empty name', function () {
    expect(fn () => RemoteTarget::branch(''))
        ->toThrow(InvalidArgumentException::class);
});

test('commit factory rejects empty sha', function () {
    expect(fn () => RemoteTarget::commit(''))
        ->toThrow(InvalidArgumentException::class);
});

test('file factory rejects empty ref or path', function () {
    expect(fn () => RemoteTarget::file('', 'foo.php'))->toThrow(InvalidArgumentException::class);
    expect(fn () => RemoteTarget::file('main', ''))->toThrow(InvalidArgumentException::class);
});

test('line factory rejects invalid line numbers', function () {
    expect(fn () => RemoteTarget::line('main', 'foo.php', 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => RemoteTarget::line('main', 'foo.php', -1))->toThrow(InvalidArgumentException::class);
    expect(fn () => RemoteTarget::line('main', 'foo.php', 1, -5))->toThrow(InvalidArgumentException::class);
    expect(fn () => RemoteTarget::line('main', 'foo.php', 5, 0))->toThrow(InvalidArgumentException::class);
});

test('line factory collapses end-equals-start to single-line', function () {
    $t = RemoteTarget::line('main', 'foo.php', 5, 5);

    expect($t->params['start'])->toBe(5);
    expect($t->params['end'])->toBeNull();
});

test('line factory normalises reversed start/end', function () {
    $t = RemoteTarget::line('main', 'foo.php', 20, 10);

    expect($t->params['start'])->toBe(10);
    expect($t->params['end'])->toBe(20);
});

test('fromWire reconstructs each target type', function () {
    expect(RemoteTarget::fromWire('repo', [])->type)->toBe(RemoteTarget::TYPE_REPO);
    expect(RemoteTarget::fromWire('branch', ['name' => 'main'])->params['name'])->toBe('main');
    expect(RemoteTarget::fromWire('commit', ['sha' => 'abc'])->params['sha'])->toBe('abc');

    $file = RemoteTarget::fromWire('file', ['ref' => 'main', 'path' => 'a.php']);
    expect($file->params)->toBe(['ref' => 'main', 'path' => 'a.php']);

    $line = RemoteTarget::fromWire('line', ['ref' => 'main', 'path' => 'a.php', 'start' => 10, 'end' => 20]);
    expect($line->params['start'])->toBe(10);
    expect($line->params['end'])->toBe(20);
});

test('fromWire rejects unknown type', function () {
    expect(fn () => RemoteTarget::fromWire('unknown', []))
        ->toThrow(InvalidArgumentException::class);
});

test('fromWire rejects missing required params via the factory', function () {
    expect(fn () => RemoteTarget::fromWire('branch', []))->toThrow(InvalidArgumentException::class);
    expect(fn () => RemoteTarget::fromWire('commit', []))->toThrow(InvalidArgumentException::class);
});
