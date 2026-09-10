# Deployment configuration

Use application environment variables or the published package config. Never
commit real connection strings or credentials. The package requires a database
and a lock-capable Laravel cache store; object storage is not required.

## PostgreSQL

Use Laravel's standard `pgsql` connection. Managed PostgreSQL services such as
Supabase work through the same configuration. For Supabase, prefer a direct
connection where network support permits or the Session Pooler; do not use the
Transaction Pooler as the default ORM connection.

```dotenv
DB_CONNECTION=pgsql
DB_URL=postgresql://<USER>:<PASSWORD>@<HOST>:5432/<DATABASE>?sslmode=require
EIX_DB_CONNECTION=pgsql
```

Run package migrations after configuring the connection:

```bash
php artisan migrate --force
```

## MySQL and MariaDB

Use Laravel's standard `mysql` connection. Quote upserts switch to
`ON DUPLICATE KEY UPDATE` automatically.

```dotenv
DB_CONNECTION=mysql
DB_HOST=<HOST>
DB_PORT=3306
DB_DATABASE=<DATABASE>
DB_USERNAME=<USER>
DB_PASSWORD=<PASSWORD>
EIX_DB_CONNECTION=mysql
```

## Local temporary storage

Each compressed source is streamed to the system temporary directory, parsed
from that local file, and deleted after success or failure. Size the temporary
volume for one compressed EIX source. No Laravel filesystem disk, S3 adapter or
object-storage credentials are needed.

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
