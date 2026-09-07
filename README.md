# Laravel EIX Pricing

Laravel package for streaming EIX pre-trade data into a database and exposing
the latest quote by ISIN.

> This package is under active development and is not ready for production.

## Requirements

- PHP 8.4 or newer
- Laravel 13

## Installation

```bash
composer require gybra/laravel-eix-pricing
```

Laravel discovers the package service provider automatically. Publish the
configuration when application-specific values are needed:

```bash
php artisan vendor:publish --tag=eix-pricing-config
```

## Configuration

Runtime settings use the `EIX_` environment prefix. They cover the discovery
and download endpoints, HTTP timeouts and retries, database connection,
transient storage disk and prefix, import batch size, schedule, routes and
throttle. Source objects are removed after every import attempt.
See [`config/eix-pricing.php`](config/eix-pricing.php) for every setting and
default.

## Database

Package migrations load automatically and use `EIX_DB_CONNECTION`, falling
back to the application's default connection. Run them with:

```bash
php artisan migrate
```

The package keeps one current quote per ISIN using bounded conditional
upserts. See [`docs/persistence.md`](docs/persistence.md) for ordering and
transaction semantics.

## Source contract

The verified EIX discovery, download and CSV formats are documented in
[`docs/eix-source-contract.md`](docs/eix-source-contract.md). The source
provides ISINs but no authoritative ticker mapping, so the initial release is
ISIN-only.

## Development

```bash
composer install
composer test
composer format:check
composer analyse
```

## License

Laravel EIX Pricing is open-source software licensed under the
[MIT License](LICENSE).
