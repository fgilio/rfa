## Tests

cd src && composer test:lint   # Pint
cd src && composer test:types  # PHPStan
cd src && composer test        # Pest

## Code style

- Prefer Laravel collections over foreach/for loops
- No external resources (CDNs, Google Fonts) - all assets served locally (enforced by arch test)

## Caching

- `LoadFileDiffAction` uses self-healing cache: validates cached entries have expected keys (e.g. `syntaxStyles`) before returning. Stale entries are re-computed and overwritten automatically. Prefer adding key checks over bumping `DiffCacheKey` version for format changes.

## Running locally

./rfa

## Releasing

See [.github/CLAUDE.md](.github/CLAUDE.md)
