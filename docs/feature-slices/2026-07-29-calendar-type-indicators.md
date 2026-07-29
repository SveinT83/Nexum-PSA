# Feature Slice: Calendar Type Indicators

Status: Done
Date: 2026-07-29
Parent: GitHub issue #139 under the approved Calendar ownership rollout #134
Depends on: GitHub issue #138
Owner: Codex

## Goal

Distinguish shared, global, team, resource, and external/system events in every Tech Calendar view without relying on color or weakening privacy masking.

## User-Visible Behavior

Non-personal events show a compact type badge beside the owner badge in day, week, month, and list views. The visible labels are `SHR`, `TM`, `GLB`, `ABS`, `SHF`, `RES`, `SYS`, `EXT`, or `CAL`, with a full accessible Calendar-type label. Personal events retain the owner badge without a redundant type badge.

## Scope

- Reuse the server-owned `calendar_type` and `calendar_type_label` metadata.
- Render one shared type partial in all four Calendar views.
- Keep owner, type, and color as separate redundant signals.
- Retain safe type context on masked private/confidential busy blocks.
- Group sidebar calendars by `mine`, `people`, `team`, `shared`, `resources`, and `external`.

## Out Of Scope

- Event creator or participant responsibility.
- New Calendar types, schema, access rules, or sync behavior.
- Provider-specific icons or a full visual redesign.

## Data And Permissions

No schema, data, permission, queue, scheduler, or integration changes. The UI consumes metadata only after `CalendarOverlayQuery` scopes visible calendars and `CalendarVisibility` masks private details.

## Tests

Feature coverage asserts representative non-personal type badges, accessible full labels, compact visible labels, and masked private resource events.

## Done Criteria

- [x] Every non-personal type has a stable compact indicator.
- [x] Type remains understandable without color.
- [x] Day, week, month, and list use the shared event identity.
- [x] Private details remain masked.
- [x] Focused Calendar tests pass on Dev.
- [x] Human review is registered as `HR-2026-07-29-005`.
