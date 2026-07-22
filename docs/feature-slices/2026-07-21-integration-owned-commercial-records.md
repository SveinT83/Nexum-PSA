# Feature Slice: Integration-Owned Commercial Records

Status: Done
Date: 2026-07-21
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
ADR: `docs/adr/2026-07-21-integration-owned-commercial-record-lifecycle.md`
Owner: Codex

## Goal

Make CloudFactory-created Services and Costs ordinary, durable Nexum records with explicit active
Integration ownership and a safe editable lifecycle after disconnect.

## User-Visible Behavior

An active CloudFactory-owned Service and Cost are clearly marked, link to CloudFactory settings, and
cannot be manually edited or deleted. After CloudFactory is disabled or removed, the same records and
their normal relation remain in Nexum and can be edited or mapped to a future Integration.

## Scope

Generic Commercial ownership fields, model relationships, active ownership checks, CloudFactory
assignment, Service/Cost UI markers and links, server-side edit/delete guards, disconnect behavior,
migration backfill, tests, Knowledge, and human review.

## Out Of Scope

The Pax8 Integration itself, automatic provider-to-provider migration, provider credential transfer,
and deletion of historical CloudFactory staging data outside the existing Integration lifecycle.

## Data Touched

`services`, `costs`, their existing `cost_relations`, Integration relationships, Commercial views and
controllers, and CloudFactory synchronization.

## Permissions

Existing Commercial and Integration permissions remain unchanged. Active ownership adds a business
rule beneath those permissions.

## Tests

Ownership assignment, ordinary Cost relation, active locks, inactive release, retained records,
source links, CloudFactory refresh, migration backfill, and affected Commercial regressions.

## Documentation

CloudFactory RFC, ownership ADR, Commercial and Integration Knowledge, TODO, and
`HR-2026-07-20-001`.

## Done Criteria

- [x] CloudFactory assigns both Service and Cost to the source Integration.
- [x] The Cost is linked through the ordinary Service-Cost relation.
- [x] Active owned rows show provider, managed state, and an Integration link.
- [x] Active owned rows cannot be edited or deleted through direct requests.
- [x] Disconnect preserves the rows and relation and makes them editable.
- [x] The schema can support a later provider taking ownership.
- [x] Relevant Dev tests and documentation pass.

## Verification

Migration `2026_07_21_200000_link_commercial_records_to_integrations` completed on Dev. The focused
Commercial and CloudFactory run passed 59 tests / 603 assertions, and the complete application suite
passed 851 tests / 6,461 assertions. Commercial and Integration Knowledge sync processed two
chapters and eleven articles without skips, and the queued BookStack push completed successfully.
Human review remains Pending in `HR-2026-07-20-001`.
