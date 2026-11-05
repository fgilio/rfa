# Phiki 3 benchmark

These earlier runs preceded the Low Power Mode change. Use [the dependency benchmark](DEPENDENCY-BENCHMARK.md) for the new before/after comparison.

Measured on 2026-11-05. RFA now uses Phiki `3.0.0-beta1`. Tempest is updated to `2.30.0` and remains the first choice for its mapped file types. Phiki handles other supported files. The benchmark can force Phiki for any supported input.

## Results

Time is in milliseconds. Lower is better. Each cell is the median of three process medians. Each process has one discarded warmup round and three measured rounds.

| Input | Lines | Phiki 2.2.0 | Phiki 3 default | Phiki 3 PCRE | Tempest 2.27.0 | Tempest 2.30.0 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| service.php | 632 | 812.596 | 313.730 | 272.903 | 21.779 | 22.615 |
| review.blade.php | 1926 | 1903.920 | 999.999 | 868.334 | 66.037 | 66.622 |
| composer.json | 128 | 21.708 | 18.965 | 18.609 | 1.860 | 1.942 |
| example.rs | 16 | 14.649 | 9.275 | 8.699 | n/a | n/a |

Phiki 3 improves all four inputs. Tempest is still faster on the PHP, Blade, and JSON inputs. PCRE is faster than the default Phiki engine on these inputs. Both engines are available through the benchmark command. The app keeps its current engine selection. Tempest 2.30.0 remains about 14x faster than default Phiki 3 on PHP, 15x on Blade, and 10x on JSON. These measurements show no clear speed gain over Tempest 2.27.0.

The Rust input has 16 lines. RFA still maps it to Phiki, so the Tempest cells are n/a. Tempest 2.30.0 includes Rust support, but RFA must change its language map to use it. The current auto result is 10.461 ms and checks the Phiki fallback only. This small input does not support a broad performance claim.

## Peak memory

Values are MiB above memory in use at the start of each measured round. These values use PHP allocation data, not process RSS.

| Input | Phiki 2.2.0 | Phiki 3 default | Phiki 3 PCRE | Tempest 2.27.0 | Tempest 2.30.0 |
| --- | ---: | ---: | ---: | ---: | ---: |
| service.php | 3.532 | 3.427 | 3.480 | 1.409 | 1.415 |
| review.blade.php | 9.206 | 7.736 | 7.904 | 2.729 | 2.735 |
| composer.json | 0.699 | 0.531 | 0.532 | 0.401 | 0.407 |
| example.rs | 0.470 | 0.577 | 0.582 | n/a | n/a |

## Method and limits

- Runtime: PHP 8.4.27, Darwin tesla 27.0.0 Darwin Kernel Version 27.0.0: Tue Aug 11 21:02:16 PDT 2026; root:xnu-13432.1.9~1/RELEASE_ARM64_T8112 arm64.
- CLI settings: `opcache.enable_cli=0`, `pcre.jit=0`. Oniguruma is available and is the default Phiki engine.
- Each sample runs in a fresh PHP process. Each round constructs a fresh service, then calls `highlightHunks()` on one full-context hunk. Context lines run through both old and new sides, as they do in RFA.
- Timing includes service construction, tokenization, theme matching, HTML output, and line mapping. File reads, DTO construction, process startup, and output checks are outside the timed section.
- Every round checks that highlighted HTML preserves each source line. Missing highlighting fails the run.
- These are adapter timings. They do not include Git, Livewire rendering, or Electron display. The packaged app uses different OPcache settings.
- The Phiki 2 baseline was recorded before the dependency update. The Phiki 3 and latest Tempest runs use the same frozen inputs, verified by SHA-256. The Tempest comparison holds Phiki at 3.0.0-beta1, because service construction resolves Phiki themes even on the Tempest path. Test and analysis commands did not run during the final measurements.
- The PHP input is a copy of `SyntaxHighlightService.php`. The Blade input is a copy of the review page. JSON is a copy of `composer.json`. Rust is a small test input.

## Run the benchmark

```sh
php artisan rfa:benchmark-syntax app/Services/SyntaxHighlightService.php composer.json \
  --backend=auto --backend=phiki --backend=phiki-pcre \
  --samples=3 --rounds=3 --warmup-rounds=1
```

Add `--json` for input hashes, package versions, per-process medians, and every round. Use `--warmup-rounds=0 --rounds=1` to measure the first use in each process.

The local frozen inputs and full JSON results are in `storage/app/benchmarks/phiki-3/`. This directory is not tracked by Git. `v2.json` has Phiki 2 with Tempest 2.27, `v3.json` has Phiki 3 with Tempest 2.27, and `tempest-2.30.json` has the latest Tempest auto results.

## Process medians

| Input | Phiki version / Tempest version | Backend | Sample times (ms) |
| --- | --- | --- | --- |
| service.php | 2.2.0 / 2.27.0 | auto | 26.718, 25.285, 30.223 |
| service.php | 2.2.0 / 2.27.0 | phiki | 1044.744, 759.959, 812.596 |
| review.blade.php | 2.2.0 / 2.27.0 | auto | 67.661, 96.105, 69.902 |
| review.blade.php | 2.2.0 / 2.27.0 | phiki | 2100.434, 1828.110, 1903.920 |
| composer.json | 2.2.0 / 2.27.0 | auto | 1.773, 1.789, 1.758 |
| composer.json | 2.2.0 / 2.27.0 | phiki | 21.708, 21.755, 21.702 |
| example.rs | 2.2.0 / 2.27.0 | auto | 14.509, 14.588, 16.323 |
| example.rs | 2.2.0 / 2.27.0 | phiki | 14.696, 14.633, 14.649 |
| service.php | 3.0.0-beta1 / 2.27.0 | auto | 22.501, 21.438, 21.779 |
| service.php | 3.0.0-beta1 / 2.27.0 | phiki | 313.730, 311.611, 330.655 |
| service.php | 3.0.0-beta1 / 2.27.0 | phiki-pcre | 274.833, 260.771, 272.903 |
| review.blade.php | 3.0.0-beta1 / 2.27.0 | auto | 66.037, 68.770, 65.086 |
| review.blade.php | 3.0.0-beta1 / 2.27.0 | phiki | 999.999, 1035.921, 986.633 |
| review.blade.php | 3.0.0-beta1 / 2.27.0 | phiki-pcre | 860.172, 868.334, 1113.330 |
| composer.json | 3.0.0-beta1 / 2.27.0 | auto | 1.859, 1.783, 2.006 |
| composer.json | 3.0.0-beta1 / 2.27.0 | phiki | 19.524, 18.609, 18.965 |
| composer.json | 3.0.0-beta1 / 2.27.0 | phiki-pcre | 18.609, 17.581, 24.713 |
| example.rs | 3.0.0-beta1 / 2.27.0 | auto | 9.990, 8.935, 9.099 |
| example.rs | 3.0.0-beta1 / 2.27.0 | phiki | 9.275, 9.154, 9.291 |
| example.rs | 3.0.0-beta1 / 2.27.0 | phiki-pcre | 8.799, 8.662, 8.699 |
| service.php | 3.0.0-beta1 / 2.30.0 | auto (tempest) | 21.947, 22.615, 24.110 |
| review.blade.php | 3.0.0-beta1 / 2.30.0 | auto (tempest) | 72.801, 65.641, 66.622 |
| composer.json | 3.0.0-beta1 / 2.30.0 | auto (tempest) | 1.894, 2.084, 1.942 |
| example.rs | 3.0.0-beta1 / 2.30.0 | auto (phiki) | 17.798, 10.461, 9.364 |

## Input hashes

| Input | SHA-256 |
| --- | --- |
| service.php | `7c2be39ef3051d33486392178223a40d6ea94be9daafeb2d1648da5240e8abf4` |
| review.blade.php | `1355cf3bb230d579b82955a6c2e79e14a87ebdf88c3a6743985ba485d7f463eb` |
| composer.json | `195f538b5ced5a42b3c5836daabc11722679fd5ff0aae3ce2617800bc27239c0` |
| example.rs | `918a764a5785f68e761f03bb1f1e7f30e0c6c1b3ba7386c2a4fbc3685595f2bb` |

## Tempest update

The live [Packagist metadata](https://repo.packagist.org/p2/tempest/highlight.json) lists **2.30.0**, released on **2026-10-27**. RFA is now locked to **2.30.0**. Its current `^2.25` constraint permits this update.

The [upstream changes](https://github.com/tempestphp/highlight/compare/2.27.0...2.30.0) add languages including Ruby, Swift, Rust, Go, C/C++, Java/Kotlin, C#, TOML, and JSX/TSX. They also fix PHP heredocs, Unicode tag names, marker handling, and text fallback. Some new language support needs RFA mapping changes before it can be used.

Tempest 2.30.0 was measured through the same RFA adapter, with the same input hashes and sample settings. Against Tempest 2.27.0, the observed time changes are +3.8% for PHP, +0.9% for Blade, and +4.4% for JSON. Every absolute increase is below 1 ms. Sample variation is larger than these differences, so these runs do not establish a speed change.

## Validation

- Pest: 166 tests pass across the syntax service, benchmark command, grammar map, diff loading, and cache invalidation. The Tempest heredoc test checks escaped content, balanced spans, and line mapping when the closing label also occurs inside the string.
- Both Phiki engines: old/new sides, independent hunks, blank lines, Unicode, escaped HTML, and light/dark theme classes.
- Pint, PHPStan, Composer validation, and Git whitespace checks.

Phiki is pinned to the requested beta. The diff cache version is increased so the app rebuilds highlighted output from earlier Phiki and Tempest versions.
