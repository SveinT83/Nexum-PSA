# Feature Slice: Ticket Workflow Automatic Migration Placement

Status: Done
Date: 2026-07-18
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Nexum PSA product owner and Codex

## Goal

Remove ordinary manual target-step mapping when active Tickets are migrated to a newer published
version of the same workflow. The target workflow's step requirements must classify each Ticket
independently.

## User-Visible Behavior

The migration preview shows an automatically determined target step and a plain-language reason for
every active Ticket. Administrators select which Tickets to migrate, but do not choose one target
step for every Ticket that happened to share an old step.

Automatic placement uses this safe order:

1. Keep the same stable step key when that target step still exists and its entry requirements pass.
2. Otherwise choose the furthest non-terminal step with explicit entry requirements that all pass.
3. If no explicit requirement identifies a step, preserve a unique matching reporting status.
4. Otherwise use the initial non-terminal step when its entry requirements pass.
5. Block only the individual Ticket when no safe non-terminal step can be identified.

The apply operation re-evaluates the Ticket against the target version inside the transaction. It
does not trust stale preview values or a client-supplied state mapping.

## Scope

- Requirement-aware per-Ticket target resolution in the migration service.
- Target-version assignment-policy context while evaluating target-step requirements.
- Browser preview without the manual **Target step** selector.
- API preview fields that explain automatic placement.
- API and browser migration requests that no longer require `state_mapping`.
- Backward-compatible acceptance, but no trust, of legacy API `state_mapping` input.
- Migration history that records the automatic placement strategy and requirement snapshot.

## Out Of Scope

- Automatic migration immediately after publishing.
- Moving Closed Tickets.
- Migrating a Ticket to a different workflow; escalation owns that operation.
- Bypassing failed requirements through a normal migration request.
- Treating transition action triggers as state-classification rules.

## Data Touched

No schema change is required. Successful migrations continue to update the Ticket's pinned workflow
version, workflow state, reporting status, assignment, history, and audit event.

## Permissions

`ticket.workflow_migrate` remains required to apply a migration. Preview remains read-only. The
automatic resolver does not bypass Ticket action, fact-provider, owner-eligibility, or workflow
version validation.

## Tests

- Same stable step key is retained only when its target-step requirements pass.
- A renamed/restructured workflow places Tickets independently from their existing facts.
- The furthest passing explicit target requirement wins over an empty initial step.
- No matching non-terminal state blocks only the affected Ticket.
- Browser and API migration requests work without `state_mapping`.
- Legacy client mapping cannot force a Ticket into a step whose requirements do not classify it.
- Apply re-evaluates placement and writes the chosen strategy and requirement snapshot to history.

## Done Criteria

- [x] Manual target-step mapping is removed from the normal migration form.
- [x] Preview explains automatic placement per Ticket.
- [x] Apply uses the same fresh resolver as preview.
- [x] Browser and API behavior are equivalent.
- [x] Focused Ticket/Workflow tests pass on Dev.
- [x] Ticket Workflow Knowledge and human review are updated.
