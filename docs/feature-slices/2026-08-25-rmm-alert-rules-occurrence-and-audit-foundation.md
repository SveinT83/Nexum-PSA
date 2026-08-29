# Feature Slice: RMM Alert Occurrence And Audit Foundation

Status: Done on Dev (human review pending)
Date: 2026-08-25
Parent: `docs/rfc/2026-08-25-rmm-alert-rules.md`
Owner: Codex

## Goal

Give Tactical and N-able alerts one normalized lifecycle, immutable activation history, typed MVP
conditions, deterministic rule ordering, and durable execution evidence without creating work yet.

## User-Visible Behavior

Future new and reopened RMM alerts can be evaluated against enabled rules. Routine active refreshes
and resolutions do not rerun routing. Admins can inspect compact execution outcomes while the
durable audit retains the complete immutable evaluation evidence.

## Scope

- Add normalized severity/provider context to AssetAlert.
- Add occurrence, rule, execution, and work-link schema/models.
- Add the explicit provider-neutral observation Action used by both sync jobs.
- Add condition validation/evaluation for title, severity, provider, Asset, Client, and fingerprint.
- Add deterministic ordering, stop behavior, ignore, immutable snapshots, and bounded failures.
- Process only future new/reopened alerts; do not synthesize history.

## Out Of Scope

- Ticket, Task, or Signal side effects.
- Admin CRUD UI.
- Recurrence windows, resolution actions, provider writes, scripts, notifications, or AI.

## Data Touched

`asset_alerts`, `rmm_alert_occurrences`, `rmm_alert_rules`, `rmm_alert_rule_executions`, and
`rmm_alert_work_items`.

## Permissions

No runtime user permission is borrowed. Admin rule management in a later slice uses
`integration.rmm_manage`.

## Tests

- New, refreshed, reopened, resolved, and successful-provider stale-recovery lifecycle.
- Tactical numeric normalization, N-able optional priority/check type, default severity, and safe
  provider-error behavior.
- Immutable combined-AND matching and fail-closed condition/action definitions.
- Ordering, non-match, stop, ignore, action failure, interruption terminalization, UUID lease loss,
  and no replay after execution starts.

## Documentation

Update Asset alert developer/Knowledge documentation with occurrence semantics.

## Done Criteria

- Both providers use the same observation Action.
- One occurrence exists per new/reopened activation.
- Refresh/resolve does not create another occurrence.
- Executions are durable and bounded; an occurrence is never replayed after its first execution
  exists, while a pre-execution structural interruption can recover on a later heartbeat.
- Focused Dev tests pass.

## Verification

On 2026-08-26, the combined RMM rule/provider suite passed 25 tests with 226 assertions. Foundation
migration `2026_08_25_230000_create_rmm_alert_rules_foundation.php` is Ran in Dev batch 5 and the
UUID lease migration `2026_08_26_000000_add_processing_token_to_rmm_alert_occurrences.php` is Ran
in batch 6. Read-back confirmed the columns and an empty Dev cohort: zero rules, occurrences,
executions, work links, AssetAlerts, and active processing tokens. Human review
`HR-2026-08-25-014` remains Pending.
