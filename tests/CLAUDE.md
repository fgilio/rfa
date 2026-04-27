# Testing Guidelines

## Stack

- Pest 4 on PHPUnit 12
- Default fast path: `composer test` (parallel via paratest, ~6s)
- Single-process fallback: `composer test:serial` (when debugging worker isolation)
- Arch only: `composer test:arch`
- Browser: `composer test:browser`
- JS unit (Vitest + happy-dom): `composer test:js`
- Perf benchmark: `composer test:perf`
- Perf smoke suite: `composer test:perf:smoke`
- Full local suite pass: `composer test:all`

## JS unit tests

For loose scripts in `public/js/` that have non-trivial logic (timers, DOM
event handling, state machines), add Vitest unit tests under `tests/Js/`.

- File pattern: `tests/Js/*.test.js` (picked up by `vitest.config.js`).
- Environment: `happy-dom` — provides `window`, `document`, events, but no
  network. Override `document.hasFocus()` and `document.hidden` via
  `vi.spyOn` / `Object.defineProperty` when driving focus transitions.
- Use `vi.useFakeTimers()` + `vi.advanceTimersByTimeAsync(ms)` for any
  `setTimeout`/`setInterval` logic. Real timers in DOM tests are flaky.
- Loose scripts are imported via UMD-style detection: the file checks for
  `module.exports` at evaluation and exports its internals to Node, while
  still auto-installing in the browser. See `public/js/smart-poll.js`.
- Don't import the production script in a way that triggers `autoInstall` —
  the UMD wrapper handles that. Importing the default export is enough.

## Parallel test isolation

`composer test` runs Pest with `--parallel` (paratest under the hood). Each
worker gets its own SQLite (via `:memory:`), blade compile dir
(`storage/framework/views/test_<token>`), and Livewire compile dir
(`storage/framework/views/livewire_test_<token>`, set up in `Tests\TestCase`).
Child PHP processes spawned during a test inherit `VIEW_COMPILED_PATH` so they
target the same isolated blade dir.

If you need shared mutable state in a test, switch to `composer test:serial`
or scope the test to its own file so it gets its own worker.

Boot-time code (service providers, registered macros, etc.) that mutates
shared paths like `storage/framework/views` must short-circuit if **either**
of the following is true: `app()->environment('testing')`, or
`getenv(BenchmarkIsolation::ENV_ENABLED) === '1'`. Either flag alone is
enough to trigger the guard. Otherwise it races against other workers'
isolated compile dirs and produces flakes that only reproduce under
`--parallel`. See `NativeAppServiceProvider::clearCompiledViewsForDev` for
the pattern.

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
- If you must build an ad-hoc path under `sys_get_temp_dir()`, scope it by
  **both** `getmypid()` and `uniqid('', true)` (e.g.
  `sys_get_temp_dir().'/rfa_test_foo_'.getmypid().'_'.uniqid('', true)`).
  `afterEach` cleanup must glob the same PID-scoped prefix — a bare
  `rfa_test_foo_*` glob wipes other parallel workers' files mid-run and
  produces flakes that only surface under `--parallel`.
- `initTestRepo()` copies a per-process `.git` template (single `cp -R`) instead
  of running `git init` + 3 `git config` calls. Author/committer/`commit.gpgsign`
  come from `GIT_AUTHOR_*` / `GIT_CONFIG_*` env vars in `phpunit.xml`, so
  don't expect tests to override these via `.git/config`.

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
