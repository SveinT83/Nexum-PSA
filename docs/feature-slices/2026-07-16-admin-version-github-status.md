# Feature Slice: Admin Version And GitHub Status

Status: Done
Date: 2026-07-16
Parent: `docs/rfc/2026-07-16-version-and-github-update-status.md`
Owner: Codex

## Goal

Show the installed version in the footer and give administrators a cached, honest comparison with
GitHub releases and the environment's update branch.

## User-Visible Behavior

- The shared footer shows only the installed version.
- The right side of the Admin header shows installed version, release availability, commit distance,
  stale state, and unavailable/unknown states.
- The Admin page renders before the deferred GitHub check completes.

## Scope

- Add a System-owned GitHub release/compare client and cached status query.
- Add a protected deferred status endpoint.
- Move the legacy Admin route/controller/view ownership into the System module without changing its
  URL or route name.
- Add responsive Bootstrap header status and footer contrast correction.

## Out Of Scope

- Automatic code updates or deployment controls.
- GitHub write access.
- A manual refresh button.
- Status on non-Admin pages beyond the footer version.

## Data Touched

- Laravel cache entries containing sanitized GitHub status.
- No database tables or persistent business data.

## Permissions

The existing Admin middleware and `system.view` permission protect the Admin page and status
endpoint. No new permission is required.

## Tests

- Unit tests for semantic version handling.
- Feature tests for releases, commit comparison, caching, stale results, failures, route ownership,
  authorization, deferred rendering, and footer output.
- Focused System tests and authenticated visual smoke checks on Dev.

## Documentation

- Add System Knowledge documentation.
- Update the RFC, TODO, README, and human-review register.

## Done Criteria

- Footer and Admin header match the approved behavior in light/dark desktop and mobile layouts.
- GitHub failures never fail or delay initial Admin-page rendering.
- Commit comparison is accurate for current, behind, ahead, diverged, and unknown states.
- Focused Dev tests pass and human review remains explicitly pending.

## Verification

- Dev System suite passed: 31 tests / 197 assertions.
- Blade compilation, route ownership, authorization, deferred rendering, caching, stale fallback,
  release parsing, and commit comparison passed.
- A live read-only Dev query reported `v0.2.0-beta`, commit `42a08a7`, the current GitHub release,
  and 7 commits behind configured branch `main`.
- An unauthenticated HTTPS smoke check returned the expected redirect to login.
- Automated visual inspection was blocked by the internal Dev certificate, so responsive and theme
  checks remain in human review `HR-2026-07-16-001`.
