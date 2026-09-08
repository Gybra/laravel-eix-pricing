# Changelog

All notable changes are documented in this file. The project follows
[Semantic Versioning](https://semver.org/) and uses Git tags named `vMAJOR.MINOR.PATCH`.

## [Unreleased]

### Changed

- Import every uncompleted EIX source in a 30-minute lookback window instead of only the newest file.
- Default import schedule is every 30 minutes on weekdays. Weekend import and prune runs are skipped.

### Added

- `eix:prune-quotes` deletes quotes older than two days by `imported_at`, scheduled daily at 01:00.
- Eloquent import and quote models with `casts()`, factories, and bound service interfaces.

## [0.1.0] - 2026-09-08

### Added

- Streaming EIX discovery, download and gzip/CSV normalization.
- Transactional bounded quote upserts and import metadata.
- Manual and scheduled imports with overlap protection.
- Throttled ISIN quote API and Google Apps Script example.

## Release policy

Move completed entries from `Unreleased` into a dated version section, create
the matching Git tag and publish a GitHub release. Breaking public API or
configuration changes require a major version; backward-compatible features
require a minor version; fixes require a patch version.
