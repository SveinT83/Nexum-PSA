# RFC: Operational Supplier Order Automation Setup

Status: Approved
Date: 2026-08-11
Owner: Svein / Codex
Amended by: `docs/rfc/2026-08-11-automatic-ai-supplier-profile-bootstrap.md` for verified
first-supplier profile bootstrap and the AI-enabled server preset.

## Context

The first simplified Storage policy removed manual actor and workload setup, but kept AI learning,
shadow samples, provider-outage behavior, confidence thresholds, timeout, output tokens, cost, and
consensus in a collapsed Advanced section. Human review showed that this still presents Integration
implementation concepts as Storage decisions. It can also leave consensus required without a second
workload and make an otherwise valid fallback impossible to save.

Supplier Order Automation extracts editable order data from email. Deterministic supplier profiles
run first, AI is optional fallback, Nexum performs every validation and write, and only explicit
Receiving changes stock. The ordinary administrator should therefore configure business behavior,
not internal execution mechanics.

## Goals

- Present order handling with labels that explain what each mode actually does.
- Derive the outcome from order handling instead of exposing a second overlapping outcome choice.
- Keep warehouse, unknown-supplier, unknown-Item, AI-use, Storage-agent, business-limit, and
  notification choices visible.
- Remove learning, consensus, workload, provider, confidence, timeout, token, cost, retry,
  circuit-breaker, retention, and JSON controls from the ordinary Storage form.
- Apply one safe, complete technical preset on the server and ignore forged browser values.
- Keep the selected Storage agent responsible for provider/model choice.

## Non-Goals

- Delete historical policy columns, immutable revisions, or backend support used by pinned imports.
- Change deterministic extraction, AI payloads, privacy governance, source trust, identity,
  arithmetic, Purchase Order, Item, or Receiving gates.
- Enable model tools, direct model writes, automatic goods receipt, or supplier order submission.
- Change the generic Integration AI administration screens.
- Roll this configuration into production.

## Current Behavior

The page exposes an Advanced AI panel and operational retry/retention/JSON fields. Runtime mode and
default outcome overlap. A browser submission can request learning or consensus, including a
consensus requirement that depends on a separately governed workload.

## Proposed Change

Rename Runtime mode to Order handling and use explanatory options for Off, test-only, review,
automatic profile registration, and automatic profile-or-AI registration. Review and non-writing
modes derive `needs_attention`; automatic modes derive `register_ordered`.

The normal form contains AI use and one Storage agent. The HTTP request replaces all technical AI
and operational inputs with a server-owned preset: profile learning is `auto_activate` with one
verified bootstrap sample when AI is enabled and is off when AI is off; consensus remains off;
provider failure goes to attention; confidence, timeout, output, retry, circuit-breaker, and
retention values are bounded; browser cost and advanced JSON rules are disabled. Direct backend
policy actions retain the historical schema and validation contract.

## Impact Analysis

- **Storage UI:** smaller Bootstrap form and plain-language copy.
- **Storage request:** browser values for hidden technical fields are ignored and replaced.
- **Storage controller/query:** no consensus workload list or technical workload language is needed.
- **Storage runtime:** no extraction, finalization, queue, scheduler, receipt, or stock behavior
  changes beyond the values in a newly saved policy revision.
- **Integration/User Management:** managed workload and system actor remain internal and unchanged.
- **Permissions/routes/API:** unchanged.

The main risks are silently preserving an invalid consensus value, failing validation because a
removed field was still required, or allowing forged fields to reactivate hidden behavior. Server
replacement plus an authenticated HTTP regression test addresses all three.

## Data And Migration Plan

No schema migration or backfill is required. Saving creates the existing immutable policy revision.
Older imports remain pinned to their original policy snapshots. Rollback restores the previous view
and request behavior; historical revisions remain intact.

## Testing Plan

- Render the page with and without a Storage agent.
- Assert plain-language fields are present and all technical fields are absent.
- Save Review plus AI fallback using only ordinary fields.
- Submit forged learning, consensus, cost, timeout, retry, retention, and JSON values and prove the
  stored revision contains the safe server preset.
- Run policy, UI, AI, import-pipeline, full Storage, Blade, Pint, Knowledge sync, and a controlled
  provider smoke on Dev.
- Complete the named desktop/narrow-width human checks in `HR-2026-08-10-003`.

## Documentation Plan

Update Storage Knowledge, the original RFC amendment note, Feature Slice, TODO, human review, and
the public-safe website handoff. Keep the website entry unpublished until human review is complete.

## Open Questions

None. Automatic versus review handling remains an explicit administrator choice. Technical
execution controls are not a Storage product decision in the ordinary workflow.

## Approval

Approved by Svein Tore on 2026-08-11 in the Codex task with the request to simplify the displayed
settings and finish the workflow so it can be used.
