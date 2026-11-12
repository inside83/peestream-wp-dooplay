# Changelog

All notable changes to the PeeStream WordPress & DooPlay integration are recorded here. Public-facing release documentation is maintained in English.

## 1.0.4-beta.1 — Pending public beta release

**Status:** Beta; use in staging environments only. **Live WordPress/DooPlay E2E: NOT RUN.**

### Changed
- Improved case-sensitive video URL deduplication using SHA-256 URL identities and binary comparisons.
- Added fail-safe handling of duplicate migration queue entries during database schema upgrades.
- Strengthened webhook transaction handling and worker lease/heartbeat coordination.
- Improved migration progress reporting and rollback safeguards.

### Verification
- The repository contains a test log reporting **174 passing WordPress mock/integration harness checks**.
- Separate standalone SQLite scenarios are **simulations, not live CMS E2E tests**.
- Actual installation, activation, migration, and rollback on a WordPress/DooPlay staging instance remain **unverified**.
- These results are not a guarantee of compatibility or production readiness.

## Earlier releases

For historical releases and the exact packaged source, see [GitHub Releases](https://github.com/PeeStream/peestream-wp-dooplay/releases).
