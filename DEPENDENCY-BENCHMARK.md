# Dependency update benchmark

Measured on 2026-11-05. All compatible project dependencies are updated. This comparison uses a fresh baseline with Low Power Mode off. Earlier Phiki runs are excluded.

## Main results

| Case | Before ms | After ms | Time change |
| --- | --- | --- | --- |
| Review page, 100 files | 96.796 | 82.128 | -15.2% |
| Diff with comments | 17.459 | 11.395 | -34.7% |
| Load full Blade diff | 92.823 | 81.246 | -12.5% |
| Browser: expand 2,220 diff lines | 924.0 | 861.0 | -6.8% |
| PHP part of browser action | 88.0 | 81.0 | -8.0% |
| PHP launch-cost proxy | 220.5 | 200.4 | -9.1% |

Negative time changes mean faster. All 12 rendering cases improved in both PHP modes. Both runs passed the CI time and memory limits (5% with a 15 ms absolute time floor, 10% with a 3 MiB memory floor).

Tradeoff: the CSS file grew from **250.6 KiB to 420.7 KiB** (+67.9%). CSS build time rose from 319.9 ms to 337.8 ms. The newer Flux package supplies more CSS and component variants.

## What changed

| Application dependency | Before | After |
| --- | --- | --- |
| laravel/boost | v2.4.11 | v2.10.4 |
| laravel/framework | v13.18.1 | v13.40.0 |
| livewire/blaze | v1.0.12 | v1.0.21 |
| livewire/flux | v2.15.0 | v2.21.0 |
| livewire/livewire | v4.3.3 | v4.4.8 |
| nativephp/desktop | dev-l13-compatibility | 2.4.0 |
| phiki/phiki | v3.0.0-beta1 | v3.0.0-beta1 |
| tempest/highlight | 2.30.0 | 2.30.0 |

| Development dependency | Before | After |
| --- | --- | --- |
| fakerphp/faker | v1.24.1 | v1.24.1 |
| larastan/larastan | v3.10.0 | v3.14.0 |
| laravel/pao | v1.1.2 | v1.1.5 |
| laravel/pint | v1.29.3 | v1.32.1 |
| laravel/tinker | v3.0.2 | v3.0.2 |
| mockery/mockery | 1.6.12 | 1.6.15 |
| nunomaduro/collision | v8.9.4 | v8.9.5 |
| pestphp/pest | v4.7.5 | v5.3.5 |
| pestphp/pest-plugin-browser | v4.3.1 | v5.2.0 |
| phpstan/phpstan | 2.2.5 | 2.3.5 |
| phpunit/phpunit | 12.5.30 | 13.4.5 |

| JavaScript dependency | Before | After |
| --- | --- | --- |
| @tailwindcss/cli | 4.3.2 | 4.3.4 |
| happy-dom | 20.10.6 | 21.0.4 |
| playwright | 1.61.1 | 1.64.1 |
| tailwindcss | 4.3.2 | 4.3.4 |
| vitest | 4.1.10 | 5.1.0 |

- Composer: 118 packages updated, 5 added, 5 removed. All before/after versions are in the raw JSON.
- NativePHP moves from the Laravel 13 development branch to stable 2.4.0. Bundled Electron moves from 38.7.2 to 40.10.2. CI and release builds now use Node 24.
- Phiki stays at the requested 3.0.0-beta1. Tempest was already 2.30.0 when this baseline was recorded.
- PHPUnit 13.4.5 is the newest version accepted by Pest 5.3.5. Pest explicitly blocks 13.4.6. This is the only outdated direct Composer package. npm reports no outdated direct packages.

The update also preserves RFA's NativePHP startup patches against the stable package. Two packaging barriers now come from upstream. A new entry patch starts PHP before Electron readiness. The old release-only npm override is removed.

Validation fixes: benchmark fixture bindings are restored between scenarios, the syntax command delegates to an Action, startup measurements fail on child-process errors, browser conditions use counted retrying assertions, and the undo countdown uses a reactive clock.

## Method

```text
Fresh baseline (Low Power Mode off)
├─ PHP rendering: all 12 cases, with OPcache off and on
├─ Syntax: four frozen files, three backend choices
├─ Startup: fresh PHP boots and cache work
├─ Browser: expand a real 2,220-line diff
└─ CSS: build time and output bytes
Update dependencies and pass checks
└─ Repeat the same measurements in sequence
```

- Same laptop, PHP 8.4.27, Node 24.15.0, source bytes, sample counts, and scenario order. Low Power Mode is verified off before, at the start of the after run, and at the end. Both phases used battery power.
- Rendering: five fresh measured child processes, one discarded child, seven measured rounds and two warmup rounds per case in each child. Reports use the existing median and outlier rules.
- OPcache-on runs use an extra ini file with `opcache.enable_cli=1`, inherited by child processes. The packaged app has its own cache sizes and four PHP workers.
- Each rendering run has an isolated temporary SQLite database and in-memory cache/session stores. The diff-loading cases use the real Action, Git, parser, and highlighter.
- Syntax: five fresh child processes, five measured rounds and two warmups each. Each round creates the service and highlights both diff sides. File reads, DTO setup, and source-preservation checks are outside timing.
- Startup: seven measured samples and two warmups per step. Child failures stop the command. The launch proxy is two framework boots with a warm opcode cache and no cache rebuild.
- Browser: six fresh test processes, first process discarded. Each measured action expands 2,220 lines and reports `cached: false`. Chromium changes with Playwright, so this measures the full updated test stack.
- CSS: six builds, first discarded. The times include the npm command. CSS output includes the imported Flux styles and discovered utility classes.
- Build and test jobs were stopped before measurements. The machine was kept awake during the after run. Raw samples are retained.

## Rendering with OPcache enabled

| Case | Before ms | After ms | Time change | Before peak MiB | After peak MiB |
| --- | --- | --- | --- | --- | --- |
| diff-large | 16.018 | 13.879 | -13.4% | 4.000 | 6.000 |
| diff-small | 5.526 | 4.527 | -18.1% | 0.000 | 0.000 |
| diff-with-comments | 17.459 | 11.395 | -34.7% | 0.000 | 0.000 |
| drawer-reply-filter | 14.495 | 13.447 | -7.2% | 0.000 | 0.000 |
| flux-2000-mixed | 3.001 | 2.855 | -4.9% | 4.000 | 4.000 |
| flux-500-mixed | 0.870 | 0.764 | -12.2% | 2.000 | 2.000 |
| flux-500-nested | 5.230 | 5.081 | -2.8% | 0.000 | 0.000 |
| load-file-diff-blade-default-context | 23.651 | 13.922 | -41.1% | 0.000 | 0.000 |
| load-file-diff-blade-full-context | 92.823 | 81.246 | -12.5% | 0.000 | 4.000 |
| review-page-100-files | 96.796 | 82.128 | -15.2% | 2.000 | 2.000 |
| review-page-20-files | 39.242 | 31.258 | -20.3% | 0.000 | 0.000 |
| review-page-50-files | 58.436 | 49.835 | -14.7% | 0.000 | 0.000 |

## Rendering with OPcache disabled

| Case | Before ms | After ms | Time change | Before peak MiB | After peak MiB |
| --- | --- | --- | --- | --- | --- |
| diff-large | 16.664 | 14.202 | -14.8% | 4.000 | 6.000 |
| diff-small | 5.089 | 4.443 | -12.7% | 0.000 | 0.000 |
| diff-with-comments | 16.499 | 11.302 | -31.5% | 0.000 | 2.000 |
| drawer-reply-filter | 14.485 | 13.495 | -6.8% | 0.000 | 0.000 |
| flux-2000-mixed | 2.998 | 2.814 | -6.1% | 4.000 | 4.000 |
| flux-500-mixed | 0.867 | 0.763 | -12.0% | 2.000 | 0.000 |
| flux-500-nested | 5.330 | 4.784 | -10.2% | 0.000 | 0.000 |
| load-file-diff-blade-default-context | 22.178 | 13.878 | -37.4% | 0.000 | 0.000 |
| load-file-diff-blade-full-context | 97.252 | 81.030 | -16.7% | 0.000 | 0.000 |
| review-page-100-files | 104.092 | 77.676 | -25.4% | 2.000 | 4.000 |
| review-page-20-files | 42.229 | 30.568 | -27.6% | 0.000 | 0.000 |
| review-page-50-files | 60.954 | 48.470 | -20.5% | 0.000 | 0.000 |

Peak and retained memory are changes in PHP allocated memory, not process RSS. Zero means no added allocator block was needed during that sample. Per-sample peak and retained values are in the JSON.

## Syntax highlighting

The highlighter versions are unchanged in this comparison. These numbers are observed timings of the full updated environment. They do not establish a speed change in either highlighting library.

| Input | Choice | Actual backend | Before ms | After ms | Time change |
| --- | --- | --- | --- | --- | --- |
| review.blade.php | auto | tempest | 66.657 | 69.574 | +4.4% |
| review.blade.php | phiki | phiki | 1023.017 | 955.169 | -6.6% |
| review.blade.php | phiki-pcre | phiki | 951.926 | 842.459 | -11.5% |
| service.php | auto | tempest | 23.210 | 21.543 | -7.2% |
| service.php | phiki | phiki | 374.448 | 304.333 | -18.7% |
| service.php | phiki-pcre | phiki | 282.639 | 248.300 | -12.1% |
| composer.json | auto | tempest | 1.850 | 1.726 | -6.7% |
| composer.json | phiki | phiki | 20.006 | 18.110 | -9.5% |
| composer.json | phiki-pcre | phiki | 22.338 | 17.073 | -23.6% |
| example.rs | auto | phiki | 9.878 | 8.696 | -12.0% |
| example.rs | phiki | phiki | 9.330 | 8.669 | -7.1% |
| example.rs | phiki-pcre | phiki | 9.186 | 8.418 | -8.4% |

The PHP input has 632 lines, Blade 1,926, JSON 128, and Rust 16. SHA-256 values match before and after. The auto choice uses Tempest for PHP, Blade, and JSON, and Phiki for Rust. The small Rust input checks fallback behavior.

## PHP startup and cache work

| Step | Before ms | After ms | Time change |
| --- | --- | --- | --- |
| One PHP framework boot, OPcache off | 143.4 | 128.1 | -10.7% |
| One PHP framework boot, OPcache warm | 110.2 | 100.2 | -9.1% |
| Full optimize step | 1524.5 | 1400.3 | -8.1% |
| Config-cache step, OPcache warm | 116.1 | 104.8 | -9.7% |

These are PHP-side probes. They exclude Electron process startup, window presentation, and actual packaged-app cold-start timing. RFA skips repeated optimize work on the same app version and caches native preflight data.

## Browser samples

| Sample | Before total ms | After total ms | Before PHP ms | After PHP ms |
| --- | --- | --- | --- | --- |
| 1 | 864 | 865 | 88 | 81 |
| 2 | 924 | 861 | 93 | 82 |
| 3 | 931 | 856 | 84 | 78 |
| 4 | 941 | 844 | 95 | 78 |
| 5 | 879 | 862 | 82 | 82 |

## Checks and build

- Core: 2,281 passed after the CI scenario-runner check was added. JavaScript: 442 passed. Performance smoke: 5 passed.
- Browser suite: 227 passed with `--fail-on-risky`, zero risky tests. The one optional large-diff probe was run separately six times and passed every run.
- Pint, PHPStan, Composer validation, lockfile install checks, and Git whitespace checks pass.
- A final local macOS arm64 app, ZIP, and DMG were built. Deep signature verification passes. The packaged PHP 8.4.27 executable runs and the final app includes the countdown fix.
- The local build uses version `0.0.0-dependency-check` and an ad hoc signature. It was not published or notarized. The built Electron window was not launched.

Single-run core test duration fell from 16.449 s for 2,276 tests to 13.701 s for 2,280 tests. This is a validation-run observation, not a repeated test-run benchmark.

## Repeat and inspect

```sh
php artisan rfa:benchmark-perf --samples=5 --rounds=7 --snapshot=/tmp/rfa-perf.json
php artisan rfa:benchmark-syntax storage/app/benchmarks/phiki-3/inputs/review.blade.php \
  storage/app/benchmarks/phiki-3/inputs/service.php \
  storage/app/benchmarks/phiki-3/inputs/composer.json \
  storage/app/benchmarks/phiki-3/inputs/example.rs \
  --backend=auto --backend=phiki --backend=phiki-pcre \
  --samples=5 --rounds=5 --warmup-rounds=2 --json
php artisan rfa:startup-bench --samples=7 --warmup=2 --json
```

[Raw report](DEPENDENCY-BENCHMARK.json) contains every rendering sample, syntax round, browser sample, dependency version, power record, and the frozen source inputs. Full local command logs remain in `storage/app/benchmarks/dependency-update-2026-11-05/`.

These are sequential measurements on a working laptop. They measure the complete update, including required compatibility fixes. Individual package effects were not isolated. Small differences can be run-to-run variation.
