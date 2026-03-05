# Arch Tests

## No Laravel app context

Arch tests run without `TestCase` - no facades, no `resource_path()`, no `app()`. Use raw PHP (`RecursiveDirectoryIterator`, `file_get_contents`, `__DIR__`) for filesystem operations.

## Syntax

- `arch('description')` for Pest arch DSL (namespace/class assertions)
- `test('description', fn)` for custom file-scanning rules (e.g. blade conventions)

Both can coexist in the same file.

## Path resolution

Use `dirname(__DIR__, 2)` to reach the project root from `tests/Arch/`.
