# Contributing

## Requirements

- PHP 8.3+
- Composer

## Setup

```bash
cd src
composer install
```

## Running tests

```bash
composer test:lint   # Code style (Pint)
composer test:types  # Static analysis (PHPStan level 6)
composer test        # Pest test suite
```

## Running locally

```bash
# From any git repo with uncommitted changes:
/path/to/rfa
```

## Pull requests

- Keep changes focused - one concern per PR
- Ensure all three test commands pass before submitting
- Follow existing code style (enforced by Pint)
