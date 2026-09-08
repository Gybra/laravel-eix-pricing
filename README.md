# Laravel EIX Pricing

Laravel package for streaming EIX pre-trade data into a database and exposing
the latest quote by ISIN.

> This package is under active development and is not ready for production.

## Architecture

The package discovers EIX sources from the last 30 minutes, streams each file
through local and configured transient storage, parses gzip/CSV records lazily,
and conditionally upserts current quotes in bounded batches. Import metadata
and cache locks make retries and overlapping scheduler instances safe. The host
application only provides Laravel, database, cache, filesystem and scheduler
infrastructure.

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

Runtime settings use the `EIX_` environment prefix. Source objects are removed
after every import attempt.

| Setting | Default |
| --- | --- |
| `EIX_DISCOVERY_URL` | Public EIX pre-trade discovery endpoint |
| `EIX_DOWNLOAD_URL` | Public EIX source-content endpoint |
| `EIX_HTTP_CONNECT_TIMEOUT` | `10` seconds |
| `EIX_HTTP_TIMEOUT` | `30` seconds |
| `EIX_HTTP_DOWNLOAD_TIMEOUT` | `3600` seconds |
| `EIX_HTTP_RETRIES` | `3` |
| `EIX_HTTP_RETRY_DELAY_MS` | `1000` milliseconds |
| `EIX_DB_CONNECTION` | Laravel default connection |
| `EIX_STORAGE_DISK` | `local` |
| `EIX_STORAGE_PREFIX` | `eix` |
| `EIX_IMPORT_BATCH_SIZE` | `1000` |
| `EIX_IMPORT_LOCK_STORE` | Laravel default cache store |
| `EIX_IMPORT_LOCK_NAME` | `eix-pricing:import` |
| `EIX_IMPORT_LOCK_SECONDS` | `10800` seconds |
| `EIX_IMPORT_LOOKBACK_MINUTES` | `30` minutes |
| `EIX_SCHEDULE_ENABLED` | `true` |
| `EIX_SCHEDULE_CRON` | Every 30 minutes, Monday–Friday |
| `EIX_SCHEDULE_OVERLAP_MINUTES` | `180` minutes |
| `EIX_SCHEDULE_ON_ONE_SERVER` | `false` |
| `EIX_PRUNE_CRON` | 01:00 Monday–Friday |
| `EIX_PRUNE_RETENTION_DAYS` | `2` days |
| `EIX_ROUTES_ENABLED` | `true` |
| `EIX_ROUTES_PREFIX` | `api` |
| `EIX_ROUTES_THROTTLE` | `60,1` |

Route middleware is configurable in the published
[`config/eix-pricing.php`](config/eix-pricing.php).

## Database

Package migrations load automatically and use `EIX_DB_CONNECTION`, falling
back to the application's default connection. Run them with:

```bash
php artisan migrate
```

The package keeps one current quote per ISIN using bounded conditional
upserts. See [`docs/persistence.md`](docs/persistence.md) for ordering and
transaction semantics.

## Importing

Run an import manually with:

```bash
php artisan eix:import
```

Each run imports every uncompleted source whose timestamp falls inside
`EIX_IMPORT_LOOKBACK_MINUTES` (default 30). The package schedules the same
command every 30 minutes on weekdays with overlap protection. At 01:00 on
weekdays it also runs `eix:prune-quotes`, deleting quotes whose `imported_at`
is older than `EIX_PRUNE_RETENTION_DAYS` (default 2). Saturday and Sunday skip
both import and prune because markets are closed. Set
`EIX_SCHEDULE_ENABLED=false` to disable both schedules. Enable `EIX_SCHEDULE_ON_ONE_SERVER=true` only when
every application instance shares a lock-capable cache store.

```bash
php artisan eix:prune-quotes
```

The host application must run Laravel's scheduler. No queue worker or Redis is
required.

## Quote API

```text
GET /api/quotes/{isin}
```

```json
{"data":{"isin":"IE000EOFR2K5","price":4.5425,"bid":4.4615,"ask":4.6235,"quoted_at":"2026-09-07T20:41:00.000000Z"}}
```

The endpoint is public and throttled. Invalid ISINs return 422 and missing
quotes return 404. Route enablement, prefix, middleware and throttle are
configurable.

## Google Apps Script

Add this custom function to a Google Sheet through **Extensions → Apps
Script**, replacing the base URL with the Laravel host URL:

```javascript
function EIXPRICE(isin) {
  const baseUrl = 'https://example.com/api/quotes/';
  const response = UrlFetchApp.fetch(baseUrl + encodeURIComponent(isin), {
    muteHttpExceptions: true,
  });
  const payload = JSON.parse(response.getContentText());
  return payload.data.price;
}
```

Use it from a cell:

```text
=EIXPRICE("IE00B3VTMJ91")
```

Invalid or unavailable ISINs surface the API's non-success response in the
sheet instead of returning an arbitrary value.

## Source contract

The verified EIX discovery, download and CSV formats are documented in
[`docs/eix-source-contract.md`](docs/eix-source-contract.md). The source
provides ISINs but no authoritative ticker mapping, so the initial release is
ISIN-only.

## Supabase PostgreSQL and Cloudflare R2

Use Laravel's normal PostgreSQL and S3-compatible filesystem configuration.
See [`docs/deployment.md`](docs/deployment.md) for placeholder-only Supabase
and R2 examples. R2 objects are transient and the credentials require delete
permission.

## Large-file troubleshooting

The importer never loads a complete gzip or CSV source into memory. Ensure the
local temporary volume has enough free space for the compressed source and set
the download timeout and import lock lifetime above the measured import time.
See [`docs/troubleshooting.md`](docs/troubleshooting.md).

## Development

```bash
composer install
composer test
composer format:check
composer analyse
composer audit --locked
```

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md) and
[CHANGELOG.md](CHANGELOG.md) for project policies.

## License

Laravel EIX Pricing is open-source software licensed under the
[MIT License](LICENSE).
