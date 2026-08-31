# Email Mail Completion Slice Index

Status: In Progress
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Purpose

This index reconciles the approved Mail target with the implementation record after Svein asked on
2026-08-16 to complete every remaining documented Mail slice, including work that had previously
required a separate approval.

The authoritative Dev audit found that all **51 already-authored Mail-parent Feature Slices** are
marked `Done`. That did not mean the complete RFC target was done: several deferred, later, and
out-of-scope capabilities had never been converted into Feature Slices. The Mail program therefore
remains `In Progress`, and this file is the ordered source of truth for the remaining implementation
queue.

The 2026-08-16 instruction also supplies the separate implementation approval required for
restricted automatic replies and permanent provider deletion. It authorizes their guarded slices;
it does **not** enable automatic external sending or irreversible deletion, approve a provider or
production rollout, execute migrations, waive an ADR/security/retention/hold/two-person gate, or mark
any human review complete. Both capabilities remain installation-wide and account-level off by
default until their complete gate sets and named human reviews pass.

## Status Terms

- `In Progress`: an implementation slice has been opened and work may be active on authoritative
  Dev.
- `Rework Needed`: implementation evidence exists, but a verified defect or missing contract makes
  the slice unsafe to activate, migrate, or treat as complete.
- `Queued`: the outcome is authorized and ordered, but its dedicated Feature Slice must be written
  before implementation reaches that item.
- `Gated off`: implementation is authorized, but the runtime capability must remain disabled until
  its prerequisites, automated verification, operational rollout checks, and human review pass.
- Review IDs after `HR-2026-08-16-001` are reserved for the corresponding completed slices. They are
  added to `docs/human-review.md` only when the slice is opened or implemented; reservation is not a
  review result.

## Immediate Operational Gates

These are existing Dev reliability obligations and remain ahead of broad feature rollout:

1. Complete the PHP-FPM, queue-worker, browser, and exact failed-job checks in
   `HR-2026-08-15-003`; do not blindly retry or delete the unresolved job.
2. Investigate the latest snapshot's 477 preserved unreferenced Email files and 79 non-private legacy
   modes before any separately approved mutation. Their provenance and retention status are not yet
   proven; inventory evidence authorizes no deletion.
3. Both active eligible accounts now poll successfully through overlap-safe account/folder locks and
   the former 53 folder-state errors are resolved. Keep the complete scheduler and notification
   worker disabled until the 136-job notification cohort has an explicit operator disposition.
4. Keep provider-deletion reconciliation and every other destructive/default-off setting disabled
   until its existing human-review entry passes.

These gates do not convert pending human review into a defect automatically. A reported security,
data-integrity, send-reconciliation, or operational defect must be resolved before a dependent slice
continues.

## Ordered Remaining Slices

| Order | Reserved review | Remaining slice | State | Required boundary |
| --- | --- | --- | --- | --- |
| 1 | `HR-2026-08-16-001` | [Historical import and UID re-baseline](../feature-slices/2026-08-16-email-mail-historical-import-and-uid-rebaseline.md) | Done / Human Review Pending | Explicit preview, scope, caps, immutable progress, forward-only live cursor, no unread-derived import, and no provider mutation. |
| 2 | `HR-2026-08-16-002` | [Mailbox delegation, audited break-glass, and access history](../feature-slices/2026-08-16-email-mailbox-delegation-break-glass-access-history.md) | Done / Human Review Pending | Owner privacy, reason and expiry, revocation, notification, metadata-only audit, and no ordinary administrator content access. |
| 3 | `HR-2026-08-16-003` | [Per-user unread baselines and explicit backlog handover](../feature-slices/2026-08-16-email-mail-per-user-unread-baselines-backlog-handover.md) | Done / Human Review Pending | New grants do not flood history; selected handover is previewed, snapshotted, and independent of provider `Seen`. Focused coverage passes 13 / 118; broad affected-module regression passes 328 / 2,611. |
| 4 | `HR-2026-08-16-004` | [Canonical message/placement shadow correlation](../feature-slices/2026-08-16-email-mail-canonical-message-shadow-correlation.md) | Done / Review Record Reconciliation Needed | Additive, conservative, account-safe shadow report; no cross-account merge or read-path cutover. Focused coverage passes 19 / 131; migration `2026_08_16_110000` ran in recovered Dev batch 104. The review summary records Svein's 2026-08-19 approval while older detailed wording still needs record reconciliation. |
| 5 | `HR-2026-08-16-005` | [Canonical message/placement cutover and logical legacy-read retirement](../feature-slices/2026-08-16-email-mail-canonical-message-placement-cutover.md) | Done / Human Review Pending | Source occurrences remain immutable authorization/workflow identities; bounded strict actual-file parity, route/API/raw/attachment cutover, drift dissolution, and newest-first rollback are implemented. Mailboxes above 500 active placements use durable 100-row whole-account parity pages, current-authority continuation, and a 15-minute fingerprint bound into mode preview/apply; 501/502-placement drift/expiry/canonical-mode regression and final independent audit pass. Physical legacy-column removal still requires a separately reviewed forward migration after the rollback window. |
| 6 | `HR-2026-08-16-006` | [Integration-owned provider credentials, endpoint security, and legacy secret migration](../feature-slices/2026-08-16-email-mail-integration-provider-credentials-endpoint-security.md) | Done / Human Review Rework Needed | Dev permission repair `2026_08_21_100000` ran additively in batch 121; no seeder ran. Both Dev accounts now use Integration-owned credentials, binding version 2, active polling and the approved exact private `/32`. Production remains untouched and must receive the additive permission migration when promoted; never replace it with the full `RoleSeeder`. Legacy-secret purge and named browser/provider review remain open. |
| 7 | `HR-2026-08-16-007` | [Provider-originated read-only reconciliation](../feature-slices/2026-08-16-email-mail-provider-originated-reconciliation.md) | Done / Runtime Operations In Review | Bounded read-only all-folder convergence, exact provider-wins/conflict evidence, hidden imports, NOMODSEQ verification, deferred safe automation, durable notification evidence, cancellation/recovery and paged fallback are implemented. The clean final Email Feature suite passes 686 / 7,046. Dev's first controlled account-2 catch-up failed safely at the old ordinary cap of 100; the default now equals the hard cap 500 while 501 still fails closed. Replacement run 2 finished terminal `stale/summary` after reading all 137 folders: 131 complete, 6 stale with `provider_tuple_drift`, 8,427 observations, 7 confirmed missing and 0 moves/conflicts/errors. Seven local placements were hidden under provider-wins projection; no provider write or notification delivery occurred. Notification delivery and broader scheduler/browser review remain operator-gated. |
| 8 | `HR-2026-08-16-008` | [Private live invalidation transport and polling fallback](../feature-slices/2026-08-16-email-mail-private-live-invalidation-polling-fallback.md) | Static Publisher Rework Implemented / Runtime Disabled | Forward repair `2026_08_24_120000` ran in Dev batch 125 with all live/Vite/operations/collaboration/UI/acknowledgement gates false. Four repaired UPDATE guards are present and live publication ledgers remain empty. SQLite, actual disposable MariaDB, build and full Email Feature coverage pass. Authority generation/recompute plus a dedicated bounded current-page projection and supervised Reverb/Apache/worker/scheduler/browser review remain open. |
| 9 | `HR-2026-08-16-009` | [Reading/typing presence, shared draft locks, and stale-composer protection](../feature-slices/2026-08-19-email-mail-presence-shared-draft-locks-stale-composer.md) | Backend/API Safety Rework Implemented / Runtime And UI Gated | Historical `140000` stays inert. Forward migration `2026_08_24_125000` ran in Dev batch 126 after Order 11; the existing draft remains private, shared lock/event ledgers are empty, and collaboration/UI gates remain false. The legacy workspace SQL-lock/presence/whisper activation path is removed, and the fresh default-off asset contains only the current collaboration boundary. Disposable MariaDB and full Feature coverage pass. Redis/Reverb, two-user/two-tab and accessible UI review remain open. |
| 10 | `HR-2026-08-16-010` | [Deterministic rules API completion](../feature-slices/2026-08-19-email-mail-deterministic-rules-api-completion.md) | Safety Rework Implemented / Full Slice Dependency Gated | The unsafe local reversal is removed. Completed attempts are immutable, later actions become `not_run` after failure, and account-scoped API Undo delegates one exact provider Archive/Move effect to the verified remote-operation inverse while mixed/local/stale/mismatched requests fail closed. Focused and adjacent SQLite coverage passes 99 / 976. Draft, durable reprocess/retry/full-rerun, and complete API/OpenAPI parity still depend on Orders 8-9. |
| 11 | `HR-2026-08-16-011` | [Compose, draft, send, and Sent API parity](../feature-slices/2026-08-19-email-mail-compose-draft-send-sent-api-parity.md) | Private/API Rework Implemented / Human Review Pending; Shared Runtime Gated | The private draft/send boundary has opaque identities, HMAC fencing, exact-generation attachments and a durable pre-SMTP submission ledger. Additive migration `2026_08_24_110000` ran in Dev batch 124; the one existing draft remains private and the outbound ledger is empty. Controlled web/API/SMTP/Sent review remains Pending. |
| 12 | `HR-2026-08-16-012` | [Conversation acknowledgement and explicit multi-account actions](../feature-slices/2026-08-19-email-mail-conversation-acknowledgement-multi-account-actions.md) | Safety Rework Implemented / Human Review Pending; Full Slice Gated | Historical `150000` remains inert. Forward migration `2026_08_24_140000` ran in Dev batch 128; its exact placement/action ledgers are empty and the acknowledgement gate remains false. Immutable previews, per-item reauthorization, personal coalescing and provider-per-placement evidence are implemented. Public UI/API confirmation and named review remain gated. |
| 13 | `HR-2026-08-16-013` | [Email/Ticket Conversation Relationship Migration](../feature-slices/2026-08-19-email-ticket-conversation-relationship-migration.md) | Safety Rework Implemented / Human Review Pending | The frozen bounded preview/apply ledger and deterministic conflict/stale/provenance guards are implemented. Additive migration `2026_08_24_130000` ran in Dev batch 127 and both ledgers remain empty; no preview/backfill ran. Disposable data-copy/runtime review and Order 14 conflict resolution remain open. |
| 14 | `HR-2026-08-16-014` | [Email/Ticket Correlation Conflict Triage](../feature-slices/2026-08-19-email-ticket-correlation-conflict-triage.md) | Done On Dev / Human Review Reopened | Durable-link, RFC-header and `TD-...` evidence is resolved together; disagreement creates one metadata-only pending conflict, blocks auto-link/create, and requires an audited administrator choice. Focused and complete Email feature coverage pass; the new migration/UI/runtime checks remain for the planned human test. |
| 15 | `HR-2026-08-16-015` | [Email/Ticket Not-Ticket And Merge Compatibility](../feature-slices/2026-08-19-email-ticket-not-ticket-merge-compatibility.md) | Done On Dev / Human Review Pending | Durable account/conversation suppression replaces broad sender+subject rules and is checked before every Ticket correlation/default-create path. Atomic merge now uses a frozen server-verified snapshot, current Ticket/Mail authorization, row locks, link deduplication, primary/internal conflict resolution, pending-conflict canonicalization and retired Ticket-key aliases. Provider state is untouched; browser/runtime review remains Pending. |
| 16 | `HR-2026-08-16-016` | [Email/Ticket Compose And Sent Reconciliation](../feature-slices/2026-08-16-email-ticket-compose-sent-reconciliation.md) | Queued / Dependency Gated | Selected-conversation sender/recipient/thread context, additive Ticket key, unified outbound action, one Sent projection, and no cross-thread bleed. |
| 17 | `HR-2026-08-16-017` | [Email/Ticket Third-Party Recipient Policy](../feature-slices/2026-08-16-email-ticket-third-party-recipient-policy.md) | Queued / Dependency Gated | Dedicated Ticket guard plus Mail View/Send, exact recipient/audience preview, explicit manual-recipient confirmation, and no automatic Contact creation. |
| 18 | `HR-2026-08-16-018` | [Email/Ticket Multi-Conversation UI](../feature-slices/2026-08-16-email-ticket-multi-conversation-ui.md) | Queued / Dependency Gated | One Ticket may show several clearly separated real conversations without pretending they are one thread or widening mailbox access. |
| 19 | `HR-2026-08-16-019` | [Email/Ticket Audience And Portal Enforcement](../feature-slices/2026-08-16-email-ticket-audience-portal-enforcement.md) | Queued / Dependency Gated | Third-party correspondence defaults internal; publication is explicit, previewed, authorized, audited, and never retroactive by accident. |
| 20 | `HR-2026-08-16-020` | [Email/Ticket Closed-Ticket Conversation Workflow](../feature-slices/2026-08-16-email-ticket-closed-conversation-workflow.md) | Queued / Dependency Gated | Later inbound/outbound behavior remains deterministic for resolved/closed cases and never bypasses Ticket workflow, recipient, or portal policy. |
| 21 | `HR-2026-08-16-021` | [Attachment malware quarantine and safe content policy](../feature-slices/2026-08-16-email-mail-attachment-malware-quarantine.md) | Queued / Dependency Gated | Known malicious content is quarantined; preview, extraction, indexing, rules, and AI require clean evidence; warned raw download is separately guarded. |
| 22 | `HR-2026-08-16-022` | [Local advanced Mail search and rebuildable index](../feature-slices/2026-08-16-email-mail-local-advanced-search.md) | Queued / Dependency Gated | Installation-controlled default, permission-aware counts/snippets/facets, clean attachments only, revocation/deletion propagation, and non-blocking index failure. |
| 23 | `HR-2026-08-16-023` | [Legal hold, DSAR/export, account offboarding, and restore workflow](../feature-slices/2026-08-16-email-mail-lifecycle-hold-export-offboarding-restore.md) | Queued / Dependency Gated | Explicit scope/authority/audit, separate Ticket ownership, honest backup expiry, token/socket cancellation, and no replay of pre-backup sends. |
| 24 | `HR-2026-08-16-024` | [Separately authorized permanent provider deletion](../feature-slices/2026-08-16-email-mail-permanent-provider-deletion.md) | Queued / Dependency Gated | Implementation approval is recorded; distinct permission and confirmation, capability-aware provider action, retention/hold/Ticket evidence checks, immutable audit, two-person approval, and no bulk/default activation remain mandatory. |
| 25 | `HR-2026-08-16-025` | [Governed entity matching and permission-filtered PSA context](../feature-slices/2026-08-16-email-mail-governed-entity-matching.md) | Queued / Dependency Gated | Contact-owned deterministic identity, manual fallback, governed AI where used, provenance, and no mailbox or Work Context leakage. |
| 26 | `HR-2026-08-16-026` | [Restricted automatic replies](../feature-slices/2026-08-16-email-mail-restricted-automatic-replies.md) | Queued / Dependency Gated | Separate 2026-08-16 implementation approval is recorded; allowlists, sensitive-case exclusion, layered account/rule/scenario opt-in, loop/duplicate/rate/cost limits, delivery reconciliation, emergency stop, and no-send-on-uncertainty remain mandatory. |
| 27 | `HR-2026-08-16-027` | [Microsoft 365 provider driver](../feature-slices/2026-08-16-email-mail-microsoft-365-provider-driver.md) | Queued / Dependency Gated | Integration-owned OAuth lifecycle, least scope, immutable Graph IDs, delta/webhook recovery, capability mapping, migration/rollback and provider tests. |
| 28 | `HR-2026-08-16-028` | [Google Workspace Gmail API provider driver](../feature-slices/2026-08-16-email-mail-google-workspace-provider-driver.md) | Queued / Dependency Gated | Integration-owned OAuth, native message/history/label semantics, authenticated Pub/Sub hints, least scope, migration/rollback and provider tests. |
| 29 | `HR-2026-08-16-029` | [Calendar invitations and out-of-office workflows](../feature-slices/2026-08-16-email-mail-calendar-invitations-out-of-office.md) | Queued / Dependency Gated | Calendar owns normalized iMIP/event/absence decisions; Email owns exact source/outbound/Sent transport; responses and OOO remain explicit, scoped and loop-safe. |
| 30 | `HR-2026-08-16-030` | [Documentation/file-provider attachment save and link](../feature-slices/2026-08-16-email-mail-documentation-file-provider-handoff.md) | Queued / Dependency Gated | Explicit guarded handoff, Documentation-owned durable file/link, Nextcloud provider verification, malware-clean evidence, independent lifecycle and no implicit copying. |
| 31 | `HR-2026-08-16-031` | [Advanced Mail collaboration](../feature-slices/2026-08-16-email-mail-advanced-collaboration.md) | Queued / Dependency Gated | Account-conversation responder assignment, handoff, watchers, internal notes/mentions, and version-bound shared-draft review build on private transport, fencing, lifecycle, and revocation; no content-bearing public events or Ticket access widening. |
| 32 | `HR-2026-08-16-032` | [Mail reporting and advanced productivity](../feature-slices/2026-08-16-email-mail-reporting-advanced-productivity.md) | Queued / Dependency Gated | Personal saved views/favorites/snooze/reminders/snippets and permission-filtered local aggregates use bounded retention and current Mail authority; no copied content, external analytics, presence history, or individual performance ranking. |

## Program Invariants

- The existing singular `Email` module remains the Mail domain owner.
- Provider folders, placement, flags, read state, drafts, Sent, Trash, and message existence remain
  provider-authoritative. Nexum's `unread for me`, opened receipts, rules, PSA links, and governed
  suggestions remain separate Nexum state.
- The existing `TD-...` Ticket-number correlation remains supported. Standards headers and durable
  conversation links extend it additively.
- Ticket linking never grants Mail access, removes the source from its mailbox, or silently publishes
  third-party correspondence.
- No migration imports historical messages, calls a provider/model, sends mail, changes provider
  state, replays rules, or marks messages read.
- No control is shown before its backend, authorization, tests, error behavior, documentation, and
  honest runtime state exist.
- Each slice receives focused tests, affected-module regression coverage, Knowledge/developer-doc
  updates, deploy/rollback notes, and a `Pending` human-review entry. Automated checks never set that
  entry to `Reviewed`.

## Documentation Reconciliation Result

The 2026-08-24 completion pass records the recovered Dev ledger without claiming production
deployment or human review. Order 11 migration `2026_08_24_110000` is batch 124; Order 8 guard repair
`120000` is batch 125; Order 9 coordination `125000` is batch 126; Order 13 ledger `130000` is batch
127; and Order 12 ledger `140000` is batch 128. All six live/Vite/operations/collaboration/UI/
acknowledgement gates remain false. New Order 9/11/12/13 operational ledgers are empty and no Ticket
relationship backfill or acknowledgement apply ran. The exact historical inert migration markers
remain untouched.

Dev uses targeted database queue cron for `email,default` and direct `email:poll`; the full Laravel
scheduler and `notifications` worker are not active. There are 136 unattempted inbound notification
fanout jobs and Web Push is already enabled for the two relevant notification types, so activation
requires an explicit human delivery decision. Production remains untouched and still needs the
additive `2026_08_21_100000` permission repair when promoted; never substitute a full `RoleSeeder`.
The August 15–21 recovery loss window, 79 legacy file modes, canonical attachment discrepancy, and
all named HR-008 through HR-013 checks remain open.
