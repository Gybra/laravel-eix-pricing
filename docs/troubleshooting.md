# Troubleshooting

## Import runs out of local disk space

The compressed source is streamed to the system temporary directory before it
is parsed. Increase the temporary volume rather than loading the response into
memory. Failed and completed attempts remove both local and configured-storage
copies automatically.

## Download times out

Measure a real download from the deployment region, then increase
`EIX_HTTP_DOWNLOAD_TIMEOUT`. Keep `EIX_HTTP_CONNECT_TIMEOUT` short enough to
fail unreachable endpoints promptly.

## Import lock expires too early

Set `EIX_IMPORT_LOCK_SECONDS` above the longest measured import duration. Set
`EIX_SCHEDULE_OVERLAP_MINUTES` above the same duration so Laravel's scheduler
does not start a duplicate process.

## Multiple servers import concurrently

Point `EIX_IMPORT_LOCK_STORE` at a cache store shared by every server. Enable
`EIX_SCHEDULE_ON_ONE_SERVER` only with that shared store. The package does not
require Redis; any Laravel cache store implementing atomic locks is suitable.

## R2 source object remains after failure

Confirm the configured R2 credentials allow object deletion for
`EIX_STORAGE_PREFIX`. Cleanup errors are surfaced rather than silently ignored.
Delete an orphan manually after correcting permissions.

## Database import is slow

Keep `EIX_IMPORT_BATCH_SIZE` at its default until measurements justify a
change. The default uses 9,000 bound parameters per statement, below
PostgreSQL's 65,535 limit. Ensure the package connection points to PostgreSQL
and that migrations have created the ISIN and import indexes.

## API returns 404

Run `php artisan eix:import` and inspect `eix_imports` for the latest status.
A 404 means no current quote is stored for that valid ISIN. A malformed or
checksum-invalid ISIN returns 422 instead.
