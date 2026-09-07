# IMPLEMENTATION_PLAN.md

## Objective

Produce (1) a public Laravel Composer/Packagist package containing
complete EIX ingestion + quote API and (2) a thin Dockerized Laravel
host supplying Supabase PostgreSQL and Cloudflare R2 configuration.

Success: install/configure package, periodically ingest EIX pre-trade
data, call one throttled GET endpoint with an ISIN, receive the latest
persisted quote.

## Repository topology and delivery order

- `Gybra/laravel-eix-pricing` contains the independently installable
  Composer package, package documentation, tests and release workflow.
- `Gybra/laravel-eix-pricing-host` contains only the Dockerized Laravel
  reference host and installs the package through Composer.
- Complete and review the package before implementing the host.
- Publish the package repository to Packagist; the host is not a
  Packagist package.

## Phase 0 --- Verify EIX contract (blocking)

1.  Query
    `https://european-investor-exchange.com/api/trade-files?tradeFileType=pretrade`.
2.  Save sanitized discovery fixture.
3.  Determine REAL mechanism resolving `fileName` to `.csv.gz`; do not
    guess.
4.  Inspect a bounded representative real sample.
5.  Verify compression, encoding, delimiter/quoting, headers,
    instrument identifiers, bid/ask/current/last, timestamps, MIC/venue,
    ordering and duplicate-instrument semantics.
6.  Create tiny real-format fixtures.
7.  Define `price`: prefer canonical EIX field; derive midpoint only if
    necessary and explicitly documented/tested.

Gate: no final parser/schema before this passes.

## Phase 1 --- Bootstrap public package

Create Composer metadata, `src`, config, migrations, routes, tests,
README, LICENSE, CONTRIBUTING, CI, Pint, Larastan and Pest/Testbench.
PHP 8.4+, supported Laravel versions with Laravel 13 reference, Composer
auto-discovery.

Create `.github/workflows/ci.yml` for pull requests and pushes to
`main`. On PHP 8.4 it must validate Composer metadata, install locked
or resolved dependencies, run Pest, run Pint in check mode and run
Larastan. Pest is a required merge gate. Expand the dependency matrix
only when additional supported Laravel versions are declared.

Acceptance: clean install, Testbench boot, passing Pest suite, green
baseline CI, no host/R2/Supabase coupling.

## Phase 2 --- Config + provider

Config: discovery URL, HTTP timeouts/retries, DB connection, transient
storage disk/prefix, batch size, schedule enabled/cadence, routes
enabled/prefix, throttle.

Provider: merge/publish config, load migrations/routes, register
commands/schedule, bind contracts. No credentials/vendor SDKs hardcoded.

## Phase 3 --- Persistence

After Phase 0, create minimal schema. Quotes need an internal id, ISIN,
bid, ask, derived midpoint price, status, quoted_at, imported_at, source
identity and physical row order for deterministic ties. Use PostgreSQL
numeric precision supporting the observed six fractional digits, not
blind floats. Index ISIN and the verified upsert identity.

Add small import/source metadata only as needed for
idempotency/observability: source identity, start/finish, status,
counts, concise failure metadata. Never store giant payloads.

## Phase 4 --- EIX discovery client

Adapter calls discovery endpoint, validates response, maps typed DTOs,
chooses newest eligible source deterministically, handles
empty/malformed/non-2xx. Unit-test ordering, empty/malformed,
timeout/retry.

## Phase 5 --- Bounded download + transient storage

Stream HTTP response to a local temporary resource, verify the transfer
and persist it through the configured Laravel filesystem while the
import runs. Reference host uses R2; package only knows disk/prefix.
Delete both the configured-storage object and local temporary file in
`finally` after success or failure. Keep only import metadata for
diagnostics. Tests use fake HTTP/storage and cover both cleanup paths.

Acceptance: no complete-source string allocation and no source artifact
remains after an import attempt.

## Phase 6 --- Streaming gzip/CSV parser

From verified fixture: streaming gzip read, correct CSV parser, header
mapping, typed normalization, defined malformed-row behavior,
generator/iterator output. No Eloquent coupling. Test quoting, malformed
rows, numeric/timestamps and generated multi-batch data.

## Phase 7 --- Bulk persistence + snapshot decision

Consume records in configurable batches with a conditional bulk upsert
on the configured connection. Store one current row per ISIN and replace
it only when `(quoted_at, source_timestamp, source_row)` is greater. No
per-row Eloquent saves.

Each source row is a complete independent quote, so generation/staging
is unnecessary. Run all batches for one source in a single transaction;
a parsing or database failure restores the previous current quotes. The
default batch of 1,000 uses 9,000 bound parameters, below PostgreSQL's
65,535 parameter limit. See `docs/persistence.md`.

## Phase 8 --- Import orchestrator

Workflow: acquire lock → discover newest → stop if already completed →
start metadata → download/stage → stream parse → batch persist → mark
success → source cleanup → release lock.

On exception: mark failed when possible, report/log, delete configured
and local source artifacts, release lock, then rethrow appropriately.
Test duplicate source, mid-import failure, retry, lock and cleanup.

## Phase 9 --- Command + scheduler

Add manual command such as `eix:import`, delegating to orchestrator. Add
configurable automatic schedule, default 10--15 minutes,
`withoutOverlapping()`, `onOneServer()` only where shared locking is
suitable. If queued, define timeout/tries/backoff and document
`retry_after > timeout`. No Redis/Horizon requirement.

## Phase 10 --- Quote lookup

Lookup service accepts a normalized, checksum-valid ISIN; invalid input
is rejected and a missing quote returns 404. Query only
completed/current data according to Phase 7. Ticker lookup is outside
v1 because the verified EIX feeds contain no authoritative mapping.

## Phase 11 --- Public API

Expose `GET /api/quotes/{isin}`. No auth; Laravel throttle. Thin
controller → lookup service → API Resource.

Target response:

``` json
{"data":{"isin":"IE00B3VTMJ91","price":116.3225,"bid":116.309,"ask":116.336,"quoted_at":"2026-09-07T09:41:59.000Z"}}
```

Feature-test ISIN 200, exact contract, 404, invalid behavior and
throttle.

## Phase 12 --- Google Apps Script example

Document a minimal custom function:

``` javascript
function EIXPRICE(isin) {
  const baseUrl = 'https://example.com/api/quotes/';
  const response = UrlFetchApp.fetch(baseUrl + encodeURIComponent(isin));
  const payload = JSON.parse(response.getContentText());
  return payload.data.price;
}
```

Show `=EIXPRICE("IE00B3VTMJ91")`.

## Phase 13 --- Open-source docs + CI

README covers purpose, architecture summary, requirements, install,
config, migrations, transient storage, scheduling, manual import, API,
Apps Script, Supabase/R2 examples, troubleshooting and memory constraints.
Add LICENSE, CONTRIBUTING and release/changelog policy. Keep the Phase
1 CI running Pest, Pint and Larastan; add dependency/security checks
only where they provide a practical signal. Never commit real giant EIX
data or secrets.

## Phase 14 --- Package verification and review before v0.1.0

-   full Pest green;
-   Pint clean;
-   Larastan clean at chosen level;
-   package installs in clean Testbench;
-   migrations work on PostgreSQL;
-   R2 configuration smoke-tested without committed credentials;
-   real EIX smoke test performed manually;
-   memory path reviewed: no full-file allocation;
-   duplicate/overlap/failure paths verified;
-   API verified from Google Apps Script;
-   docs match actual commands/config;
-   no secrets/source files staged;
-   independent full-package review completed, with every actionable
    finding resolved through the normal issue and pull request cycle.

Gate: do not begin the host until this phase passes.

## Phase 15 --- Thin Dockerized host

In `Gybra/laravel-eix-pricing-host`, create a minimal Laravel host that
installs the released package with no duplicated business logic. Docker
provides app + scheduler and, only if chosen, queue worker.

Configure Supabase PostgreSQL through normal Laravel pgsql/`DB_URL` with
SSL. For a persistent Laravel backend prefer direct connection where
network support permits or Supabase Session Pooler; do not use
Transaction Pooler as the default ORM connection. Configure R2 as
Laravel S3-compatible disk. Configure package
DB/storage/schedule/routes.

Acceptance: clone → env → Docker startup → migrations → manual
import/API path.

## Phase 16 --- Final integration verification

-   host CI green;
-   Docker host starts cleanly;
-   package installs from its public Composer distribution;
-   migrations and the manual import/API path work through the host;
-   host documentation matches its actual environment and commands;
-   no credentials, source datasets or local artifacts are staged.

## Execution workflow

Work phase by phase and do not implement work blocked by Phase 0
assumptions. Every phase uses this cycle:

1.  Open one GitHub issue with explicit acceptance criteria.
2.  Create a dedicated branch from current `main`.
3.  Implement the smallest coherent change with tests and documentation.
4.  Run focused tests plus formatting and static analysis for touched
    code.
5.  Commit using Conventional Commits and open a pull request that
    closes the issue.
6.  Wait for all required CI checks, including Pest, to pass.
7.  Merge immediately when CI is green, update local `main`, then begin
    the next phase without an additional approval gate.

Split a phase only when its changes cannot remain a reviewable,
independently passing pull request. Keep progress in GitHub issues and
pull requests. Public text must contain only technical project content.
After all package phases pass, perform the Phase 14 review once across
the complete package; process findings through the same cycle before
starting the host repository.

## Decisions intentionally deferred until source inspection

The download mechanism, CSV columns, identifier scope and midpoint
price semantics are verified in `docs/eix-source-contract.md`. Do not
invent the remaining snapshot/generation requirement or optimal batch
size; measure and verify them first.
