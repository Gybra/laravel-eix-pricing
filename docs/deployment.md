# Deployment configuration

Use application environment variables or the published package config. Never
commit real connection strings or storage credentials.

## Supabase PostgreSQL

Laravel uses its standard `pgsql` connection. For a persistent application
backend, use a direct connection when network support permits or the Supabase
Session Pooler. Do not use Transaction Pooler as the default ORM connection.

```dotenv
DB_CONNECTION=pgsql
DB_URL=postgresql://<USER>:<PASSWORD>@<HOST>:5432/<DATABASE>?sslmode=require
EIX_DB_CONNECTION=pgsql
```

Run package migrations after configuring the connection:

```bash
php artisan migrate --force
```

## Cloudflare R2

Install Laravel's S3 Flysystem adapter in the host application:

```bash
composer require league/flysystem-aws-s3-v3
```

Configure the normal Laravel `s3` disk with R2 values:

```dotenv
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<R2_ACCESS_KEY_ID>
AWS_SECRET_ACCESS_KEY=<R2_SECRET_ACCESS_KEY>
AWS_DEFAULT_REGION=auto
AWS_BUCKET=<R2_BUCKET>
AWS_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=false
EIX_STORAGE_DISK=s3
EIX_STORAGE_PREFIX=eix
```

The package uploads each source only while its import runs and deletes it in a
`finally` block after success or failure. The R2 credentials therefore require
read, write and delete access to the configured prefix.

## Scheduler and locks

Run Laravel's scheduler in the host:

```bash
php artisan schedule:work
```

The default cache store can protect one application instance. Multiple
scheduler instances must share a lock-capable cache store. Only then enable:

```dotenv
EIX_SCHEDULE_ON_ONE_SERVER=true
EIX_IMPORT_LOCK_STORE=<SHARED_CACHE_STORE>
```

Redis and a queue worker are optional and are not package dependencies.
