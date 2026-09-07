# Contributing

## Development setup

Requirements are PHP 8.4 or newer and Composer 2.

```bash
git clone https://github.com/Gybra/laravel-eix-pricing.git
cd laravel-eix-pricing
composer install
```

## Changes

1. Open an issue describing the change and its acceptance criteria.
2. Create a branch from `main`.
3. Add or update tests with the implementation.
4. Run the complete local checks.
5. Open a pull request linked to the issue.

```bash
composer test
composer format:check
composer analyse
```

Use Conventional Commits and keep pull requests focused on one coherent
change. Do not commit credentials, downloaded EIX source files or local
configuration.
