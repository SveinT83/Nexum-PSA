# Feature Slice: Calendar Ownership Filters

Status: Done
Date: 2026-07-29
Parent: GitHub issue #140 under the approved Calendar ownership rollout #134
Depends on: GitHub issue #137
Owner: Codex

## Goal

Let technicians reduce Calendar overlays by real calendar ownership while preserving existing Calendar, date, search, sort, and permission behavior.

## User-Visible Behavior

The sidebar offers `Only mine` plus backed ownership groups for People, Team, Shared/global, Resources, and External/system. Counts and options come only from currently visible calendars. Filters remain active across view switching, date navigation, search, sorting, availability lookup, and dense-month drill-down. A clear action and explicit empty state are shown when relevant.

`Only mine` means calendars whose `owner_type` and `owner_id` identify the signed-in user. Creating or participating in an event does not make a shared/team event mine.

## Scope

- Filter server-side by the ownership metadata contract.
- Intersect ownership groups with explicitly selected calendar IDs.
- Let `Only mine` take priority over group selections.
- Never broaden the visible Calendar set.
- Preserve ownership state in Calendar navigation and forms.

## Out Of Scope

- A creator/participant-based `My events` filter.
- Event-level responsibility or assignment.
- Saved filter presets or user preference persistence.
- Permission, schema, API, or sync changes.

## Data And Permissions

`CalendarOverlayQuery::visibleCalendars()` remains the visibility boundary. Unknown groups are ignored, and a valid filter with no visible calendar matches returns an empty event collection rather than falling back to every calendar.

## Tests

Feature coverage verifies owner-versus-creator semantics, group combinations, intersection with calendar IDs, hidden-calendar denial, backed controls, and explicit empty state.

## Done Criteria

- [x] `Only mine` uses calendar ownership only.
- [x] Ownership groups are derived from visible server data.
- [x] Existing Calendar/date/search/sort state remains compatible.
- [x] Empty and clear states are explicit.
- [x] Hidden calendars cannot be introduced through filter parameters.
- [x] Focused Calendar tests pass on Dev.
- [x] Human review is registered as `HR-2026-07-29-006`.
