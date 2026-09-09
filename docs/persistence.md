# Quote persistence

The package stores one current quote per ISIN. It does not retain historical
source rows.

## Deterministic ordering

Input rows are not timestamp ordered, and one timestamp can contain corrected
values. A candidate replaces the stored quote only when this tuple is greater:

```text
(quoted_at, source_timestamp, source_row)
```

`source_timestamp` is parsed from the discovered filename and `source_row` is
the physical CSV row number. This preserves the newest quote while allowing a
later row in one source to correct an equal timestamp.

## Consistency

Each source row is a complete independent quote, so a generation or snapshot
table is unnecessary. All batches for one writer call run in one transaction
on the configured package connection. A parsing or database failure therefore
restores the previous current quotes.

Imports remain separately identifiable through `eix_imports`; completion
metadata is finalized by the import workflow on the same connection.

## Batch size

The default batch contains 2,500 records. Each row uses nine bound parameters,
so one statement uses 22,500 parameters. The evaluated sizes have these
tradeoffs:

- 1,000: 9,000 parameters and the most database round trips.
- 2,500: 22,500 parameters, fewer round trips, and a conservative portable
  query size.
- 5,000: 45,000 parameters; below PostgreSQL's 65,535 limit, but more exposed
  to driver, packet/query-size and SQLite parameter limits.

The default therefore increases to 2,500 rather than 5,000. Keep
`EIX_IMPORT_BATCH_SIZE` configurable and measure against the deployment's real
database and network before increasing it.

Upserts use `ON CONFLICT` on PostgreSQL and SQLite, and `ON DUPLICATE KEY UPDATE`
on MySQL and MariaDB. The stored quote still changes only when the incoming
`(quoted_at, source_timestamp, source_row)` tuple is greater.

## Duplicate ISINs

The writer retains only the newest row for each ISIN inside a batch. A duplicate
that appears in a later batch is conditionally upserted again. Preventing all
such work would require retaining every ISIN for the complete source, making
memory usage grow with source cardinality. The bounded per-batch behavior is
intentional; database ordering guards keep cross-batch results correct.

## Import metrics

Structured info logs report compressed source size, download duration, parsed
and submitted row counts, database batch count, measured statement duration,
parsing/import duration and total import duration. Database write duration
covers SQL statement execution only, so it remains distinct from parsing and
normalization time.
