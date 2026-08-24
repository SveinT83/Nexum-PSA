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
2. Inventory the 36 preserved pre-existing unreferenced Email attachment files before any separately
   approved deletion. Their provenance and retention status are not yet proven.
3. Make scheduled polling cover every active eligible account with overlap-safe account/folder locks,
   verify the external scheduler and Email workers, and resolve the observed 53 folder-state errors
   without treating unread state as a cursor or silently replacing UID baselines.
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
| 4 | `HR-2026-08-16-004` | [Canonical message/placement shadow correlation](../feature-slices/2026-08-16-email-mail-canonical-message-shadow-correlation.md) | Done / Review Record Reconciliation Needed | Additive, conservative, account-safe shadow report; no cross-account merge or read-path cutover. Focused coverage passes 19 / 131; migration `110000` is included among the Order-1-through-7 migrations run in Dev batches 99-103. The review summary records Svein's 2026-08-19 approval while older detailed wording still needs record reconciliation. |
| 5 | `HR-2026-08-16-005` | [Canonical message/placement cutover and logical legacy-read retirement](../feature-slices/2026-08-16-email-mail-canonical-message-placement-cutover.md) | Done / Human Review Pending | Source occurrences remain immutable authorization/workflow identities; bounded strict actual-file parity, route/API/raw/attachment cutover, drift dissolution, and newest-first rollback are implemented. Mailboxes above 500 active placements use durable 100-row whole-account parity pages, current-authority continuation, and a 15-minute fingerprint bound into mode preview/apply; 501/502-placement drift/expiry/canonical-mode regression and final independent audit pass. Physical legacy-column removal still requires a separately reviewed forward migration after the rollback window. |
| 6 | `HR-2026-08-16-006` | [Integration-owned provider credentials, endpoint security, and legacy secret migration](../feature-slices/2026-08-16-email-mail-integration-provider-credentials-endpoint-security.md) | Done / Human Review Rework Needed | Integration now owns staged credentials and normalized endpoints. A reported portal 403 exposed missing runtime permissions; forward migration `2026_08_21_100000` repairs the eight approved entries and only the intended Admin/Superuser grants additively. The focused administration/migration matrix passes 7 / 127, Dev binding authorization succeeds, and unrelated Calendar/Sales grants remain intact. Live provider verification, cutover, legacy-secret purge, and the remaining named browser/provider review were not performed. |
| 7 | `HR-2026-08-16-007` | [Provider-originated read-only reconciliation](../feature-slices/2026-08-16-email-mail-provider-originated-reconciliation.md) | Done / Runtime Operations Pending | Bounded read-only all-folder convergence, exact provider-wins/conflict evidence, hidden imports, NOMODSEQ verification, deferred safe automation, durable bounded notification fan-out/outbox, cancellation/recovery, IDLE hint loss recovery, paged scheduled fallback, and sealed observable progress are implemented. Final focused SQLite passes 255 / 2,225, rolling-schema compatibility passes 4 / 26, private MariaDB evidence passes 3 / 434 plus the final `118500` contract 3 / 163, and the complete Email Feature directory passes 621 / 6,345. The 20 Order-1-through-7 migrations report Ran in Dev batches 99-103; controlled provider/browser/scheduler/worker/queue checks remain operator-gated. The review summary records Svein's 2026-08-19 approval, while its older detailed checklist still needs record reconciliation. |
| 8 | `HR-2026-08-16-008` | [Private live invalidation transport and polling fallback](../feature-slices/2026-08-16-email-mail-private-live-invalidation-polling-fallback.md) | Rework Needed / Runtime Disabled | Migration `130000` ran before the implementation was complete. The 2026-08-21 repair makes disabled mode fail closed, removes the broad global auth route, hardens the module auth boundary, fixes trigger-safe append evidence, and applies forward repairs `110000` and `120000`. `120000` refuses to run while live mode is enabled, quarantines incomplete base-authority writer guards, and permits valid same-second stream transitions. Bounded fanout, authorization generations, transactional writer coverage, fallback, retention and supervised operations remain incomplete; `EMAIL_LIVE_ENABLED` now defaults false, Echo is initialized only through both build-time and server-rendered gates, and the disabled Vite build contains no Echo/Pusher client. |
| 9 | `HR-2026-08-16-009` | [Reading/typing presence, shared draft locks, and stale-composer protection](../feature-slices/2026-08-19-email-mail-presence-shared-draft-locks-stale-composer.md) | Rework Needed / Schema Quarantined | Historical migration `140000` is now an inert deploy marker, ran in Dev batch 108, and created no lock table. `EMAIL_MAIL_COLLABORATION_ENABLED` defaults false. The original schema lacked the approved shared-scope/fencing contract, and per-user whispers cannot coordinate coworkers; a corrected design requires a new forward migration before activation. |
| 10 | `HR-2026-08-16-010` | [Deterministic rules API completion](../feature-slices/2026-08-19-email-mail-deterministic-rules-api-completion.md) | Rework Needed | The reversal path mutates only local folder state, omits the provider-operation ledger, and exposes incomplete failure behavior. It is not API/Undo parity. |
| 11 | `HR-2026-08-16-011` | [Compose, draft, send, and Sent API parity](../feature-slices/2026-08-19-email-mail-compose-draft-send-sent-api-parity.md) | Rework Needed | The Reply/Reply All/Forward undefined-placement regression is repaired with focused coverage and works while the quarantined lock table is absent, but private-versus-shared draft scope, durable fencing and complete API/Sent parity remain unproved. |
| 12 | `HR-2026-08-16-012` | [Conversation acknowledgement and explicit multi-account actions](../feature-slices/2026-08-19-email-mail-conversation-acknowledgement-multi-account-actions.md) | Rework Needed / Schema Quarantined | Historical migration `150000` is now an inert deploy marker, ran in Dev batch 108, and created no acknowledgement table. `EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED` defaults false. The action still lacks the frozen placement snapshot, action-level mailbox authorization and provider/personal result boundary; a corrected design requires a new forward migration before activation. |
| 13 | `HR-2026-08-16-013` | [Email/Ticket Conversation Relationship Migration](../feature-slices/2026-08-19-email-ticket-conversation-relationship-migration.md) | Rework Needed | The backfill calls an absent relationship, selects an arbitrary first user as actor, and has no focused verification or dispatch boundary. |
| 14 | `HR-2026-08-16-014` | [Email/Ticket Correlation Conflict Triage](../feature-slices/2026-08-19-email-ticket-correlation-conflict-triage.md) | In Progress / Implementation Missing | The planned conflict evidence, triage storage/actions/UI and tests are not implemented. Existing `TD-...` behavior remains unchanged. |
| 15 | `HR-2026-08-16-015` | [Email/Ticket Not-Ticket And Merge Compatibility](../feature-slices/2026-08-19-email-ticket-not-ticket-merge-compatibility.md) | In Progress / Dependency Gated | Current merge code only bulk-updates links. Suppression, locks/deduplication, strongest-role resolution, aliases, frozen preview, reauthorization and atomic conflict handling remain to implement after Orders 8–14 are safe. |
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

The 2026-08-21 reconciliation corrects program accounting and records the current Dev runtime; it
does not perform a provider call, queue job, external AI workload, BookStack push, deployment, or
production activation. Orders 1-7 are implemented. The exact 20 Order-1-through-7 migrations
`100000` through `118500` now report Ran in Dev batches 99-103. Migration `130000` reports Ran in
batch 104, the additive permission repair `2026_08_21_100000` in batch 105, and the live-stream
trigger repair `2026_08_21_110000` in batch 106. Runtime quarantine migration
`2026_08_21_120000` reports Ran in batch 107. The unsafe Order 9 and 12 migration filenames `140000`
and `150000` were converted to inert deploy markers and report Ran in batch 108; their tables remain
absent, and corrected schemas require new forward migrations. Order 7's review summary records Svein's
2026-08-19 approval, while its older detailed checklist still needs human record reconciliation;
controlled provider/browser/scheduler/worker/queue checks remain operator-gated. Orders 8-15 are
reclassified above according to current implementation evidence and still require their stated
rework or dependencies before activation.
