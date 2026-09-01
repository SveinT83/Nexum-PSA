# RFC: Knowledge Large Article Content Capacity

Status: Approved
Date: 2026-08-25
Owner: Svein / Codex

## Context

BookStack rate-limit coordination for GitHub Issue #196 is verified against the configured Dev
provider. The provider exposes 376 pages, and a clean read-only run fetched 60 real pages in 59.92
seconds with zero failures and no HTTP 429.

A single full pull still cannot complete because three BookStack pages contain 67,542 to 89,095
bytes of HTML. Knowledge stores `articles.body_html` as MySQL/MariaDB `TEXT`, whose 65,535-byte
storage ceiling is too small. The pull stops with SQLSTATE `22001`, driver error `1406` (`data too
long`). `body_markdown` is also `TEXT` and is close to the same limit for the affected pages.

## Goals

- Preserve complete Knowledge article HTML and Markdown without silent truncation.
- Allow the configured BookStack pull to import all provider pages.
- Keep existing article content, ownership, visibility, source identity, and checksums unchanged.
- Retain a safe rollback boundary that refuses to truncate oversized content.

## Non-Goals

- No BookStack provider writes or content changes.
- No change to rate-limit pacing, retry behavior, sync ownership, routes, permissions, or API
  contracts.
- No new editor, attachment handling, content compression, or archival workflow.
- No automatic truncation of provider or local Knowledge content.

## Current Behavior

The original articles migration defines both `body_markdown` and nullable `body_html` as `TEXT`.
BookStack pull stores the complete provider Markdown and HTML in those columns. Pages larger than the
database column capacity cause a query exception, so the final sync summary is never persisted even
though smaller pages import idempotently.

## Proposed Change

Add a forward-only schema migration that changes:

- `articles.body_markdown` from `TEXT` to `MEDIUMTEXT`.
- `articles.body_html` from nullable `TEXT` to nullable `MEDIUMTEXT`.

`MEDIUMTEXT` provides capacity up to 16 MiB, matching the existing project pattern for sizeable HTML
draft content while avoiding the much broader `LONGTEXT` allocation. Application persistence and API
behavior remain unchanged.

## Impact Analysis

- **Knowledge:** the Article storage contract accepts larger Markdown and rendered HTML.
- **Integration:** BookStack pull can persist pages above 64 KiB without truncation.
- **Database:** one schema migration alters two columns in `articles`; MariaDB may lock or rebuild the
  table depending on server/version.
- **Permissions/routes/API/UI:** no changes.
- **Queues/scheduler:** no behavioral changes; BookStack workers should be stopped during the schema
  alteration and restarted afterward.
- **Data safety:** existing content is preserved. No raw provider content is logged or copied into
  tests, documentation, or GitHub.

## Data And Migration Plan

1. Back up the production database before deployment.
2. Stop queue workers and avoid manual/scheduled BookStack pulls during the column alteration.
3. Run the migration changing both body columns to `MEDIUMTEXT` while preserving nullability.
4. Read back the two column types.
5. Clear optimized Laravel caches and restart queue workers.
6. Run one controlled BookStack pull and verify all 376 pages, zero failed/rate-limited records, no
   duplicate source identities, and a healthy integration status.

No backfill is required. The rollback must first check `OCTET_LENGTH` for both columns and refuse to
shrink to `TEXT` if any stored value exceeds 65,535 bytes. This prevents destructive rollback
truncation.

## Testing Plan

- Add migration/schema coverage for both `MEDIUMTEXT` columns where the database driver supports
  type inspection.
- Add a BookStack pull regression using HTML and Markdown payloads larger than 65,535 bytes and
  verify complete persistence without truncation.
- Re-run `BookStackClientTest` and the BookStack-filtered `IntegrationModuleTest` coverage.
- Run migration, focused tests, Pint, and `git diff --check` on authoritative Dev.
- Complete the controlled full provider pull described in the migration plan.

## Documentation Plan

- Update the BookStack Integration Knowledge document with the large-page storage requirement.
- Update `docs/TODO.md` and `HR-2026-08-25-005` with migration and live full-pull evidence.
- Post sanitized verification evidence to GitHub Issue #196.

## Implementation Evidence

- Migration `2026_08_25_210000_expand_knowledge_article_body_capacity` ran on Dev in batch 2.
- Schema read-back confirms non-null `body_markdown` and nullable `body_html` are `MEDIUMTEXT`.
- The >70 KB BookStack regression and focused suites pass: 25 tests / 190 assertions.
- The final 376-page pull completed with 3 created, 373 skipped, zero failed/rate-limited records,
  zero duplicate source identities, and a healthy integration status.

## Open Questions

None.

## Approval

Approved by Svein on 2026-08-25 in conversation after reviewing the `TEXT` and `MEDIUMTEXT` capacity, storage, migration, and rollback differences.
