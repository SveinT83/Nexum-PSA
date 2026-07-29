# Feature Slice: Calendar Owner Badges And Accessible Color

Status: Done
Date: 2026-07-29
Parent: GitHub issue #138 under the approved Calendar ownership rollout #134
Depends on: GitHub issue #137 and
`docs/feature-slices/2026-07-29-calendar-ownership-view-metadata.md`
Owner: Codex

## Goal

Make calendar ownership recognizable at a glance in every Tech Calendar view without relying on
color alone or exposing masked event details.

## User-Visible Behavior

Every visible event in day, week, month, and list view has a compact badge containing owner initials
or a stable short Calendar-type fallback. A bordered color swatch preserves Calendar color identity,
while the accessible label and tooltip state the full owner and Calendar type.

Long event titles truncate instead of overlapping badge or time content. Month and week keep
readable columns on narrow screens with horizontal scrolling. List Calendar identity uses both its
name and swatch instead of arbitrary background color with uncertain text contrast.

## Scope

- Render one shared owner-badge partial in day, week, month, and list.
- Consume `owner_label`, `owner_badge`, `calendar_color`, and `calendar_type_label` from the approved
  backend metadata contract.
- Keep visible initials/type fallback, color swatch, tooltip, and accessible owner/type name aligned.
- Add layout boundaries for badge, time, and title.
- Keep private and confidential event details masked while retaining safe owner/color/type signals.
- Add focused view assertions covering every Calendar view and private-detail boundaries.

## Out Of Scope

- New Calendar type markers or filter sections tracked by #139.
- `Only mine`, owner-group filtering, or URL filter state tracked by #140 and #141.
- Event-level responsibility, creator-based ownership, or participant-based ownership.
- Changes to Calendar visibility, private-detail permissions, database schema, or provider sync.
- A broad Calendar redesign.

## Data And Permissions

No database data, schema, permissions, queues, schedules, or integrations change. The UI consumes
only server-scoped overlay metadata. `CalendarOverlayQuery` and `CalendarVisibility` remain the
visibility and private-detail authorities.

## Tests

- Each of day, week, month, and list renders the shared owner badge.
- The badge includes owner initials, a calendar-color swatch, and full accessible owner/type text.
- Long-title layout classes are present in each relevant event row.
- List Calendar identity includes both Calendar name and swatch.
- Unauthorized private details are absent from all four rendered views while `Busy` and safe owner
  identity remain visible.

## Documentation

Update the Calendar README, Calendar Knowledge overview, TODO register, public website handoff, and
human-review register. Sync the Calendar Knowledge module after implementation.

## Done Criteria

- [x] Ownership is readable without opening an event in all four views.
- [x] Calendar color is retained but never used as the only owner identity.
- [x] Badge text and full accessible owner/type label come from the backend ownership contract.
- [x] Long titles cannot overlap badge/time content.
- [x] Month and week remain readable at narrow viewport widths.
- [x] Private/confidential event details remain masked.
- [x] Focused Calendar regression tests pass on Dev.
- [x] Human review is registered as `HR-2026-07-29-004`.
