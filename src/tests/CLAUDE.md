# Testing Guidelines

## Stack

- Pest 4 on PHPUnit 12
- Run: `php artisan test` or `php artisan test --parallel`

## Faker

- Import: `use Faker\Factory as Faker;` (not pest-plugin-faker - it's deprecated in Pest 4)
- Seed in `beforeEach` for deterministic, reproducible tests:
  ```php
  $this->faker = Faker::create();
  $this->faker->seed(crc32(static::class . $this->name()));
  ```
- Same seed = same values across runs, making failures reproducible

## Temp Directories

- Pattern: `sys_get_temp_dir().'/rfa_<name>_'.uniqid()`
- Create in `beforeEach`, clean up in `afterEach` - always remove all created files
- See `CommentExporterTest` and `GitDiffServiceTest` for examples

## Assertions

- `expect()` chains only - no PHPUnit `assert*()` methods
- Chain multiple expectations on the same subject when readable

## Test Naming

- `test('lowercase description', fn)` arrow function style
- Action-first: "parses X", "handles X", "returns X", "detects X"
- Group related tests with `// -- section --` comments when needed

## Fixtures

- Directory: `tests/Fixtures/` (capital F - Pest 4 convention)
- Use Pest 4's native `fixture('name.ext')` - returns the **file path**, not contents
- Wrap with `file_get_contents(fixture('name.ext'))` when you need the content

## Reflection

- Acceptable for testing private methods
- Get `ReflectionClass` in `beforeEach`, store method refs on `$this`
- Call via `$this->method->invoke($this->service, ...args)`

## TestCase

- No `uses(Tests\TestCase::class)` unless Laravel app context is needed (e.g. service resolution)
- Pure unit tests should work without it

## Collision Guard

- When test logic depends on distinct random values, use a `do { ... } while` loop
- Example: `do { $b = $faker->word(); } while ($b === $a);`
