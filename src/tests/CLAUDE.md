# Testing Guidelines

## Stack

- Pest 4 on PHPUnit 12
- Default fast path: `composer test` or `php artisan test`
- Arch only: `composer test:arch`
- Browser: `composer test:browser`
- Perf benchmark: `composer test:perf`
- Perf smoke suite: `composer test:perf:smoke`
- Full local suite pass: `composer test:all`

## Suite Model

- `Core` = `Unit` + `Arch`
- `Browser` = Playwright-backed browser coverage
- `Performance` = deterministic smoke coverage for benchmark scenarios
- CI perf regression gating happens through `php artisan rfa:benchmark-perf`, not PHPUnit thresholds

## Faker

- Import: `use Faker\Factory as Faker;` (not pest-plugin-faker - it's deprecated in Pest 4)
- Seed in `beforeEach` for deterministic, reproducible tests:
  ```php
  $this->faker = Faker::create();
  $this->faker->seed(crc32(static::class . $this->name()));
  ```
- Same seed = same values across runs, making failures reproducible

## Temp Directories

- Prefer `InteractsWithTestRepositories::createTempDirectory()` for tracked temp dirs
- If a test needs git repo setup, use `InteractsWithTestRepositories::initTestRepo()` and `commitTestRepo()`
- Global cleanup in `tests/Pest.php` removes tracked temp dirs after each test

## Assertions

- Chain multiple expectations on the same subject when readable

## Test Naming

- `test('lowercase description', fn)` arrow function style
- Action-first: "parses X", "handles X", "returns X", "detects X"
- Group related tests with `// -- section --` comments when needed

## Fixtures

- Directory: `tests/Fixtures/` (capital F - Pest 4 convention)
- Use Pest 4's native `fixture('name.ext')` - returns the **file path**, not contents
- Wrap with `File::get(fixture('name.ext'))` when you need the content

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

## Browser Tests

- See `tests/Browser/CLAUDE.md` for details

## Performance Benchmarks

- The required CI perf check is the benchmark command, not a hard-coded ms assertion
- Snapshot and compare via `php artisan rfa:benchmark-perf --snapshot=...` and `--compare=...`
- `rfa:benchmark-perf` must run against an isolated temp sqlite database plus non-persistent cache/session stores
- Keep PHPUnit perf tests deterministic and structural
