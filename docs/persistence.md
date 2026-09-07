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

The default batch contains 1,000 records. Each row uses nine bound parameters,
so a batch uses 9,000 parameters, safely below PostgreSQL's 65,535 parameter
limit. The batch size remains configurable for deployment measurement.
