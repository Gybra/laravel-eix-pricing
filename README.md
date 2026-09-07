# Laravel EIX Pricing

Laravel package for streaming EIX pre-trade data into a database and exposing
the latest quote by instrument identifier.

> This package is under active development and is not ready for production.

## Requirements

- PHP 8.4 or newer
- Laravel 13

## Installation

```bash
composer require gybra/laravel-eix-pricing
```

Laravel discovers the package service provider automatically.

## Source contract

The verified EIX discovery, download and CSV formats are documented in
[`docs/eix-source-contract.md`](docs/eix-source-contract.md). The source
provides ISINs but no ticker mapping.

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
