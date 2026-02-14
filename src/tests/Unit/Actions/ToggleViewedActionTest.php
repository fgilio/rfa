<?php

use App\Actions\ToggleViewedAction;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->action = new ToggleViewedAction;
    $this->knownPaths = ['a.php', 'b.php', 'c.php'];
});

test('adds file to viewed list', function () {
    $result = $this->action->handle([], 'a.php', $this->knownPaths);

    expect($result)->toBe(['a.php']);
});

test('removes file from viewed list', function () {
    $result = $this->action->handle(['a.php', 'b.php'], 'a.php', $this->knownPaths);

    expect($result)->toBe(['b.php']);
});

test('returns null for unknown path', function () {
    $result = $this->action->handle([], 'unknown.php', $this->knownPaths);

    expect($result)->toBeNull();
});

test('reindexes after removal', function () {
    $result = $this->action->handle(['a.php', 'b.php', 'c.php'], 'b.php', $this->knownPaths);

    expect(array_keys($result))->toBe([0, 1]);
    expect($result)->toBe(['a.php', 'c.php']);
});
