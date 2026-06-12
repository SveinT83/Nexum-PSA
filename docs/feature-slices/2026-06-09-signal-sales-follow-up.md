# Feature Slice: Signal Sales Follow-Up

Status: Done
Date: 2026-06-09
Parent: `docs/rfc/2026-06-09-signal-domain-active-automation.md`
Owner: Codex

## Goal

Make Signal useful in daily client/contact work by exposing related signals and allowing rules to
create sales follow-up.

## User-Visible Behavior

Client profiles have a Signals tab. Contact profiles show a Signals panel. Signal rules can create
or update a Sales opportunity with an unread internal follow-up activity.

## Scope

- Related Signal query for clients and contacts.
- Reusable related Signal Blade panel.
- `sales_follow_up` Signal action.
- Signal route ordering fix for rule routes before signal detail routes.

## Out Of Scope

- AI classification.
- Automatic phone lists.
- Ticket creation from Signal.
- Marketing campaign scoring dashboards.

## Data Touched

- Reads `signals`.
- Writes `sales_opportunities` and `sales_activities` when a matching Signal rule uses
  `sales_follow_up`.

## Permissions

No new permission. Client/contact visibility uses existing client/contact permissions. Signal rule
management still uses `signal.rule.manage`.

## Tests

- Signal action creates a Sales opportunity/activity and is idempotent per signal.
- Client show renders related Signal tab/content.
- Contact show renders related Signal panel/content.

## Documentation

Updated Signal README, Knowledge docs, and TODO.

## Done Criteria

- Related Signal UI renders on Client and Contact show pages.
- `sales_follow_up` rule action works without duplicate activities.
- Targeted module tests pass.
