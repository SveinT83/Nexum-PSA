# Feature Slice: RMM Alert Rules Admin And Operations

Status: Done on Dev (human review pending)
Date: 2026-08-25
Parent: `docs/rfc/2026-08-25-rmm-alert-rules.md`
Owner: Codex

## Goal

Let authorized Admins configure only the implemented RMM conditions/actions and inspect truthful
execution evidence from one shared RMM settings surface.

## User-Visible Behavior

Admins can list, create, edit, enable/disable, order, and soft-delete RMM rules. A typed form explains
the pre-routing boundary and shows recent matched, ignored, completed, and failed executions. Both
Tactical and N-able settings link to the same page.

## Scope

- Add Integration-owned routes, controller, validation, typed definition support, and Bootstrap
  views.
- Protect every route with `integration.rmm_manage`.
- Provide implemented condition fields, ordered action rows, target selectors, flow controls, and
  server-side validation.
- Show compact terminal execution history with rule revision, occurrence, result, targets, and
  bounded error; do not expose retry or full-rerun controls.
- Add shared RMM Knowledge documentation, TODO and human-review tracking.
- Register the every-minute Signal webhook outbox dispatcher used after RMM Signal handoff.
- Run migrations, schema/count read-back, focused verification, formatting, and HTTP/browser smoke
  checks on Dev.

## Out Of Scope

- Advanced arbitrary JSON editing.
- Rule preview against live provider data.
- Retry/full-rerun UI.
- Unimplemented future actions or conditions.
- Production activation or migration.

## Data Touched

RMM rule/audit tables, Signal webhook outbox metadata/indexes, Ticket key sequences, Integration
module routes/views/docs, TODO, and human review.

## Permissions

Existing `integration.rmm_manage`; no new human permission or role synchronization.

## Tests

- Route permission allow/deny coverage.
- CRUD, revision increments, stale revision rejection, active-delete protection, and soft delete.
- Definition validation rejects empty, invalid, and unsupported fields; action references require
  active targets while Asset/Client condition references require an existing record.
- The views expose only implemented conditions/actions, use ID-backed Client selectors, and show a
  compact projection of execution evidence.
- Redirect-safe or authenticated HTTP smoke after cache clear.

## Documentation

Add `rmm-alert-rules.md` Knowledge documentation and a human-review checklist entry.

## Done Criteria

- Both provider settings link to one RMM Rules page.
- Unauthorized users cannot read or mutate rules/executions.
- The UI does not advertise future actions.
- Migrations and focused tests pass on authoritative Dev.
- The `signal.webhook.dispatch` schedule is visible and pending outbox rows are recoverable.
- Human review remains explicitly Pending until Svein confirms the listed checks.

## Verification

On 2026-08-26, Admin behavior was included in the 25-test / 226-assertion focused suite. Dev
registered six RMM Alert Rules routes, Blade view cache compiled and cleared successfully, and an
unauthenticated HTTP request returned the expected `302` login redirect. The latest broad Integration
run passed 198 tests with 1 expected environment-skipped contract and retained one unrelated OpenAI
legacy-completion test failure (`max_tokens` expectation); this is recorded rather than attributed
to RMM Rules. Dev migrations include RMM foundation/lease plus the Signal claim-state, Ticket key
sequence, unique action-key, and action-key NOT NULL additions in batches 5, 6, 7, 8, 9, and 10.
Laravel registers `signal.webhook.dispatch`, but Dev has no verified external `schedule:run` runner
for `/var/Projects/tdPSA`; the only every-minute Laravel systemd timer found targets
`/var/projects/project-society`. Configuring an approved Nexum runner is therefore an operations
gate. Human review `HR-2026-08-25-014` remains Pending.
