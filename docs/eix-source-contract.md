# EIX pre-trade source contract

Observed on 2026-09-07 against the public EIX website. The committed
fixtures retain only a small subset of the public response and source rows.

## Discovery

Request:

```text
GET https://european-investor-exchange.com/api/trade-files?tradeFileType=pretrade
```

The response is a JSON array whose entries contain only `fileName`:

```json
{"fileName":"pretrade/2026-09-07/Pretrade.1788759000000.csv.gz"}
```

The observed response contained 183 unique entries in ascending timestamp
order at five-minute intervals. Consumers must not depend on response order;
the newest source is the entry with the greatest numeric timestamp matching
`Pretrade.<unix-milliseconds>.csv.gz`.

## Download

The website downloads a discovered source through:

```text
GET /api/trade-file-contents?key=<URL-encoded fileName>&attachmentFilename=<name>
```

The endpoint responds with `307 Temporary Redirect` to a short-lived signed
S3 URL. The observed URL expired after 300 seconds, and the S3 response
supported byte ranges. Clients should follow the redirect while streaming the
body and must not persist or log the signed URL.

The inspected complete source was valid gzip: 3,063,705 compressed bytes and
11,654,673 uncompressed bytes. This single observation is not a supported size
limit; production code must retain the large-file streaming invariant.

## CSV format

The decompressed source is UTF-8-compatible ASCII text with LF line endings,
a header row, comma delimiters and no BOM. Parse it with an RFC-compatible CSV
reader even though quoted fields were not present in the inspected source.

Exact headers:

```text
Trading day & Trading time UTC
Instrument Identifier
Bid Quantity
Ask Quantity
Bid Price
Ask Price
Price Currency
Price Notation
Status
```

Observed values:

- timestamps use UTC ISO 8601 with millisecond precision;
- every inspected instrument identifier was a valid ISIN;
- quantities and prices are decimal values with up to six fractional digits;
- currency was `EUR` and price notation was `MONE`;
- statuses include `TRAD`, `HALT`, `SUSP`, `QUOT` and `SOLD`;
- suspended rows can contain zero quantities and zero bid/ask prices;
- one-sided books are common: a positive bid with ask `0` (often `SOLD`, also `TRAD`);
- no ticker or venue/MIC field exists in the pre-trade source.

## Ordering and duplicates

The inspected source contained 152,505 rows for 12,412 ISINs. Rows were not
globally ordered by quote timestamp. It contained 128,767 exact duplicate
rows and five `(ISIN, timestamp)` keys with two different quote values.
Conflicting rows were adjacent in the observed file.

The parser must preserve physical row order. Persistence cannot treat
`(ISIN, quoted_at)` as a unique source identity because a later row can amend
a quote without changing its timestamp. A deterministic latest-quote reducer
must compare timestamp first and use later physical row order as the tie
breaker.

## Price semantics

The source has no canonical current or last-price field. Public `price` is the
decimal midpoint:

```text
price = (bid + ask) / 2
```

The calculation must use decimal arithmetic at database precision, not binary
floating point. A suspended `0/0` book therefore has midpoint `0`. A one-sided book with
ask `0` has midpoint `bid / 2`. Status must remain persisted for debugging
and future API policy changes.

## Identifier scope

The pre-trade source exposes only ISIN. The observed post-trade and official
trade-list headers also contain no ticker mapping. The initial package release
therefore supports ISIN lookup only. Ticker lookup is out of scope unless a
future version adopts an authoritative mapping source; no mapping may be
inferred from filenames or instrument identifiers.
