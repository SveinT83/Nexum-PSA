# Signal Browser/API Coverage Matrix

Status: Done On Dev / Human Review Reviewed. All mapped operations are implemented and verified.
Date: 2026-10-27. Source: authoritative /var/Projects/tdPSA on Dev.
Baseline runtime discovery: 18 browser rows (10 canonical workflows / 8 aliases), 1 API
operation. Final coverage has 19 API operations and 42 schemas. HEAD is excluded. Exact route/middleware/source hashes are in the JSON.

| ID | Class | Browser route | Planned API | Current native right |
| --- | --- | --- | --- | --- |
| S01 | read | `GET /tech/admin/system/signals` | `GET /api/v1/signals` | `signal.view` |
| S02 | read | `GET /tech/admin/system/signals/rules` | `GET /api/v1/signals/rules` | `signal.view` |
| S03 | write | `POST /tech/admin/system/signals/rules` | `POST /api/v1/signals/rules` | `signal.rule.manage` |
| S04 | read | `GET /tech/admin/system/signals/rules/create` | `GET /api/v1/signals/rules/context` | `signal.rule.manage` |
| S05 | read | `GET /tech/admin/system/signals/rules/{rule}` | `GET /api/v1/signals/rules/{rule}` | `signal.view` |
| S06 | write | `PUT /tech/admin/system/signals/rules/{rule}` | `PUT /api/v1/signals/rules/{rule}` | `signal.rule.manage` |
| S07 | read | `GET /tech/admin/system/signals/settings` | `GET /api/v1/signals/settings` | `signal.rule.manage` |
| S08 | write | `PUT /tech/admin/system/signals/settings` | `PUT /api/v1/signals/settings` | `signal.rule.manage` |
| S09 | read | `GET /tech/admin/system/signals/{signal}` | `GET /api/v1/signals/{signal}` | `signal.view` |
| S10 | action | `POST /tech/admin/system/signals/{signal}/executions/{execution}/retry` | `POST /api/v1/signals/{signal}/executions/{execution}/retry` | `signal.action.execute` |
| S11 | read | `GET /tech/signals` | `GET /api/v1/signals` | `signal.view` |
| S12 | read | `GET /tech/signals/rules` | `GET /api/v1/signals/rules` | `signal.view` |
| S13 | write | `POST /tech/signals/rules` | `POST /api/v1/signals/rules` | `signal.rule.manage` |
| S14 | read | `GET /tech/signals/rules/create` | `GET /api/v1/signals/rules/context` | `signal.rule.manage` |
| S15 | read | `GET /tech/signals/rules/{rule}` | `GET /api/v1/signals/rules/{rule}` | `signal.view` |
| S16 | write | `PUT /tech/signals/rules/{rule}` | `PUT /api/v1/signals/rules/{rule}` | `signal.rule.manage` |
| S17 | read | `GET /tech/signals/{signal}` | `GET /api/v1/signals/{signal}` | `signal.view` |
| S18 | action | `POST /tech/signals/{signal}/executions/{execution}/retry` | `POST /api/v1/signals/{signal}/executions/{execution}/retry` | `signal.action.execute` |

## Embedded And Supporting Workflows

| ID | Workflow | Acceptance evidence required |
| --- | --- | --- |
| S19 | Existing POST /api/v1/signals | Preserve field/default/record-only behavior; native/action/provider guards, exact keyed replay and rule result. |
| S20 | Client and Contact related Signal panels | Exact parent/morph linkage, existing Clients coverage, Contact gap, no other-parent payload leak. |
| S21 | Rule editor reference and Intake prefill | Typed ten-action catalog, fields/operators, active actors/templates/portal roles; current rights and bounded lookup. |
| S22 | Execution list/detail and explicit retry preview | Immutable snapshot, original positions/results/attempt links, safe capabilities; preview has no side effect. |
| S23 | Command operation read-back | Current original actor/token/object authority, encrypted results, no revoked/foreign disclosure. |
| S24 | AI settings linked agent | Safe current status only; Integration owns configuration and credentials. |

All rows require typed success/error HTTP evidence, explicit token scopes, native
rights, same shared action/query and recorded test/Swagger paths before Done.
The JSON records proposed scopes and detailed row acceptance. No percentage claim
is valid from route registration alone. Settings have no /tech/signals alias today.
Rule delete and Signal update/delete have no supported browser workflow today.

## Known Compatibility And Operational Boundaries
The current ingest token check alone is insufficient for the proposed actor model.
The RFC explicitly proposes native signal.action.execute for ingest and a separate
signals.actions.execute ability for process_rules=true. External effects require
an explicit provider ability/default-off API switch. Unkeyed legacy ingest stays
possible but cannot promise duplicate-free retransmission. No automatic role grant.
Existing GET calls to EnsureSignalDefaults have mutation side effects: shared API
discovery must not grant rights or activate/configure providers. This is part of the
approved implementation analysis, not a claim that it is already repaired.

#262 scheduler/queue reconciliation and #285 Ticket Rules activation are separate.
The existing July recovery checklist HR-2026-07-15-002 is Reviewed by Svein on
2026-08-25. It does not approve this new API contract. New human-review checklist
HR-2026-10-27-SIGNAL-API is Pending and gates Main/production release.


## Final Dev Verification
All S01-S24 workflows are covered. Each observed browser row links its official
runtime operation and contract/parity tests in the JSON. All 19 successful operations
were observed and validated against their typed Swagger results; 42 schemas are present.
The reference catalog now includes the existing Sales needs field, and historical
attempts/list-or-null payloads retain safe compatible read-back.
Native MySQL, exact Dev migration, trusted HTTPS and handbook evidence are in
2026-10-27-signal-api-verification.md/.json. Human review is Pending; no Main/production
promotion or external runtime activation is performed by this task.
Earlier planned/proposed wording records the baseline; the approved RFC and final
verification govern the implemented scope and compatibility requirements.
