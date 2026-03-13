# Autoresearch: RFA Highlighting Performance

## Objective

Continuously discover and validate performance improvements to RFA's syntax
highlighting pipeline. The pipeline is: git diff → parse → tokenize → theme match → HTML.
Tokenization + theme matching dominate (~95% of total time).

## Current Baseline

_Not yet captured. Run `/autoresearch-perf` to begin._

## Target Files

| File | Role | Current Optimizations |
|------|------|-----------------------|
| `app/Services/SyntaxHighlightService.php` | Tokenizer + theme matching | Direct token API, scope-cached theme matching |
| `app/Actions/LoadFileDiffAction.php` | Orchestration + caching | Self-healing cache, version-keyed cache keys |
| `app/Services/DiffParser.php` | Git diff parsing | None |
| `app/Support/GrammarMap.php` | File extension → grammar | Static map |
| `app/DTOs/DiffLine.php` | Line data container | Immutable DTO |
| `app/DTOs/Hunk.php` | Hunk data container | Immutable DTO |

## Experiments

_None yet._
