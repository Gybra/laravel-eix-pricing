# IMPLEMENTATION_PLAN.md

## Objective

Produce (1) a public Laravel Composer/Packagist package containing
complete EIX ingestion + quote API and (2) a thin Dockerized Laravel
host supplying Supabase PostgreSQL and Cloudflare R2 configuration.

Success: install/configure package, periodically ingest EIX pre-trade
data, call one throttled GET endpoint with ISIN/ticker, receive latest
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
    ISIN/ticker, bid/ask/current/last, timestamps, MIC/venue, ordering
    and duplicate-instrument semantics.
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

Config: discovery URL, HTTP timeouts/retries, DB connection, storage
disk/prefix, retention, batch size, schedule enabled/cadence, routes
enabled/prefix, throttle.

Provider: merge/publish config, load migrations/routes, register
commands/schedule, bind contracts. No credentials/vendor SDKs hardcoded.

## Phase 3 --- Persistence

After Phase 0, create minimal schema. Quotes likely need internal id,
ISIN, ticker, optional MIC/venue, bid, ask, price if defined, quoted_at,
imported_at and source identity. Use PostgreSQL numeric precision
appropriate to observed data, not blind floats. Index ISIN/ticker and
verified upsert identity.

Add small import/source metadata only as needed for
idempotency/observability: source identity, start/finish, status,
counts, concise failure metadata. Never store giant payloads.

## Phase 4 --- EIX discovery client

Adapter calls discovery endpoint, validates response, maps typed DTOs,
chooses newest eligible source deterministically, handles
empty/malformed/non-2xx. Unit-test ordering, empty/malformed,
timeout/retry.

## Phase 5 --- Bounded download + R2 archival

Stream HTTP response to local temporary resource, verify transfer,
persist through configured Laravel filesystem, keep/reopen safe local
staging for parser, cleanup in `finally`. Reference host uses R2;
package only knows disk/prefix. Implement configurable source retention.
Tests use fake HTTP/storage.

Acceptance: no complete-source string allocation.

## Phase 6 --- Streaming gzip/CSV parser

From verified fixture: streaming gzip read, correct CSV parser, header
mapping, typed normalization, defined malformed-row behavior,
generator/iterator output. No Eloquent coupling. Test quoting, malformed
rows, numeric/timestamps and generated multi-batch data.

## Phase 7 --- Bulk persistence + snapshot decision

Consume records in configurable batches with PostgreSQL-friendly bulk
upsert, correct configured connection and deterministic conflict key. No
per-row Eloquent saves.

Decide snapshot consistency from Phase-0 facts. If partial imports must
never be visible, use generation/staging: write generation, mark
complete at end, query latest completed generation, clean obsolete
generations. If independent latest-quote upserts are safe, document why
staging is unnecessary. Do not guess.

## Phase 8 --- Import orchestrator

Workflow: acquire lock → discover newest → stop if already completed →
start metadata → download/stage/archive → stream parse → batch persist →
mark success → retention → temp cleanup → release lock.

On exception: mark failed when possible, report/log, cleanup, release
lock, rethrow appropriately. Test duplicate source, mid-import failure,
retry, lock and cleanup.

## Phase 9 --- Command + scheduler

Add manual command such as `eix:import`, delegating to orchestrator. Add
configurable automatic schedule, default 10--15 minutes,
`withoutOverlapping()`, `onOneServer()` only where shared locking is
suitable. If queued, define timeout/tries/backoff and document
`retry_after > timeout`. No Redis/Horizon requirement.

## Phase 10 --- Quote lookup

Identifier classifier + lookup service: normalized valid ISIN or
validated normalized ticker; missing → 404; ambiguous ticker → explicit
deterministic error. Query only completed/current data according to
Phase 7.

## Phase 11 --- Public API

Expose `GET /api/quotes/{identifier}`. No auth; Laravel throttle. Thin
controller → lookup service → API Resource.

Target response:

``` json
{"data":{"isin":"IE00B3VTMJ91","ticker":"CSBGE3","price":116.3225,"bid":116.309,"ask":116.336,"quoted_at":"2026-09-07T09:41:59.000Z"}}
```

Finalize fields/price only after Phase 0. Feature-test ISIN/ticker 200,
exact contract, 404, ambiguity/invalid behavior and throttle.

## Phase 12 --- Google Apps Script example

Document a minimal custom function:

``` javascript
function EIXPRICE(identifier) {
  const baseUrl = 'https://example.com/api/quotes/';
  const response = UrlFetchApp.fetch(baseUrl + encodeURIComponent(identifier));
  const payload = JSON.parse(response.getContentText());
  return payload.data.price;
}
```

Show `=EIXPRICE("IE00B3VTMJ91")` and ticker equivalent.

## Phase 13 --- Open-source docs + CI

README covers purpose, architecture summary, requirements, install,
config, migrations, storage, scheduling, manual import, API, Apps
Script, Supabase/R2 examples, troubleshooting and memory constraints.
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

Do NOT invent: actual EIX file download mechanism, exact CSV columns,
canonical `price`, instrument uniqueness when ticker duplicates exist,
snapshot/generation requirement, or optimal batch size. Measure/verify
first.
