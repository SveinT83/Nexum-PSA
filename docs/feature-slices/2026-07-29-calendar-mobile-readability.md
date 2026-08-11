# Feature Slice: Calendar Mobile Readability

Status: Done
Date: 2026-07-29
Parent: GitHub issue #141 under the approved Calendar ownership rollout #134
Depends on: GitHub issues #138, #139, and #140
Owner: Codex

## Goal

Keep the complete ownership/type/filter rollout readable and operable in desktop and narrow mobile Calendar views.

## User-Visible Behavior

Month and week use a keyboard-focusable, touch-scrollable region with stable column width. Event identity, time, and title keep independent layout boundaries. Month shows at most five event rows per day and then a `+N more` link to the filtered day view. Clicking that link no longer triggers the surrounding day-cell create action.

## Scope

- Keep month/week columns at a readable minimum width with horizontal navigation.
- Retain compact touch targets and clear keyboard focus.
- Cap dense month cells and preserve active filter/query state in day drill-down.
- Prevent nested interactive controls from triggering day-cell creation.
- Add regression coverage for dense-month behavior and responsive layout contracts.

## Out Of Scope

- A new responsive Calendar engine or JavaScript framework.
- Drag-and-drop, swipe gestures, or virtualized event lists.
- Changes to event content, privacy, permissions, or ownership semantics.

## Data And Permissions

No data, schema, permission, queue, scheduler, integration, or build changes.

## Tests

Feature coverage verifies the five-event month limit, `+N more`, hidden sixth/seventh month buttons, preserved filters, keyboard-focusable scroll region, and minimum grid width. Manual browser review remains required because the in-app browser does not trust the Dev self-signed certificate.

## Done Criteria

- [x] Month/week retain readable columns at narrow widths.
- [x] Dense month cells expose an accessible filtered drill-down.
- [x] Nested controls do not trigger event creation.
- [x] Automated responsive-contract checks pass on Dev.
- [x] Human browser review is registered as `HR-2026-07-29-007`.
