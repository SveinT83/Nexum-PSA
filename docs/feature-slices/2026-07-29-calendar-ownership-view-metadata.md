# Feature Slice: Calendar Ownership View Metadata

Status: Done
Date: 2026-07-29
Parent: GitHub issue #137 under the approved Calendar ownership rollout #134
Owner: Codex

## Goal

Expose one stable, privacy-safe Calendar ownership contract to views and API consumers without
changing which calendars or events the viewer may access.

## User-Visible Behavior

Calendar rendering and API clients receive owner label, initials/badge, calendar color and type,
ownership group, and whether the calendar is owned by the viewer. Private and confidential events
keep these safe calendar signals while restricted event details remain masked.

## Scope

- Derive ownership only from `calendars.owner_type` and `owner_id`.
- Normalize supported Calendar types and the approved ownership groups.
- Add the ownership contract to Calendar list, range event, and single-event API resources.
- Keep the existing `ownership_badge` overlay key as a compatibility alias.
- Make the single-event API use the same private-detail decision as Calendar overlays.
- Keep visible calendar selection in `CalendarOverlayQuery`.

## Out Of Scope

- Event-level responsibility or ownership.
- Treating creator or participant status as ownership.
- Ownership filters, `Only mine`, or later badge and type UI changes tracked by #138-#141.
- Changing the current Admin/Superuser, calendar-owner, default-visibility, or Calendar Access
  permission semantics.
- Database changes or external provider synchronization behavior.

## Data Touched

No database data or schema is changed. The slice changes Calendar services, API resources,
controller serialization, tests, module documentation, and Knowledge documentation.

## Permissions

Every event is scoped through `CalendarOverlayQuery` or an equivalent visible-calendar check before
ownership metadata is serialized. Existing role, owner, and Calendar Access behavior remains
unchanged. `CalendarVisibility` remains the single private-detail decision for range and single-event
responses.

## Tests

- Owner metadata comes from the calendar even when another user created the event.
- Viewer-owned and other-person ownership groups are distinct.
- Ownerless team calendars have stable fallback metadata.
- Hidden calendars do not appear because metadata was requested.
- Single-event private details and participant data are masked for unauthorized viewers.
- Calendar owners retain permitted private details.

## Documentation

Update the Calendar README, Calendar Knowledge overview, TODO register, and human-review register.
Sync the Calendar Knowledge module after implementation.

## Done Criteria

- [x] Stable ownership metadata is available for every visible overlay event.
- [x] Calendar list and single-event API resources expose the same normalized ownership contract.
- [x] Creator data is not used as ownership.
- [x] Private single-event API responses no longer expose restricted details.
- [x] Existing visible-event scope is unchanged.
- [x] Focused Calendar regression tests pass on Dev.
- [x] Human review is registered as `HR-2026-07-29-003`.
