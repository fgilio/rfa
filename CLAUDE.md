## Tests

cd src && composer test:lint   # Pint
cd src && composer test:types  # PHPStan
cd src && composer test        # Pest

## Code style

- Prefer Laravel collections over foreach/for loops
- No external resources (CDNs, Google Fonts) - all assets served locally (enforced by arch test)

## Running locally

./rfa
