# Feature Slices: Full Lead Intelligence API Parity

Status: All six sequential slices Done On Dev; scoped Main candidate verified
Date: 2026-10-27
Parent: ../rfc/2026-10-27-lead-intelligence-full-api-parity.md
Owner: Codex

Parent approved by Svein Tore on 2026-10-27. Slices were implemented sequentially. Each slice must
leave a complete tested behavior and matching docs; do not expose stubbed operations.

| Slice | Goal and user-visible scope | Data and permissions | Required tests and done criteria |
| --- | --- | --- | --- |
| LI-01 | Authority, pure discovery/context, settings and segment contract parity | Existing settings/segments; active actor + sales.lead_manage + original ability; accessible list context | Denials, pure reads, nested validation, aliases, PATCH preservation, exact saved settings/segment read-back; shared requests/actions and typed docs |
| LI-02 | Durable commands, revisions and actor-bound operation read-back | Additive encrypted receipts/locks; no grant changes | Restart/concurrency/key conflict/stale/revocation tests, native DB and exact migration evidence; legacy writes covered |
| LI-03 | AI segment draft, queued run creation and Run Now | Existing Integration chats/telemetry/approval and run/queue tables; manage/run abilities plus current actor; API provider gates default-off | Provider-fake draft outcomes, one job per accepted command, commit/dispatch recovery, no AI save shortcut, runtime actor reauthorization; typed schemas/docs |
| LI-04 | Run list/detail/evidence and scan ledger parity | Read-only authorized run/source/Client/Contact data; bounded pagination | Exact evidence labels/IDs, filters/defaults, denied foreign records and no provider/agent mutation; typed schemas/docs |
| LI-05 | Eligibility and candidate promotion parity with shared Client authority | Existing evidence/eligibility, Clients, Contacts/bridge and Marketing members; run ability plus target rights; preserve suppression and provider policy | Dry-run purity, actual read-back, exclusions, duplicates, local/provider numbering, stable UUID, unknown-attempt reconciliation and explicit billing email; shared worker regression |
| LI-06 | Complete contract, affected verification and employee handbook | Swagger/API catalog and Knowledge/BookStack; existing employee access | Exact route/operation/schema matrix and actual response validation, affected suites, native DB/trusted HTTPS evidence, handbook publication/read-back; all artifacts reconciled |

## Out Of Scope

New discovery products, email sending, deletions/cancellation, Main/production promotion,
worker/scheduler activation, permission expansion and another module's unrelated work.

## Documentation

Each slice updates the coverage matrix, module/API/Knowledge docs, TODO status and
verification evidence. HR-2026-10-27-LEAD-INTELLIGENCE-API remains Pending until explicit
named human review. Passing tests never completes manual scenarios.

## Done Criteria

All 14 browser rows and existing API-only capabilities have verified official typed
operations, all supported writes/actions work with exact authorization/read-back,
relevant tests pass, actual handbook is verified, and deployment/review gates are explicit.
