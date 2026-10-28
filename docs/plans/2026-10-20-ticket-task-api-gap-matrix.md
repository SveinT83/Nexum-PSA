# Ticket and Task full API parity matrix

Status: All ten approved slices implemented on Dev; automated verification passed; scoped Main merge separately approved by Svein Tore on 2026-10-28; operational human review Pending.
Date: 2026-10-20. Parent: ../rfc/2026-10-20-ticket-task-full-api-parity.md.

The initial audit found 130 browser route rows, 40 API route rows / 42 HTTP operations, and 28 undocumented operations. Current runtime inventory has **130 browser routes and 214 API route rows / 216 HTTP operations**: **113 GET, 62 POST, 22 PUT, 7 PATCH and 12 DELETE**. HEAD is excluded. Forty-one audited functional groups include ordinary operations, administrative settings, workflow/Rules and Customer Portal behavior.

All 216 actual HTTP method/path pairs are present in the generated Swagger JSON independently fetched over trusted HTTPS. This is route/contract coverage, not proof that every production workflow has been manually accepted. Existing runtime switches remain unchanged. The machine-readable JSON retains baseline gaps, original routes, current routes, scopes/middleware and implementation mappings.

| ID | Domain capability | Implemented API / native authority |
|---|---|---|
| T01 | Full detail and result read-back | GET tickets; tickets/{ticket}; history; time-entries; cost-entries; planned-lines; tasks; schedule |
| T02 | Create/edit context and reference data | GET tickets/context; tickets/context/{catalog}; tickets/{ticket}/context; time-rate-options |
| T03 | Complete creation payload | POST tickets (full validated form, type/tags/schedule/portal/Custom Fields) |
| T04 | Complete editing and scheduling | PUT/PATCH tickets/{ticket} (client/context, type, SLA, tags and complete scheduling) |
| T05 | Conversation and message content | GET tickets/{ticket}/conversation; messages/{message} |
| T06 | Internal notes and selected-technician notifications | POST tickets/{ticket}/notes (notifications, solution flag, files and stable request key) |
| T07 | Customer replies with files | POST tickets/{ticket}/messages (JSON/multipart public reply, files and exact replay) |
| T08 | Attachment list/upload/download | GET tickets/{ticket}/attachments[/{attachment}[/download]]; POST notes/messages with attachments |
| T09 | Ticket/message read receipts | POST tickets/{ticket}/read-state; messages/{message}/read-state |
| T10 | Mark an existing message as solution | POST tickets/{ticket}/messages/{message}/solution |
| T11 | Time history/detail/correction | POST/GET tickets/{ticket}/time-entries; GET/PATCH nested time entry |
| T12 | AI invoice-text suggestion | POST tickets/{ticket}/time-entries/invoice-text-suggestion (separate conversation/read grants) |
| T13 | Cost read/edit/release/pick | POST/GET tickets/{ticket}/cost-entries; GET/PATCH/DELETE nested entry; pick-preview/pick |
| T14 | Automatic assignment engine | GET assignment-preview; POST tickets/{ticket}/assignment with actor-bound request key |
| T15 | Merge suggestions/dismissal/merge | GET merge suggestions; POST merge/dismiss; GET lifecycle state (cross-client merge; no preview) |
| T16 | Not-Ticket classification | GET classification/delete preview; POST tickets/{ticket}/not-ticket with current snapshot |
| T17 | Delete Ticket | GET delete-preview; DELETE tickets/{ticket}; GET lifecycle-state and archived reads |
| T18 | Documentation request/revision context | GET/POST documentation-requests; GET nested request; GET ticket-settings/documentation-requests |
| T19 | Reply in selected source email conversation | GET email-relationships; POST selected reply-draft; GET email-communications and native Mail links |
| T20 | Relationship escalation | GET relationship-targets/links/escalation receipts; POST relationships/escalate |
| T21 | Workflow/close/quote/planned-line/review/evidence operations | Existing workflow/quote/planned-line/review/evidence writes; complete workflow editor CRUD/publication/migration; new nested quote/review/evidence reads |
| T22 | Own/admin assignment settings and rules | GET/PUT me/ticket-assignment; administrative assignment profile/rule reads and supported writes |
| T23 | Ticket policies and catalog administration | GET/PUT ticket-settings policies; catalog CRUD; context/history and explicit audit |
| T24 | Rule drafts/preview/publication/toggle/delete | Rule readiness/catalog/draft CRUD/preview/versions/publication/enabled/delete and legacy compatibility |
| T25 | Rule execution history/retry/full rerun | Rule execution list/detail; exact action retry; gated full-rerun preview/execute |
| T26 | SLA report data | GET tickets/reports/sla with browser calculation/filters and report.view |
| T27 | Customer Portal operations | Portal own-membership context/sites/list/detail/create/reply/public-message/file/download APIs |
| K01 | Full detail/hierarchy/dependency/activity/time read-back | GET tasks; tasks/{task}; children/dependencies/checklist/activities/time; browser filters/sort |
| K02 | Complete create/edit payload | POST tasks; PUT/PATCH tasks/{task} (tags/checklist/rate/context/date fields) |
| K03 | Assignment/status activity and notifications | PATCH tasks/{task}/assignee and /status using shared actions and persisted activity |
| K04 | Checklist editing/checking | PUT checklist state; replace checklist through validated Task create/edit payload |
| K05 | Domain completion | POST tasks/{task}/complete with native dependencies/children/checklist/time/actor rules |
| K06 | Actual time registration and billing delta | POST/GET actual Task time, actor-bound stable key and native cumulative Ticket billing projection |
| K07 | AI field/checklist suggestions | POST tasks/ai-suggestions (typed draft fields, current context/grants, no automatic save) |
| K08 | Template CRUD/graph editing/history | Template/item CRUD, graph/nesting/dependencies/checklists/tags/history and generation-run reads |
| K09 | Template target lookup/preview/apply | Target lookup; GET template preview; POST apply with snapshot/key; exact run/Task read-back |
| K10 | Schedules/toggle/generate/history | Schedule CRUD/active setter/run-preview/manual run/history and native bounded occurrences |
| K11 | Defaults and billing settings | GET/PUT task-settings; context/history; explicit POST initialize and repeated-default regression |
| K12 | Form/reference/owner/rate context | GET Task form/context/catalog/owner/rate references with private/current owner scope |
| X01 | OpenAPI and capability discovery | All 216 HTTP operations in Swagger, including both PUT/PATCH aliases, binary/multipart, schemas, cumulative/conditional grants and capability discovery |
| X02 | Actor/record permissions, side effects and retries | Active actor plus token grants, nested/private/context/Portal checks, fixed input fields, native actions/audit/retry receipts and unchanged dark runtime gates |

## Explicit operational decisions

- Full read/write/action parity is approved; this is not a read-only implementation.
- Cross-client Ticket merge is permitted without preview, as Svein explicitly requested. Deletion/classification and stock-pick previews retain their own guards.
- Tasks and active reservations follow the target/customer context. Unallocated foreign-customer contract links detach; recorded rates and allocated customer/contract snapshots remain authoritative.
- Archived detail/conversation/files/history are readable with the proper grants; archived mutations are rejected. Source-owned Sales/Knowledge/Relationship history retains native ownership rules.
- Explicit route input fields cover the complete operation even when validation occurs in several shared form stages. Unsupported writes fail rather than silently appearing saved.
- Native Mail and Relationship ownership remains authoritative. Accepted, provider-reconciled, unresolved and customer-delivered states remain distinct. Unknown external attempts require reconciliation before resend.
- Configuration audit uses the existing activity journal atomically. No new migration, runtime activation, token widening, commit/push, Main promotion or production deployment was performed.

## Remaining gates and owners

HR-2026-10-20-TICKET-TASK-API is Pending and gates production deployment/release; Svein Tore separately approved the scoped Main merge on 2026-10-28. Svein must verify same-account browser/API operations, cross-customer merge/commercial cases, real-database concurrency, private/Portal/field access, notification/provider outcomes and scheduler operation. Automated evidence supports but does not complete that review.

BookStack publication is blocked by missing supported Dev integration/access configuration. Svein owns establishing the intended publication path; Codex owns exact-source sync, article content read-back and employee access verification afterward. Ten unrelated pending articles must not be bulk-pushed. Repository Knowledge sources are updated; publication and employee assistant retrieval are unverified.

See [JSON inventory](2026-10-20-ticket-task-api-gap-matrix.json), [acceptance evidence](2026-10-20-ticket-task-api-acceptance.md), [human-review checklist](../human-review.md#hr-2026-10-20-ticket-task-api-complete-tickettask-api-parity) and [Dev Swagger](https://dev.nexumpsa.eu/api/documentation).

Final automated evidence: broad run 560 passed / 15,569 assertions (66 files); final affected
Task/owner/read-back/lifecycle/Swagger run 106 passed / 11,079 assertions (12 files, overlapping
coverage). Both source regression and schema/route inventory checks pass. Human review and supported
BookStack publication remain open as stated above.

Current Main candidate verification: [promotion evidence](2026-10-28-ticket-task-api-main-promotion.md).

## Current-Main audit (2026-10-28)

The final audit closes the raw Task Markdown guide gap with GET /api/v1/tasks/docs, using the same source and task.view policy as the browser. Current totals are **130 browser rows, 63 Livewire public methods, 215 API route rows and 217 HTTP operations: 114 GET and 103 writes/actions**. Historical Oct20 counts remain below as dated evidence. All browser/component actions now have explicit read/write/action classification and named API/test/read-back links in [full coverage](2026-10-28-ticket-task-api-coverage.md) and the expanded JSON inventory.
