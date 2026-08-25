# tdPSA Development TODO

This file is the shared coordination list for tdPSA development. Use it to delegate work across contributors and keep implementation, tests, and Knowledge/BookStack documentation moving together.

## Working Rules

- Pick one item, add your name or initials under `Owner`, and keep the status updated.
- Keep domain code inside `app/Modules/{Domain}` and follow `AGENTS.md`, `module-architecture.md`, and `ui-guidelines.md`.
- Every completed or materially updated domain feature must update Knowledge documentation and mark it for BookStack sync.
- Add or update tests for behavior changes before marking an item done.
- Do not rewrite unrelated dirty files. Work with the current branch state.

## Status Legend

- `Ready`: can be picked up now.
- `In Review`: the proposed direction is being reviewed before implementation approval.
- `In Progress`: someone is actively working on it.
- `Blocked`: needs a decision or prerequisite.
- `Done`: implemented, tested, and documented.

## Active Workstreams

| Area | Status | Owner | Notes |
| --- | --- | --- | --- |
| BookStack Cross-Process Rate-Limit Coordination (#196) | 429 Fix Verified / Full Sync Blocked By Article TEXT Limit | Svein / Codex | The cache-backed one-request/second limiter is verified live: the configured provider exposes 376 pages and 60 real page reads completed in 59.92 seconds with zero failures or HTTP 429. A single-process full pull then failed separately with SQLSTATE 22001 / driver 1406 because three pages contain 67,542 to 89,095 bytes of HTML while `articles.body_html` is `TEXT`; 373 local pages exist with zero duplicate `source_id` groups. Draft RFC `docs/rfc/2026-08-25-knowledge-large-article-content.md` proposes guarded `MEDIUMTEXT` columns. Keep Issue #196 open until RFC approval, migration, and one complete 376-page pull. Human review is `HR-2026-08-25-005`. |
| Mail Production Placement Schema Repair | Done On Dev / Production Migration Pending | Svein / Codex | Production had recorded `2026_08_16_118000_add_email_provider_reconciliation` as Ran while its four placement-observation columns, foreign key, indexes, and guards were absent. New forward-only migration `2026_08_25_100000_repair_email_provider_reconciliation_placement_schema.php` repairs partial MariaDB/MySQL/SQLite states idempotently and ran on Dev in batch 132. The regression reproduces the recorded-migration/schema-drift state and passes. Production still requires backup, stopped Email/default/notification workers, migration, schema read-back, worker restart, provider reconciliation, and proof that the 241 already-stored post-18-August messages receive active Inbox placements. Track under `HR-2026-08-16-007`; do not run the historical migration again or create placements with an ad-hoc SQL backfill. |
| Scheduled Ticket SLA Management | Done | Junie | All Slices (One-time, Recurring, Calendar Linkage, and Automation) implemented, tested, and verified. Pending final human review HR-2026-08-25-001. |
| Commercial Contract Customer Document And Pricing Consistency | Done / Production Readiness And Human Review Pending | Svein / Codex | Implemented on authoritative Dev under the approved RFC/ADR and completed Feature Slices. The boundary uses Brick Math/minor units, exact CloudFactory Contract-bound decimals, one Norwegian six-column projection, immutable non-null evidence with full semantic v1 validation, preview-safe Livewire validation, and term metadata v2. Historical null-snapshot customer paths fail closed; only a named, original-evidence attestation with stable fingerprint and explicit document type can freeze the reconstruction, and it never replaces non-null evidence. Ambiguous approved/won rows show no reconstruction before type selection. API/portal lists isolate unavailable rows, missing tokens do not create public/resend access, and accepted CloudFactory line reconciliation locks the parent Contract first. Migrations `160000` and `180000` ran in Dev batches 130 and 131 with bounded backfill, NOK preflight, and protected rollbacks. Production remains blocked until authoritative supplier/customer identity and the one legacy won evidence disposition are supplied, production rate visibility is classified, and the exact EDR Service/cadence is identified. Never guess or automatically backfill these facts. Final authoritative scoped Dev verification passes 99 tests / 1,455 assertions: `ContractFinalReviewRegressionTest` 44 / 868, `ContractCustomerDocumentTest` 6 / 104, `CommercialModuleTest` 33 / 313, `CustomerPortalQuoteContractAcceptanceTest` 2 / 60, two Contract-focused `CustomerPortalCommercialEconomyTest` methods 2 / 26, `ContractPricingTest` 8 / 31, and four CloudFactory Contract-boundary tests 4 / 53. Final PDF evidence and all still-open human checks are recorded in `HR-2026-08-24-003`. |
| Default Permission And Role Seeder Reconciliation | Done | Junie | The 2026-08-21 Mail provider deployment repair proved that a full `RoleSeeder` sync would remove 17 current Admin and 4 current Tech grants owned by Calendar/Sales defaults. Reconciled in HR-2026-08-25-002. |
| Email Pre-Existing Unreferenced Private-Storage Inventory | Ready | Operations / Svein | The latest 2026-08-24 12:47 CEST read-only inventory inspected 1,445 Dev files without mutation: 968 referenced and 477 unreferenced. It reports 28 missing `message_raw` references, 79 non-private `0644` files, 15 duplicate unreferenced checksum+size groups, and zero unsafe or unreadable files. Duplicate groups and unreferenced status do not authorize deletion; investigate send reconciliation, missing raw evidence, legacy sources/account-2 copies, provenance, retention/Ticket/legal-hold/backup relevance, and age before any separately approved mutation. No file was deleted, moved, copied, or rewritten during recovery or inventory. |
| Mail Scheduled All-Account Polling And Folder-State Recovery | In Progress / Targeted Runtime Active | Svein / Codex | The Dev crontab now runs one overlap-safe `email:poll --scheduled` dispatch and one database `email,default` worker every minute; both active Integration-bound accounts have completed repeated polling without the former folder-handle errors. The shared Laravel scheduler is not running, so scheduled provider reconciliation, health, remote-operation recovery, and notification dispatch are not automatically active. Do not enable the full scheduler until its 136-job notification backlog and all other due jobs receive explicit operator review. The exact historical failed IMAP job remains preserved under `HR-2026-08-15-003` without blind retry/deletion. |
| Mail Integration-Owned Provider Credentials And Endpoint Security | Done / Human Review Rework Needed | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-integration-provider-credentials-endpoint-security.md`. Dev migration `2026_08_21_100000` ran in batch 121 and repaired all eight permission entries plus only the intended Admin/Superuser grants additively; no seeder ran. Both Dev Email accounts now use Integration-owned credentials, provider binding version 2, active polling, and the approved exact RFC1918 `/32` group `tronderdata_mail_dev`. Production is untouched: upgraded installations must run this additive permission migration when the code is promoted, and must never substitute a full `RoleSeeder`. Legacy-secret purge and broad Telescope deletion remain unauthorized. Authenticated provider/browser review remains open under `HR-2026-08-16-006`. |
| Mail Full Provider-Originated Reconciliation | Done / Runtime Operations In Review | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-provider-originated-reconciliation.md`: bounded resumable read-only all-folder/UID/flag/import cycles, hidden-and-attested message birth, stable negative evidence, mailbox-local NOMODSEQ verification, exact move/copy/operation reconciliation, personal-state-safe confirmed moves, deferred live-Inbox automation, durable bounded notification fan-out and external-delivery evidence, intent/lease-safe cancellation, bounded summaries/recovery, optional IDLE hints, and paged scheduled all-account correctness. The final focused SQLite bundle passes 255 / 2,225, the standalone durability gate passes 34 / 468, rolling unread-schema compatibility passes 4 / 26, the existing private MariaDB guard/path/Integration matrix passes 3 / 434, and the final MariaDB `118500` contract passes 3 / 163. The clean 2026-08-24 complete Email Feature directory passes 686 / 7,046, including the 500-folder boundary and truthful child-progress regressions, without weakening production guards. Controlled account-2 run 2 finished terminal `stale` in `summary`: 137 folders, 131 complete and 6 stale with `provider_tuple_drift`; 8,427 observations; 7 confirmed missing; and 0 moves, conflicts or errors. Provider-wins local projection hid seven placements and soft-deleted caches where no active placement survived. Three pending observations belong only to the six stale folders. No provider write or notification delivery occurred. The exact 20 Order-1-through-7 migrations `100000` through `118500` ran one per step in Dev batches 98 through 117. The inbound Ticket-message repair cursor completed through ID 363 in four pages. Both the historical IMAP failure and run 1's understood 100-folder-cap reconciliation failure remain preserved without retry/deletion. Browser/full-scheduler/notification-worker smoke remains operator-gated; Svein's 2026-08-19 review history is preserved, and `HR-2026-08-16-007` was reopened on 2026-08-24 for the folder-cap/progress/runtime changes and current checks. This is not a complete repository-suite claim. |
| Mail Notification Backlog And Worker Activation | Blocked / Human Delivery Decision Required | Svein / Operations | Dev has 136 unreserved `ProcessInboundEmailNotificationFanout` jobs, all with zero attempts, and no `notifications` database worker. Existing settings enable Web Push for both relevant notification types, so starting the worker or full scheduler could deliver a historical burst. No preference was changed and no notification was delivered during Mail completion. Review the bounded cohort and explicitly choose delivery, suppression, or another approved disposition before enabling that worker. |
| Dev Recovery Incident August 15–21 Evidence Reconciliation | Ready / Human Disposition Open | Svein | The 2026-08-21 Dev recovery restored the complete August 15 backup and applied forward migrations. Any Dev-only changes from August 15 through the accidental reset remain a possible loss window until a named human compares external/provider/project evidence and records the disposition in `HR-2026-08-21-001`. Runtime-resume approval does not close this evidence review. |
| Mail Private Live Invalidation And Polling Fallback | Static Publisher Rework Implemented / Runtime Disabled | Svein / Codex | The 2026-08-24 rework freezes source fanout evidence, bounds recovery/catch-up, requires signed acknowledgements, and keeps exact Reverb/CSP plus operations gates. Forward repair `2026_08_24_120000_repair_email_live_publisher_state_transitions.php` ran in Dev batch 125 with all gates false; four replacement UPDATE guards are present and its new ledgers remain empty. SQLite, actual disposable MariaDB, `npm run build`, and the final 686-test Email Feature suite pass. Keep `EMAIL_LIVE_ENABLED`, `EMAIL_LIVE_RUNTIME_APPROVED`, `VITE_EMAIL_LIVE_ENABLED`, `EMAIL_MAIL_COLLABORATION_ENABLED`, `EMAIL_MAIL_COLLABORATION_UI_ENABLED`, and `EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED` false. Authority writer/recompute and bounded current-page projection work plus supervised Reverb/Apache/CSP/worker/scheduler/browser review remain open under `HR-2026-08-16-008`. |
| Mail Presence, Shared Draft Fencing And Stale Composer | Backend/API Safety Rework Implemented / Runtime And UI Gated | Svein / Codex | Order 9 has default-off cache presence, explicit private-to-shared scope, exact shared-mailbox authorization, 60-second lease/fence/content guards, stale-source rebase, safe attachment handling, `423` conflicts, and Order 11 once-only send reuse. The legacy `MailWorkspace` SQL-lock/presence/whisper activation path is removed; workspace collaboration now requires private-live readiness, the separate UI flag, and `EmailCollaborationGate`. Forward migration `2026_08_24_125000` ran in Dev batch 126 after `110000`; the one legacy draft remains private, all shared lock/event tables are empty, and collaboration plus UI gates remain false. A fresh default-off asset build selects `app-DjAfqa_z.js` with no legacy presence/whisper markers. Disposable MariaDB and final Email Feature coverage pass. Complete accessible UI/transport, Redis/Reverb-loss and two-user/two-tab review `HR-2026-08-16-009` before activation. |
| Mail Conversation Acknowledgement And Explicit Selected Accounts | Safety Rework Implemented / Default-Off; Full Slice Gated | Svein / Codex | Order 12 freezes exact placement/UID/epoch/provider-binding previews, reauthorizes each item, coalesces one personal effect per account/message/epoch/target, and keeps provider Seen per placement through the existing remote-operation ledger. Additive migration `2026_08_24_140000` ran in Dev batch 128; its run/item ledgers are empty, inert historical `150000` is unchanged, and `EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED=false`. Disposable MariaDB and final Email Feature coverage pass. Accessible Livewire/API confirmation, continuation/retry/cancel, broader draft-safe actions, Order 8/9 integration and `HR-2026-08-16-012` remain open. |
| Mail Desktop Workspace Density And Height Polish | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-desktop-workspace-density-height-polish.md`: the Smart Inbox button is again above the reader while its single controlled result region remains after the complete conversation; center-list parent/child rows show only the signed-in technician's personal Unread badge; desktop rows are denser; and equal-height list/reader panes consume the available viewport before independent scrolling. The stacked layout below 1200 px is unchanged. Focused coverage passes 20 / 337, `EmailModuleTest` plus supervised cleanup passes 153 / 1,408, and the complete Email directory passes 349 / 3,066. Human review `HR-2026-08-15-007` is Pending. |
| Mail Inbound Attachment Recovery And Download | Rework Needed / Canonical Evidence Review Open | Svein / Codex | Current direct readback finds 32 of 34 expected attachment parts physically present on their exact source rows. Provider reconciliation confirmed `EmailMessage 479` absent from its source placement, hid placement `478`, and soft-deleted that local cache; it still has no raw or attachment files. Same-identity `EmailMessage 650` remains active in Trash with independent raw plus two attachments and is not substituted automatically. Messages `456` and `478` each retain one attachment row/file but lack raw snapshots. All legacy/unreferenced evidence remains preserved and no file was copied, deleted, moved, or rewritten. Controlled browser/access and canonical reconciliation review remain open under `HR-2026-08-15-006`. |
| Mail Smart Inbox Reader-First Review Polish | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-smart-inbox-reader-first-polish.md`: useful results remain after the selected mail body and start closed, while the follow-up desktop-polish slice restores one accessible trigger above the reader without duplicating eligibility/query ownership; terminal and currently unavailable pending actions disappear while applied history and durable audit remain; recorded-agent/user/mailbox/target eligibility is checked for presentation and again at action time; and schema-v2 fingerprints ignore unrelated bookkeeping timestamps while legacy rows use their recorded schema. Human reviews `HR-2026-08-15-005` and `HR-2026-08-15-007` are Pending. |
| Email Private Storage Cross-User Permissions | In Progress | Operations / Svein / Codex | `EmailPrivateStorage` limits writes to normalized `email/*` paths; the latest 2026-08-24 12:47 CEST read-only Dev snapshot has 1,445 files, 968 referenced and 477 unreferenced. The structural directory/ACL contract remains intact, but 79 `www-data`-owned legacy files are still non-private `0644` and the SSH project user cannot chmod them. A root/operator must change only those 79 modes to `0660` without content, ownership, move, or deletion, then rerun inventory and PHP-FPM/queue dual-runtime smoke. Investigate 28 missing raw references and 15 duplicate groups separately; zero unsafe/unreadable files were found and no deletion is authorized. Keep `HR-2026-08-15-003` Pending and preserve the exact historical failed job. |
| Mail Runtime Reliability Hardening | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-runtime-reliability-hardening.md`: deterministic special-folder targets, exact UID preflight, truthful pre/post-SMTP evidence, fail-closed Sent/Draft handling, verified private writes, bounded Draft refresh, and honest UI state. Controlled Dev repair cancelled operation `23`, hid stale placement `474`, projected verified Trash UID `30177` as placement `485`, repaired the child role, and reconciled draft `1` without SMTP or MOVE writes. Polling now repeatedly succeeds for both Integration-bound accounts. The final Email Feature suite passes 686 / 7,046, Unit passes 58 / 694, Notification Feature passes 110 / 1,030, and the latest Ticket regression passes 125 / 934. The latest 1,445-file inventory still reports 79 non-private files, so root/operator mode-only normalization plus dual-runtime smoke remains under `HR-2026-08-15-003`. |
| Mail Folder Hierarchy And Subject Readability | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-folder-hierarchy-subject-readability.md`: normal Mail navigation now shows each authorized provider folder as an account-isolated expandable parent/child tree, defaults branches closed, remembers each technician's explicit open/close state in Email-owned folder preference rows, keeps non-selectable containers structural, selects exact physical folders, labels folder-local mailbox unread counts, and renders common or truncated RFC 2047 subjects readably throughout Mail and Reply/Forward presentation without rewriting identity-bearing stored data. Human review `HR-2026-08-15-002` is pending. |
| Mail Decoded Subject Search Compatibility | Done | Svein / Codex | The decoded `subject_search` projection/search surfaces remain intact and the discovered MariaDB receipt-timestamp defect is repaired. Migrations `121000`, `121100`, and `121200` ran after recovery in Dev batches 95, 96, and 97. The final migration removed implicit `ON UPDATE CURRENT_TIMESTAMP` and froze 490 audit candidates; preview/apply restored the 471 evidence-supported timestamps (439 header dates, 32 conversation boundaries), left 19 unresolved candidates untouched, and recovered exactly five falsely stale Smart Inbox suggestions. The schema reports empty `EXTRA`; receipt-repair plus adjacent regressions pass 36 / 408. Manual search, timestamp-candidate, and rollout checks remain Pending under `HR-2026-08-15-004`. |
| Mail Selected Conversation List Expansion | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-selected-conversation-list-expansion.md`: selecting a conversation now opens one indented, newest-first list of its authorized exact mailbox placements beneath the parent row; child clicks synchronize with the threaded reader, filter-scoped and full-conversation counts remain explicit, and unselected rows add no child query. Human review `HR-2026-08-15-001` is pending. |
| Mail Provider Deletion Reconciliation | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-provider-deletion-reconciliation.md`: stable bounded inventories, fail-closed UID/cursor evidence, seven-day hidden tombstones, conservative move/reappearance handling, retention/Ticket protections, idempotent Mail/Smart Inbox cleanup, daily dispatch, and an exact Admin opt-in that remains off by default. Migration `115000` ran in batch 93. Human review `HR-2026-08-14-015` is pending and the setting must remain off until controlled review. |
| Mail Supervised Smart Inbox Cleanup | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-supervised-smart-inbox-cleanup.md`: explicit reversible Archive/Move suggestions through the normal remote ledger and verified Undo, exact source placement/UID/version checks, cleanup-only max-50 batches with one reservation per source and per-item reauthorization/results, unchanged Seen/personal unread, honest provider status, no-write personal prefill, one-use opaque inactive Admin prefill, and distinct provider rule actions that preserve legacy local `archive`. Human review `HR-2026-08-14-014` is pending. |
| Mail Reviewed Smart Inbox Actions | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-ai-reviewed-conversation-actions.md`: explicit human application is limited to existing active Email category, existing active tag, or an editable internal Task through guarded domain actions; exact source/agent/scope authority is rechecked and applied references/events are idempotent. Human review `HR-2026-08-14-013` is pending. |
| Mail Durable Smart Inbox Suggestions | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-smart-inbox-suggestion-foundation.md`: user/account/conversation/source-bound typed suggestions, append-only events, explicit manual analysis, post-provider authorization recheck, safe provider errors, terminal-state access revocation, preserved audit references, a selected-conversation Livewire review queue, and scoped queue/count/show/analyze/dismiss/correct API. Migration `114000` ran in batch 92. Human review `HR-2026-08-14-012` is pending. |
| Mail Verified Remote Operation Undo | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-verified-remote-operation-undo.md`: immutable exact result snapshots, unique source/inverse linkage, 15-minute recent window, execution-time local/provider verification, no-write stale/revoked/ambiguous blocking, normal ledger/recovery for every inverse, shared UI/API actions, and hidden-404 account scope. Permanent delete, bulk undo, and folder mutation undo remain excluded. Human review `HR-2026-08-14-011` is pending. |
| Mail Fail-Safe Retention Purge | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-fail-safe-retention-purge.md`: one eligibility service now protects provider placements, unresolved operations/reconciliation, Ticket evidence, recognized holds, and unsupported storage; the scheduled orphan purge records sanitized durable run/attempt evidence, and Email Admin shows a read-only cutoff/count/reason preview. Full legal-hold/DSAR/offboarding remains separately scoped lifecycle work; provider-deletion inventory confirmation is now completed under its default-off reconciliation slice and `HR-2026-08-14-015`. Human review `HR-2026-08-14-009` is pending. |
| Mail Remote Operation Recovery | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-remote-operation-recovery.md`: immutable sanitized provider-attempt evidence, execution-time requester authorization and stale identity guards, ambiguous-outcome reconciliation without blind replay, authoritative target-folder evidence for moves, fail-closed provider inventory errors, mutation-only attempt budgets, bounded scheduled retries, shared race-safe retry/cancel actions, scoped recovery API, richer Mail dashboard evidence, and durable conversation unread refresh. Verified inverse/undo is completed in its follow-up slice. Human review is `HR-2026-08-14-010`. |
| Mail Conversation Identity Hardening | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-conversation-identity-hardening.md`: nested RFC replies stay together, reused identifiers fail closed, only unambiguous existing projections reconcile, and Smart Inbox suggestion/event references preserve old audit shells. Migration `105000` ran in batch 86; 462 placements remain linked across 139 conversations after two safe moves. Focused coverage passes 7 tests / 50 assertions. Human review `HR-2026-08-14-007` is pending. |
| Mail Conversation Taxonomy Classification | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-conversation-taxonomy-classification.md`: one account-scoped durable conversation owns Email category/tags while legacy message routing tags, provider state, Ticket classification, cross-account isolation, and ambiguous migration evidence remain intact. Migration `110000` ran in batch 87; focused coverage passes 11 / 90 and Inbound Automation 14 / 81. Human review `HR-2026-08-14-008` is pending. |
| Mail Composer Local Status Polish | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-composer-local-status-polish.md`: AI apply/no-reply/unavailable results and open-composer draft save/restore/provider sync/attachment messages now render inside the shared composer while send/discard and non-composer actions keep page-level Mail feedback. |
| Mail Durable Account Conversations | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-durable-account-conversations.md`: Email now persists account-scoped conversation rows, backfills mailbox placements and Ticket conversation links, projects conversations on inbound storage and provider moves, and keeps the existing `/tech/mail` conversation UI behavior. The conversation-scoped Taxonomy follow-up is now complete under `docs/feature-slices/2026-08-14-email-mail-conversation-taxonomy-classification.md`; later extensions and restricted automatic external replies remain separate dependency-gated work whose implementation approval is recorded in the 2026-08-16 completion index. |
| Mail Composer AI Consistency | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-composer-ai-consistency.md`: the shared `/tech/mail` composer now exposes AI rewrite controls consistently for Compose, Reply, Reply All, and Forward when policy allows it; Draft reply remains Reply/Reply All only, and Forward preserves the original forwarded-message block. |
| Mail Conversation Reader Polish | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-conversation-reader-polish.md`: `/tech/mail` renders the current account-scoped conversation as a compact reader thread, expands only the selected placement, and keeps command-bar actions scoped to that selected provider placement. The durable conversation projection, identity hardening, database-backed list pagination, account-isolated reader loading, and conversation Taxonomy follow-ups are now complete. |
| Beta completion | In Progress | Svein / Codex | Finish and harden existing modules before starting large new domains. |
| Mail Full Client, Personal Mailboxes, Rules And AI | In Progress | Svein / Codex | Svein approved Level 3 RFC `docs/rfc/2026-07-04-mail-module-full-email-client.md` and accepted its four 2026-08-11 Email ADRs on 2026-08-12. Feature Slice 1 `docs/feature-slices/2026-08-12-email-mailbox-access-foundation.md` is implemented with mailbox kinds, owner isolation, explicit grants, scoped Inbox UI/API, notification filtering, account-scoped rules, and personal no-Ticket-ingress. Feature Slice 2 `docs/feature-slices/2026-08-12-email-server-authoritative-folders-placements.md` adds provider folders, mailbox placements, folder baselines, safe multi-folder polling, Inbox-only legacy automation, and an idempotent remote-operation ledger. Admin config cleanup `docs/feature-slices/2026-08-12-email-admin-sync-cache-settings-clarity.md` makes sync/cache settings clear, keeps normal IMAP mail on the provider by default, and moves gateway-authentication trust into Advanced Automation Trust. Rule/API foundation `docs/feature-slices/2026-08-12-email-deterministic-rule-versions-api-foundation.md` adds published rule snapshots, idempotent execution attempts, and read-only rule list/show/preview API. Livewire Mail workspace and personal state `docs/feature-slices/2026-08-12-email-livewire-mail-workspace-personal-state.md` adds `/tech/mail`, account/folder/search/reading panes, explicit `Unread for me`, opened receipts, and personal read/unread actions while leaving legacy `/tech/inbox` intact. Provider mailbox actions and API `docs/feature-slices/2026-08-12-email-provider-mailbox-actions-api.md` adds explicit IMAP Seen/Unseen, Flag/Unflag, Archive, and Trash actions with shared UI/API authorization and remote-operation acknowledgement. Mail reply composer `docs/feature-slices/2026-08-12-email-mail-reply-compose-attachments.md` adds Reply from `/tech/mail` with To, Cc, attachments, threading headers, mailbox Send authorization, and idempotent outbound Email logs. Mail forward and rich HTML composer `docs/feature-slices/2026-08-12-email-mail-forward-rich-html-composer.md` adds Forward, shared rich Reply/Forward editor controls, HTML source mode, sanitized outbound HTML, and Forward logs without auto-reattaching original inbound attachments. Mail command bar triage `docs/feature-slices/2026-08-12-email-mail-command-bar-triage-actions.md` makes Mark read personal-only in the main bar, moves provider read/flag/archive to More, and adds compact Spam/Ticket/Trash icon actions backed by existing Email/Ticket actions. Mail taxonomy classification `docs/feature-slices/2026-08-12-email-mail-taxonomy-classification.md` keeps provider flagging separate and adds visible flag styling plus Email-owned category and multi-tag assignment using existing Taxonomy definitions. Mail Reply All/new compose `docs/feature-slices/2026-08-12-email-mail-reply-all-new-compose.md` adds Reply All recipient defaults/threading and a new-message composer using send-authorized accounts without broadening mailbox read access. Mail Move-to-folder `docs/feature-slices/2026-08-12-email-mail-move-to-folder.md` adds arbitrary same-account selectable-folder moves through the provider operation ledger and API. Personal simple rules `docs/feature-slices/2026-08-12-email-mail-personal-simple-rules.md` adds per-message rule history, owner-scoped safe personal rule creation, personal rule execution for personal no-Ticket-ingress mail, and Admin builder redirects for shared/system rule managers. Mail AI summary `docs/feature-slices/2026-08-12-email-mail-ai-summary.md` adds a read-only governed AI summary/action-extraction panel from `/tech/mail` through the selected Email agent or global fallback agent, excluding raw source, HTML, attachment content, attachment filenames, and all write actions. Mail AI reply drafting `docs/feature-slices/2026-08-12-email-mail-ai-reply-drafting.md` adds governed Reply/Reply All composer drafting, improve, shorten, warmer tone, and Norwegian rewrite controls where sendable output replaces only the composer body, no-reply recommendations stay advisory, and sending remains manual; it also expands `common_settings.value` to long text so Email settings can save full MIME/trust/AI settings, lets admins choose the Default Email agent directly, clears the legacy structured workload override on save, and otherwise falls back to the global default agent for manual non-writing Mail AI. Integration standard AI activation `docs/feature-slices/2026-08-12-integration-standard-ai-activation.md` adds a compact AI Settings activation path that records installation/provider/model governance for normal user-triggered Mail AI after admin confirmation, while keeping advanced Privacy & Coordinator governance available. Mail signatures `docs/feature-slices/2026-08-13-email-mail-signatures.md` adds Mail-owned technician signatures on profile, keeps the page AI chat first in the Mail rightbar, and shows the signature plus Mail AI runtime cards collapsed below it, appends them in the send pipeline, and keeps AI drafting scoped to the message body. Mail local drafts `docs/feature-slices/2026-08-13-email-mail-drafts-autosave.md` adds user-scoped Nexum drafts/autosave for Compose, Reply, Reply All, and Forward, marks sent/discarded drafts, and provides the local lifecycle used by provider Drafts sync. Mail Sent reconciliation foundation `docs/feature-slices/2026-08-13-email-mail-sent-reconciliation-foundation.md` records pending provider Sent reconciliation after SMTP success and marks matching same-account Sent-folder imports reconciled by normalized `Message-ID`. Mail provider Drafts visibility `docs/feature-slices/2026-08-13-email-mail-provider-drafts-visibility.md` shows imported provider Drafts-folder placements with a Drafts view/filter and draft badges while hiding ordinary Reply/Forward/Ticket/rule actions for those provider drafts. Mail provider Drafts write sync `docs/feature-slices/2026-08-13-email-mail-provider-drafts-write-sync.md` records provider Drafts sync evidence on local composer drafts, appends manual Save draft content to the real provider Drafts folder, keeps autosave local-only, and best-effort deletes provider copies after Send or Discard. Mail durable draft attachments `docs/feature-slices/2026-08-13-email-mail-durable-draft-attachments.md` stores local draft attachments durably, restores them in the composer, includes them in SMTP sends and provider Drafts append, and cleans them up after Send or Discard. Mail provider folder create `docs/feature-slices/2026-08-13-email-mail-provider-folder-create.md` lets organize-authorized technicians create a custom provider folder from the Mail sidebar after selecting one mailbox, then projects the IMAP-created folder locally. Mail provider folder rename/delete `docs/feature-slices/2026-08-13-email-mail-provider-folder-rename-delete.md` adds a Folders-header gear manager for the selected organize-authorized mailbox, mirrors custom folder rename/delete to IMAP through the remote-operation ledger, and requires mail to be moved before folder delete. Direct provider Drafts placement editing `docs/feature-slices/2026-08-13-email-mail-provider-drafts-direct-editing.md`, provider Sent append/deduplication support `docs/feature-slices/2026-08-13-email-mail-provider-sent-append-support.md`, grouped shared rule builder/reprocessing `docs/feature-slices/2026-08-13-email-mail-grouped-rules-reprocessing.md`, multi-conversation Ticket links `docs/feature-slices/2026-08-13-email-mail-ticket-conversation-links.md`, remote operation retry dashboard `docs/feature-slices/2026-08-13-email-mail-remote-operation-retry-dashboard.md`, first write-gated AI assistant `docs/feature-slices/2026-08-13-email-mail-ai-write-gated-assistants.md`, manual Mail send/receive plus folder refresh `docs/feature-slices/2026-08-14-email-mail-manual-send-receive-refresh.md`, and conversation list grouping `docs/feature-slices/2026-08-14-email-mail-conversation-list-grouping.md` are implemented. Durable conversation identity/classification, recovery and verified Undo, fail-safe retention, the Smart Inbox foundation/review queue, reviewed category/tag/Task actions, supervised reversible cleanup, default-off provider-deletion reconciliation, and selected-conversation list expansion are now implemented under their approved Feature Slices and ready for pending human review. All 51 Mail-parent Feature Slices that existed before the 2026-08-16 completion audit are implemented; deferred and later target capabilities had not yet been represented as slices and are now ordered in `docs/plans/2026-08-16-email-mail-completion-slice-index.md`. The complete Email module regression passes 141 tests / 1,227 assertions; focused conversation-query coverage passes 6 / 81. Restricted automatic external replies remain unimplemented, but Svein's explicit 2026-08-16 instruction to take every remaining slice, including work not previously approved, supplies the separate product approval. The capability remains default-off and cannot be enabled until its slice, architecture/security gates, tests, operational checks, and named human review pass. |
| Sales Quotes / CPQ Completion | Done | Svein / Codex | GitHub Discussion #170 Sales-owned completion is implemented on Dev under approved RFC `docs/rfc/2026-08-11-sales-cpq-completion.md`, ADR `docs/adr/2026-08-11-sales-cpq-accepted-snapshot-boundary.md`, and feature slice `docs/feature-slices/2026-08-11-sales-cpq-core.md`. Delivered scope includes customer-selectable option groups, required/recommended/default selections, quantity bounds, quote/line acknowledgements, immutable accepted snapshots, configurable approval policy and Admin settings, reusable quote templates/bundles, sent-quote superseding when scope changes before acceptance, separate additional Ticket quotes for quote-required scope added after acceptance, permissioned accepted-quote voiding with safe reversal, Ticket `Add cost/item` routing to planned scope for quote-required Storage items or threshold-triggered costs, accepted Ticket quote auto-processing to safe reservations, draft purchase needs, or pending Ticket costs, public and Customer Portal accept/decline/expiry/history behavior, lifecycle activities, conversion-plan status/reference tracking, Sales, Ticket, and Storage Knowledge updates, Dev migrations `[67]`, `[68]`, and `[69]`, Sales tests `25 / 418`, Ticket tests `122 / 903`, Ticket Workflow tests `25 / 278`, and Storage tests `23 / 363`. Human review is `HR-2026-08-11-004`. Sales conversion plans for non-Ticket downstream domains remain owner-controlled and do not silently mutate Economy, Commercial, Asset, Task, ServiceVisit, or future Project records. |
| Simplified And Automatic Storage Supplier Order AI | Ready for Review | Svein / Codex | The approved original RFC/ADR plus operational RFC `docs/rfc/2026-08-11-operational-supplier-order-automation-setup.md` and bootstrap RFC/slice `docs/rfc/2026-08-11-automatic-ai-supplier-profile-bootstrap.md` / `docs/feature-slices/2026-08-11-storage-automatic-ai-profile-bootstrap.md` remove manual workload/user setup and technical controls from the ordinary form. Administrators choose order handling, warehouse, Supplier/Item behavior, AI use, one Storage agent, business limits, and notifications. A trusted valid email without a profile can now use a Storage-owned candidate contract to create one active Supplier, protected reusable profile, active/orderable Items, and an editable ordered Purchase Order; retries and close first messages reuse identities, and only Receiving changes stock. Dev policy revision 11 is automatic profile-or-AI with fallback agent 5 on standard `gpt-5.5`, warehouse 2, one-sample verified activation, active Supplier/Item creation, max 250 new Items, and green readiness. A rolled-back real-provider bootstrap passed in about 21 seconds with zero receipt/inventory deltas. Focused verification passes 94 / 971; the full Storage suite passes 257 / 2,775 with one expected skipped opt-in MariaDB contract. Human review `HR-2026-08-10-003` remains In Review. |
| Storage Incoming Purchase Quantity Visibility | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-10-storage-incoming-purchase-quantity-visibility.md` adds one sortable Inventory **Incoming** quantity derived from positive outstanding lines on active ordered/partially received Purchase Orders. The row shows **On order** while incoming quantity is positive; drafts, received/closed/cancelled/deleted orders and received/cancelled line quantities do not inflate it. Storage-only users see the aggregate without gaining Purchase Order identity or actions. The reported item `IMP-18-1324745-5BA90BDA` now projects 1 incoming from `AUTO-2026-00000011`. Focused Storage verification passes 23 tests / 360 assertions; the full Storage suite passes 251 tests / 2,600 assertions with one expected skipped MariaDB contract. No migration, API, permission, queue, scheduler, or frontend build change is required. Human review `HR-2026-08-10-002` remains pending. |
| Storage Unified Supplier Orders List | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-10-storage-unified-supplier-order-list.md` consolidates Supplier Order Imports, Purchase Orders, and Receiving into one permission-aware operational list without changing data, APIs, permissions, receipt posting, or inventory effects. Manual and email-created orders now share the same canonical detail; an authorized email order adds only a sanitized Email Copy card at the bottom after Shipments and Receipt History, while item lines, shipments, tracking, receipts, and actions remain identical. Trusted Authentication, SPF, DKIM, DMARC, and alignment remain internal and are not displayed on either detail page. Focused import UI verification passes with 7 tests / 130 assertions; the full Storage suite passes with 250 tests / 2,588 assertions and one expected skipped MariaDB contract. Knowledge is updated and human review `HR-2026-08-10-001` remains pending. |
| Storage Supplier Email Purchase Order Automation | In Review | Svein / Codex | The approved Level 3 RFC, ADR, nine Feature Slices, migrations `100000`-`113000`, seeders, 17 Nexum Knowledge articles, isolated `supplier-orders` runtime, and locked inbound Email poller/worker are deployed on Dev. Exact inline-forward routing, Email rule 10, Signal rule 2, Itegra profile row 1/version row 7 (version 4), warehouse 2, and policy revision 3 remain active only in `shadow`. The first real forward produced EmailMessage 51, Signal 10, and import 2 with deterministic `shadow_complete`: one unresolved line and no Item, PO, receipt, Movement, or stock write. The original poll missed UID 1447 and stored no authentication headers; both defects are fixed with ordered raw-header parsing plus a persistent forward-only `UIDVALIDITY`/UID baseline that ignores historical unread mail and drains new bursts oldest-first. An intermediate unread-catch-up safety test unintentionally imported 240 historical messages, creating 179 Tickets, 42 Signals, 18 rule logs, and one database-only assignment notification; it sent no outbound email and produced no failed jobs. Polling was contained, corrected, live-verified, and re-enabled, but these accidental rows remain untouched pending explicit cleanup authorization. A bounded mailbox calibration on 2026-08-07 added five inactive draft profiles with six protected real fixtures for Dustin, iFixit, MyTrendyPhone, Ecoengros, and IPC-Computer. It also added a validated but inactive Itegra version 5 candidate with four real fixtures while active version 4 remained unchanged. Nine calibration imports retained 12 reviewed lines in `needs_attention` / `validate`; the guarded transaction created no Vendor, Item, PO, shipment, receipt, Stock Unit, Movement, on-hand, Email rule, Signal, Ticket, queue, or failed-job side effect. NDI is PDF-only, 3DJake lacks normalized line prices, and the available Allnet sample lacks order lines, so no passing profiles were created for those formats. A second ordinary-poll capture, broader Itegra coverage for multi-line orders, quantity above one, and freight or discount variation, verified Plesk trust boundary, least-privilege actor, Item mapping, human review `HR-2026-08-04-003`, external BookStack push, and authenticated browser QA remain open; AI also requires a governed provider smoke if enabled. The approved ninth Feature Slice is implemented on Dev with one shared manual/email PO identity: exact supplier plus supplier order number, database-enforced across soft-deleted history, manual-first vendor confirmation without overwrites or inventory effects, fail-closed material and source-projection conflicts, locked post-confirmation identity, and accessible provenance in the canonical Purchase Orders list. Migration `2026_08_07_100000_add_supplier_order_identity_to_purchase_orders.php` remains in batch 62, and forward-only migration `2026_08_07_101000_add_database_generated_supplier_order_identity_key.php` ran in batch 63. The authoritative guard is a database-generated normalized key plus composite supplier/key unique index; the old hash unique index is removed, while the obsolete nullable hash column remains unindexed for a later reviewed cleanup. Sanitized preflight found one PO, zero populated supplier order numbers, and zero collisions. A live two-connection MariaDB 10.6.23 contract verified raw insert/update rejection, supplier scoping, blank identities, Unicode-consistent normalization, and locking-read race recovery beyond an older REPEATABLE READ snapshot. Focused identity/import tests pass 27 tests / 192 assertions; affected AI/integrity/policy/purchase tests pass 71 / 622; the full Storage suite passes 247 / 2,528; and all remaining application tests pass in bounded groups with 978 / 7,619. The opt-in PHPUnit MariaDB contract is skipped without dedicated credentials, while its equivalent live Dev contract passed. Cache clearing, Blade compilation, Pint, three protected-route HTTP smoke checks, the six-article Storage Knowledge sync, bounded worker/cron check, and zero failed-jobs check pass. Authenticated browser QA and the named human checks in `HR-2026-08-04-003` remain open. |
| Storage Supplier Shipment Confirmation Email Automation | Blocked |  | Shipment-confirmation messages were retained only as future calibration corpus; no shipment-email profile, Email rule, Signal action, shipment, tracking, receipt, or inventory mutation was created. The current supplier-order profile contract handles order confirmations only. Define and approve a separate Level 3 RFC, ADR, and Feature Slices before adding shipment-confirmation routing or mutation. Shipment email processing must never imply physical receipt or update stock automatically. |
| Storage Supplier Order Legacy Identity Hash Cleanup | Planned | Svein / Codex | After `HR-2026-08-04-003` and the rollback window are complete, add a forward migration that removes the now-unindexed `supplier_order_identity_hash` column. The generated `supplier_order_identity_key` and composite supplier/key unique index are authoritative; this cleanup must not weaken or recreate that invariant. |
| Email IMAP Historical Import And UID Re-Baseline | Done / Human Review Pending | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-historical-import-and-uid-rebaseline.md`: explicit `email.mailbox_sync_manage` permission, bounded account/folder/date preview, 31-day window, default 100 and hard 500 caps, durable progress/cancel evidence, exact UIDVALIDITY namespaces, shared provider locks, and fail-closed changed/same-validity re-baseline paths. Import is forward-cursor independent and never derives backlog from unread state or triggers provider writes, Inbox automation, Tickets, Signals, notifications, AI, or personal unread changes. Focus is 21/167; adjacent + inbound/conversation + EmailModule is 231/1,886. Additive migrations `100000`-`102000` ran one per step after recovery in Dev batches 98-100; controlled provider/runtime/browser checks remain Pending under `HR-2026-08-16-001`. |
| Mail Per-User Unread Baselines And Backlog Handover | Done / Human Review Pending | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-per-user-unread-baselines-backlog-handover.md`: current access epochs prevent new grants/delegations from flooding history while post-access mail remains unread independently of provider Seen; personal direct grants and break-glass fail closed; historical import projects insert-only current-epoch read state; and a metadata-only exact-account/user/folder/date/cap preview applies bounded backlog unread without changing provider or other-user state. Focus passes 13/118; full historical+delegation rerun is 30/340; broad Email/delegation/attachment is 171/1,548; and affected Notification/UserManagement/system-actor/Ticket is 157/1,063. Additive migrations `104000`/`105000` ran after recovery in Dev batches 102/103; authenticated browser checks and named review remain Pending under `HR-2026-08-16-003`. |
| Mail Canonical Message Shadow Correlation | Done / Review Record Reconciliation Needed | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-canonical-message-shadow-correlation.md`: bounded, resumable, account-safe local discovery records only versioned hashes, reason codes, counters, and audited immutable review decisions. Initial and final snapshots each fail closed above 64 MiB and the complete run above 256 MiB; content inspection requires current ordinary View for each exact recorded account, and deterministic oversized evidence cannot be confirmed. Focused coverage passes 19/131 and the final independent audit is GO. Additive migration `2026_08_16_110000_create_email_canonical_correlation_shadow.php` ran in Dev batch 104 as part of the one-per-step Order-1-through-7 batches 98 through 117. The summary records Svein's 2026-08-19 approval while older detailed wording still needs human reconciliation; controlled operator/worker and rollback-guard evidence remains open. No provider/data mutation or canonical cutover ran. |
| Mail Canonical Message And Placement Cutover | Done / Human Review Pending | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-canonical-message-placement-cutover.md`: immutable source occurrences, strict full-field/actual-file evidence, reversible source-to-canonical mappings and placement pointers, source-preserving `legacy`/`verify`/`canonical` reads, reviewed complete-clique consolidation, drift dissolution, retention guard, and newest-first rollback. Arbitrarily large accounts use durable whole-account parity attestations with at most 100 placements per request, one source/projection materialized at a time, current-authority continuation after requester offboarding, and a 15-minute fingerprint rechecked at preview/apply. Focused coverage passes 18/702, adjacent affected Mail coverage 91/843, 501/502-placement scope/age/success coverage passes, and final independent audit is GO. Additive migration `2026_08_16_111000` ran after recovery in Dev batch 105 without a cutover run, provider call, or private-file mutation; controlled browser/worker/provider checks remain Pending under `HR-2026-08-16-005`. |
| Mail Deterministic Rules API Completion | Safety Rework Implemented / Full Slice Dependency Gated | Svein / Codex | The 2026-08-24 Order 10 repair removes local-only reversal, makes completed execution attempts immutable, records later actions as `not_run` after a failure, and adds account/token/user-scoped execution/Undo API routes. Only one exact allowlisted provider Archive/Move result can delegate to the verified idempotent remote-operation inverse; mixed, local-only, stale, ambiguous, target-mismatched, and unauthorized requests create no compensation. Focused plus adjacent SQLite coverage passes 99/976. No migration/runtime/provider action was performed. Finish draft editing, bounded preview, durable reprocess item/action ledgers, retry/full-rerun, and complete API/OpenAPI parity only after Orders 8-9 are stable; human review `HR-2026-08-16-010` remains Rework Needed. |
| Mail Compose/Draft/Send/Sent API Parity | Private/API Rework Implemented / Human Review Pending; Shared Collaboration Gated | Svein / Codex | The 2026-08-24 Order 11 repair closes cross-user active-draft lookup, makes ordinary drafts explicitly private, adds opaque HMAC fencing and exact-generation attachment ownership, and routes Livewire plus `/api/v1/email/mailbox` preview/send through one durable version-specific outbound submission before SMTP. Additive migration `2026_08_24_110000` ran in Dev batch 124; its backfill preserved the one existing private draft and the outbound ledger remains empty. Controlled web/API/SMTP/Sent human review remains Pending under `HR-2026-08-16-011`; shared drafts remain default-off. |
| Mail Email/Ticket Conversation Relationship Migration | Safety Rework Implemented / Human Review Pending | Svein / Codex | The 2026-08-24 Order 13 repair adds a frozen preview/apply ledger, exact active-human authorization, bounded Email-queue dispatch, deterministic relationship/provenance/audience/conflict gates, and terminal continuation/final-worker failure evidence. Additive migration `2026_08_24_130000` ran in Dev batch 127 and both ledger tables remain empty; no preview or backfill ran. Disposable data-copy/runtime review remains Pending under `HR-2026-08-16-013`, while Orders 14–15 remain dependency-gated. |
| Storage Purchase Orders, Shipping, And Receiving | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md` and all four linked Feature Slices are implemented, migrated, seeded, documented, and Dev-tested. The workflow covers externally placed supplier orders, multiple shipments/tracking identifiers, the Documentation-owned carrier register, partial accepted/rejected receiving, immutable receipts/reversals, and atomic inventory movements. Human review remains `HR-2026-08-04-001`; automatic vendor ordering and live carrier polling remain out of scope. |
| Storage Inventory Sortable Tables | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-04-storage-inventory-sortable-tables.md` and both Feature Slices are implemented across all eleven read-only Storage queue, Admin, and detail/history surfaces. Sorting is allowlisted, accessible, null-last, filter-preserving, and stable while workflow forms/control slips retain their original order. The complete Storage suite passes with 95 tests / 1,233 assertions and the full Laravel suite with 1,027 / 8,558. Human review remains `HR-2026-08-04-002`; no migration, API, queue, scheduler, or frontend build is required. |
| Composer Dependency Security Advisories | In Review |  | `composer audit --locked` on Dev reported 45 advisories across 15 locked packages on 2026-07-29, including high-severity framework, mail, HTTP, PDF, spreadsheet, and test-tool findings. Plan an approved dependency-hardening change, classify runtime versus development exposure, update within supported constraints, run the complete test/build/audit suite, and document any residual risk. Do not fold a broad framework/package upgrade into an unrelated feature delivery. |
| Web Push And Inbound Email Alerts | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md` and accepted ADR `docs/adr/2026-07-23-notification-owned-web-push-channel.md`. All three feature slices are implemented on Dev: device/channel foundation, inbound Email/customer-reply delivery, and source read-sync rollout hardening. Dev has a cron-managed `email,default` queue worker plus direct `email:poll --account=1`; the full Laravel scheduler runner remains a separate Operations concern. Production enablement still requires named human browser/device and end-to-end checks in `HR-2026-07-24-001` and `HR-2026-08-11-002`. |
| One Responsive Nexum PWA Final Browser Acceptance | Blocked | Svein / Codex | GitHub Discussion #169's foundation and Notification/Web Push slices are implemented and automated source-contract tests now guard PWA metadata, viewport tags, mobile offcanvas shell, and the shared online-first service worker. Final closure is blocked until the new trusted HTTPS Dev vhost is available and the named human checks in `HR-2026-08-11-003`, `HR-2026-07-24-001`, and `HR-2026-08-11-002` pass. Use `docs/deployment/dev-https-pwa-vhost.md` for the vhost checklist. |
| AI Model Usage And Cost Telemetry | Done | Junie | Slices 1-3 (Ledger, Call-path migration, Rate Cards) and Slice 4 (Admin UI) are implemented. All AI calls in Lead Intelligence and Nextcloud are migrated to the telemetry contract. Decimal-precision cost calculation via versioned rate cards is active. Admin UI standardized across all AI-related pages. Human review updated to `HR-2026-08-25-006`. |
| Dev Queue And Scheduler Runtime | Blocked | Operations | Supplier Order Automation now has its own isolated `/var/Projects/tdPSA` crontab runtime with a locked `supplier-orders` database worker and dedicated dispatch, heartbeat, health, retention, and digest jobs. This removes the runtime prerequisite only for the supplier-order shadow rollout. A general authoritative Dev queue/scheduler runtime for Web Push and ordinary Email/notification scheduling remains unverified, so this cross-application Operations item stays `Blocked` and must not be marked `Done` from the supplier-specific runtime alone. |
| CloudFactory Partner Integration | Ready for Live Validation | Svein / Codex | Core integration and the versioned legal-document/Customer-admin portal-ordering slice are implemented under approved RFC `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`. Provider documents are immutable and read-only, Nexum terms remain additive, contracts and portal writes retain versioned acceptance evidence, and monthly catalogue sync performs the legal check. Remaining gates are the existing production validation in `HR-2026-07-20-001` and the focused Dev legal/portal review in `HR-2026-07-22-001`. |
| Between Competitor Parity Audit | Ready |  | Use `docs/audits/2026-07-03-between-competitor-gap-analysis.md`, `docs/ideas/`, and the 2026-07-04 draft RFC batch as planning input for booking, field-service mobile/PWA, payments/accounting, SMS automation, departments/service areas, resources, and feedback. Do not implement Level 2/3 parity work without approved RFCs. |
| Notification SMS Channel Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-notification-sms-channel-automation.md`; first slice `docs/feature-slices/2026-07-05-notification-sms-dry-run-foundation.md` adds dry-run transactional SMS configuration, templates, audit logs, manual admin test send, and Contact phone consent guards. Production providers and workflow automation remain later slices. |
| One Responsive Nexum PWA Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-one-responsive-nexum-pwa.md`; app-wide PWA metadata, online-first service worker/offline page, mobile tech offcanvas navigation, `/tech/my-day`, UI guidelines, Knowledge docs, Dev smoke checks, focused Dev tests, and Notification-owned inbound Email Web Push/read-sync slices are complete. Offline write queues remain intentionally unavailable unless a future workflow gets an approved conflict/sync design. |
| Public Inquiry Forms Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-public-inquiry-forms.md`; first slice adds Intake-owned public forms, file uploads, admin review, matching, guarded Sales routing, Knowledge docs, Dev migration/seeding, and tests. |
| Intake Signal Post-Submit Automation | Done | Codex | Approved RFC `docs/rfc/2026-07-05-intake-signal-post-submit-automation.md`; Intake now emits post-submit Signal events and Signal rules can trigger Ticket, Task, Portal invitation, Sales follow-up, and webhook actions. |
| Intake Final Routing And Review | Done | Codex | Feature slice `docs/feature-slices/2026-08-11-intake-final-routing-review.md` completes Discussion #166 scope with published/paused form lifecycle, purpose/language/scope metadata, explicit routing modes, form/field snapshots, direct Sales/Ticket/Task routing, manual review outcomes, existing-record linking, Knowledge docs, migration, and focused Intake tests. Human review is `HR-2026-08-11-001`. |
| Email/Ticket Rules Signal Alignment | Done | Codex | Approved RFC `docs/rfc/2026-07-08-email-ticket-signal-rule-alignment.md`; Email Rules and Ticket creation rules can now explicitly emit Signal records, Signal-created tickets skip Ticket Signal handoff to avoid loops, Knowledge sync includes Signal docs, and focused Dev tests passed. |
| Storage Orderable Over-Reservations | Done | Codex | Approved RFC `docs/rfc/2026-07-08-storage-orderable-over-reservations.md`; GitHub issue #177 is implemented so active orderable Storage items can be reserved beyond available stock, not-orderable items keep the available-stock guard, picking remains blocked until stock is on hand, and Knowledge docs/tests were updated. |
| Ticket Storage Reservation Release | Done | Codex | Approved RFC `docs/rfc/2026-07-21-ticket-storage-reservation-release.md`; explicit removal from inside Edit cost and quantity-zero release share one audited transaction that frees reserved stock, removes Picking List work, and restores linked approved planned lines. All affected Dev suites and rendered-view checks passed; human review `HR-2026-07-21-001` remains pending. |
| Customer Portal Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-customer-portal-foundation.md` with ADR `docs/adr/2026-07-04-customer-portal-identity-separation.md`; first slice implemented with portal accounts, memberships, invitations, audit events, portal dashboard, docs, Dev migration/seeding, and tests. Portal invitations now originate from Contact/Contract workflows, while the Customer Portal admin URL is reserved for future portal settings. Customer-visible domain data remains explicit slices. |
| Customer Portal Ticket Workflow | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md`; customer-visible ticket list/create/detail/reply with explicit one-way publishing, manual visibility control, and scope enforcement is implemented. Approved RFC `docs/rfc/2026-07-28-manual-client-ticket-published-default.md` completes GitHub issue #191 by making Published the clean-install fallback while preserving valid admin choices, per-Ticket overrides, internal-Ticket isolation, history, and existing notification behavior. |
| Customer Portal Document Center | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-document-center.md`; explicitly published Documentation records and published public/client-wide Knowledge articles are exposed inside portal scope and Dev-tested. |
| Customer Portal Commercial/Economy Summary | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-commercial-economy-summary.md`; approved/accepted contract summaries and explicitly published economy order summaries are implemented, documented, migrated, and Dev-tested. |
| Customer Portal Quote/Contract Acceptance | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-quote-contract-acceptance.md`; existing Sales quote and Commercial contract acceptance are bound to authenticated portal identity, documented, migrated, and Dev-tested without implementing full CPQ. |
| Customer Portal Notifications | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-notifications.md`; portal notification center, customer-safe notification delivery, preferences, and implemented-domain event emitters are implemented, documented, and Dev-tested under the approved Customer Portal RFC. |
| Online Booking With Calendar Availability | Done | Codex | Approved RFC `docs/rfc/2026-07-04-online-booking-calendar-availability.md`; foundation slice `docs/feature-slices/2026-07-04-online-booking-calendar-availability.md` implements public Calendar-backed requests and staff confirmation. Follow-up slice `docs/feature-slices/2026-07-28-booking-hours-and-technician-routing.md` completes service opening windows, company/technician hours, fixed/automatic/customer-choice routing, Page Header Back, plain-language spam protection, docs, and tests for GitHub issue #184. |
| Client Workspace Tickets Tab | Done | Codex | Approved RFC `docs/rfc/2026-07-28-client-workspace-tickets-tab.md`; GitHub issue #188 adds the permission-aware Client Tickets tab, required tab order, direct Ticket links, matching count, tests, and Knowledge documentation. Human review is `HR-2026-07-28-003`. |
| Client Summary Layout And Notes Autosave | Done | Codex | Approved RFC `docs/rfc/2026-07-28-client-summary-notes-autosave.md`; GitHub issue #189 adds the responsive Summary metadata grid and permission-protected Notes autosave with honest save states, tests, and Knowledge documentation. Human review is `HR-2026-07-28-004`. |
| Telephony Call Intake v1 | Done | Codex | Discussion #49 implemented with personal provider URL, public token intake, caller matching, call notes, ticket creation/linking, tests, and Knowledge docs. |
| Technician profile consolidation | Done | Codex | UserManagement owns `user_profiles`; Ticket now owns only Ticket Assignment Settings. |
| Ticket assignment settings split | Done | Codex | Legacy Ticket technician profile tables migrated into explicit assignment settings. |
| Ticket SLA v1 | Done | Codex | SLA resolution, contract SLA field, ticket show panel, index SLA risk badges, Knowledge docs. |
| Ticket Actions v1 | Done | Codex | Shared action names, guard, apply SLA action, UI gating, Knowledge docs. |
| Ticket Workflow v1 | Done | Codex | Default workflow, states, transitions, runtime validation, Ticket show actions, Knowledge docs. |
| Ticket Workflow Editor v2 | Done | Codex | Admin create/edit workflow metadata, states, transitions, and stored requirements. |
| Ticket Workflow v3 Conditional Actions And Escalation | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`; all eight 2026-07-17 Feature Slices plus the 2026-07-18 customer notification and automatic migration-placement slices are implemented. Workflow supports Signal-style grouped requirements, versioned steps, server-enforced action/API parity, escalation and eligible assignment, senior review/evidence, Ticket-origin Sales quotes, planned scope, controlled fulfilment/Economy closure, Published-only transition notifications, and requirement-based per-Ticket placement when active work is migrated to a new version. Dev migration, focused cross-module tests, Knowledge/BookStack sync, and human review are tracked under `HR-2026-07-17-001`. |
| Ticket Solution Policy | Done | Codex | Approved RFC 2026-06-03 keeps internal solution notes enabled by default and admin-configurable. Follow-up RFC `docs/rfc/2026-07-28-ticket-internal-note-solution-toggle.md` completes GitHub issue #190 by replacing the duplicate composer type with a policy-protected Mark as solution switch that retains technician notification. Human review is `HR-2026-07-28-005`. |
| Ticket Knowledge loop | Done | Codex | Ticket show creates documentation follow-up events and Ticket settings lists the latest requests. |
| AI write tools | Blocked |  | Wait until Ticket Workflow/Action guards are stable enough. |
| Contract SLA UI polish | Done | Codex | Contract index, form wording, show summary, tests, and Knowledge docs updated. |
| Company Theme System | Done | Codex | Branding view now manages light/dark logos, shell surfaces, card headers, and button colors. |
| Reporting Domain Foundation | Done | Codex | Report module owns the hub and registry; Ticket registers the SLA report while keeping its query/detail view. |
| Module Settings Audit | Done | Codex | Audit captured settings ownership gaps, admin discoverability gaps, visible unfinished UI, and legacy planning files. |
| Admin Settings Discoverability Cleanup | Done | Codex | Existing beta-ready settings surfaces are reachable from Admin hub/sidebar and documented. |
| Visible Unfinished UI Cleanup | Done | Codex | Removed beta-visible coming-soon text from Asset/N-able and replaced old login copy/placeholders. |
| Email Health Check Honesty | Done | Codex | Queued email health checks now reuse the real IMAP/SMTP test service instead of writing unconditional OK results. |
| BookStack Scheduled Sync Honesty | Done | Codex | Scheduled BookStack pull/push jobs now mark active misconfigured integrations unhealthy instead of returning silently. |
| BookStack Knowledge API And Sync Hardening | Done | Codex | Approved RFC `docs/rfc/2026-06-16-bookstack-knowledge-api-sync-hardening.md`; implemented API parity for shelves/books/chapters/articles, BookStack pull/push/test/status endpoints, two-way sync diagnostics, tests, and docs. |
| Knowledge Documentations API | Done | Codex | Approved RFC `docs/rfc/2026-06-30-knowledge-documentations-api.md`; Documentation module exposes Knowledge-scoped API routes for Documentation records, documentation categories, and templates. |
| Commercial Settings Route Cleanup | Done | Codex | Contract settings URL now uses `/contracts`; legacy `/contacts` typo redirects to the canonical route. |
| Beta Release Hardening Sweep | Done | Codex | Removed mutating GET routes found in Commercial, made Queue/Worker setup paths environment-aware, and ran all module feature suites. |
| Asset Settings Slice | Done | Codex | Asset module now owns manual registration defaults and admin settings. |
| Contact Settings Slice | Done | Codex | Contact owns defaults and relation types. Follow-up slice `docs/feature-slices/2026-07-28-contact-portal-invitation-override.md` adds the approved global portal-invitation default and create-only per-Contact override for GitHub issue #185. |
| Legacy Planning Files Cleanup | Done | Codex | Moved Markdown planning/spec files out of production view paths and updated runtime doc references. |
| Task Settings Slice | Done | Codex | Task module now owns manual task defaults for status, priority, and estimate. |
| Warroom Settings Slice | Done | Codex | Warroom now owns dashboard windows, list limits, and visible panels. |
| Knowledge Settings Slice | Done | Codex | Knowledge now owns manual article defaults for visibility, status, review, and priority. |
| Risk Settings Slice | Done | Codex | Risk now owns defaults for assessments, item scoring, item status, and review interval. |
| Missing Settings Ownership RFC | Done | Codex | RFC approved; Asset, Contact, Task, Warroom, Knowledge, and Risk settings slices completed. |
| Ticket Customer Completion API | Done | Codex | GitHub issue #194 and approved RFC `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`; API coordinators can publish eligible Tickets, send idempotent customer replies including `send_solution`, inspect decisions, transition to Resolved, and close through existing guarded actions. Dev migration, OpenAPI, Knowledge, regression tests, and human review `HR-2026-07-29-002` are recorded. |
| Organization-controlled AI and coordinator access | In Review | Codex | GitHub issue #178 and approved RFC `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`; least-privilege keys, installation/provider/model/agent/workload policy, privacy gateway enforcement, workload tokens, metadata audit, and minimized worklog/stale APIs are implemented. Controlled provider smoke and human review remain under `HR-2026-07-29-012`. |
| Calendar Ownership View Metadata | Done | Codex | GitHub issue #137 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-view-metadata.md`; Calendar overlays and APIs expose stable calendar-owner/type/group metadata without broadening visibility, and the single-event API now applies private-detail masking. Human review is `HR-2026-07-29-003`. |
| Calendar Owner Badges And Accessible Color | Done | Codex | GitHub issue #138 and Feature Slice `docs/feature-slices/2026-07-29-calendar-owner-badges-accessible-color.md`; day/week/month/list use one privacy-safe owner badge with text, color swatch, accessible owner/type label, truncation, and narrow-screen overflow. Human review is `HR-2026-07-29-004`. |
| Calendar Type Indicators | Done | Codex | GitHub issue #139 and Feature Slice `docs/feature-slices/2026-07-29-calendar-type-indicators.md`; non-personal events use shared accessible type badges in all four views without weakening private masking. Human review is `HR-2026-07-29-005`. |
| Calendar Ownership Filters | Done | Codex | GitHub issue #140 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-filters.md`; server-scoped groups and `Only mine` filter by Calendar owner, preserve navigation/search state, intersect explicit Calendar selections, and expose clear empty states. Human review is `HR-2026-07-29-006`. |
| Calendar Mobile Readability | Done | Codex | GitHub issue #141 and Feature Slice `docs/feature-slices/2026-07-29-calendar-mobile-readability.md`; month/week retain accessible scrolling and dense month days expose a filtered `+N more` drill-down. Human review is `HR-2026-07-29-007`. |
| Calendar Ownership Rollout Tests And Knowledge | Done | Codex | GitHub issue #142 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-rollout-tests-knowledge.md`; regression coverage, Calendar docs, TODO, website handoff, and manual review tracking complete the rollout. Human review is `HR-2026-07-29-008`. |
| Domain API Foundation | Done | Codex | Scoped Sanctum API keys now enforce Client/Site, Custom Fields, Asset, Contact, Marketing, Ticket, Task, Knowledge, Storage, Calendar, Risk, Email Inbox, Notification, Sales, Taxonomy, Commercial, Economy, Report, and User Management API scopes. |
| Work Context / Organization Scope | Done | Codex | Discussion #149 completed through RFC `docs/rfc/2026-07-01-work-context-organization-scope.md`: foundation plus Ticket, Task, Asset, Documentation, Risk, Calendar, Report/API filters, and client-only Commercial/Economy/Sales guardrails are implemented, documented, and tested. Legacy self-client cleanup remains a separate non-goal/future cleanup item. |
| Nexum Relationship / Vendor Provider Routing | Done | Codex | Discussion #150 implemented with Relationship module, signed Nexum-to-Nexum transport, ticket escalation/public reply/status sync, selected attachment sync, documentation/Knowledge sync with conflict review, admin UI, audit logs, documentation, migration, seed updates, and tests. |
| Data Exchange Platform | Done | Codex | Approved RFC `docs/rfc/2026-07-03-data-exchange-platform.md` with ADR `docs/adr/2026-07-03-data-exchange-platform-ownership.md`; v1 profile builder, export/import runtimes, schedules, delivery attempts, API, Clients basic import, and Economy Orders export are implemented. Tripletex/PowerOffice remain future provider-profile slices. |
| Custom Fields Core | Done | Codex | Adds generic definitions/values with Client UI/API value support, Client workspace tab, and read-only definition discovery API for MSP Manager/n8n sync identifiers. |
| Client Contract Timebank Quick Consumption | Done | Codex | Approved RFC 2026-06-08; first slice adds Client Contracts tab timebank bars, audit-backed quick usage modal, permissions, and default Commercial policy. |
| Commercial Timebank Policy Admin UI | Done | Codex | Commercial settings now expose quick Client timebank policy controls backed by `common_settings`. |
| Quick Timebank Overuse Billing Integration | Done | Codex | Quick Client timebank overuse now stores rate snapshots and Economy Generate orders creates draft order lines for overused minutes. |
| Client Time Usage Tab | Done | Codex | Client profile now has a Time tab with quick registration, unified quick/ticket/task time usage history, and source-safe edit actions before Economy ordering. |
| Marketing Domain And Email Campaign Automation | Done | Codex | Approved RFCs 2026-06-09, 2026-06-17, and `docs/rfc/2026-08-24-evergreen-marketing-contact-sequences.md`; domain foundation, Email marketing defaults/templates, mailing lists, Marketing API surface, campaign approval, due sending, dashboard, tracking, campaign email cards, preview/test-send, snapshot auto-fill, AI email draft assist, AI campaign plan assist, campaign-level send rhythm, recipient batch throttling, new-contact schedule policy, multi-list campaign audiences with recipient deduplication, ongoing contact-specific progression, lifetime campaign-email delivery guards, suppression hardening, richer segmentation UI, WordPress content pull as AI/content context, and Sales/Leads marketing engagement context are implemented. Automatic repeat/stop completion behavior is retired; caught-up Contacts remain enrolled and newly appended emails are delivered once. Human review is tracked under `HR-2026-08-24-002`. Separate Marketing engagement/call lists remain intentionally out of scope. |
| Marketing Google And Social Integrations | Future |  | Add provider-backed Google Analytics/Search Console/Ads context and social publishing/import workflows under Integration-owned provider settings. Do not expose controls until each provider workflow is functional and tested. |
| Lead Intelligence / AI Prospecting Foundation | Done | Codex | Approved RFC 2026-06-12; first slice adds settings, segment policy, planned and executable research runs, scan ledger, source evidence, suppression entries, contact marketing eligibility, API abilities, simple admin/tech UI, guarded candidate promotion into Clients, Contacts, and Marketing list members, Run Now, configurable BRREG discovery, shallow website email discovery, AI discovery planning with editable prompt, OpenAI AI-provider web search for candidate URLs, provider-neutral web-search endpoint adapter, Laravel queued execution job, and grounded AI candidate review with editable prompt. Run Now queues an immediate run and dispatches the same Laravel queue job used by scheduled automation. It intentionally does not run deep crawling, hallucinated contact generation, or email sending. Next slice: deeper discovery and richer evidence review UI. |
| Lead Intelligence Schedule Foundation | Done | Codex | Approved RFC 2026-06-12; segments now have schedule period, run time, weekdays, run interval, lead target, token budget or unlimited-token mode, max runs, next/last run tracking, planner command, Laravel queued execution job, description-as-goal-prompt run context, AI segment draft assist, Run Now, and promotion targets through segment Marketing lists. Still no deep crawler, enrichment worker, or email sending. |
| Lead Intelligence Deeper Discovery + AI Enrichment | Future |  | Add controlled multi-page discovery, role/person extraction, AI evidence summarization UI, manual review queue for AI `review` decisions, website/contact confidence scoring, richer BRREG/company filters, and optional provider-backed search. Must keep settings, suppression, scan ledger, and dry-run/review guardrails in front of Marketing promotion. |
| Lead Intelligence AI-Led Discovery Worker | Done | Codex | Worker now uses an editable AI discovery planner, configured source adapters, BRREG, AI-provider or endpoint web-search results, company-specific homepage lookup for registry candidates with missing contact data, shallow website evidence collection, grounded AI candidate review, scan ledger checks, and guarded promotion. AI output cannot create companies, contacts, emails, roles, URLs, or facts unless source evidence exists. |
| Signal Domain Active Automation | Done | Codex | Approved RFC 2026-06-09; active Signal records, rules, execution audit, webhook delivery, UI, protected API ingest, Marketing producer integration, Email bounce/autoreply/unsubscribe/vendor classifiers, settings-controlled AI-assisted classification fallback, configurable rule/action settings, Client/Contact signal history, Sales follow-up action, and Ticket follow-up action are implemented, documented, and tested. |
| Report Builder And Scheduled Client Reporting | Post-Beta |  | Version 2 item. Build custom report builder, saved report templates, and automatic client report delivery. |
| Shared HTML Content Editor | Post-Beta |  | Version 1 item. Build a reusable WordPress-like Bootstrap editor with HTML/source mode, visual drag/drop content blocks, reusable template sections, preview, and safe output for Marketing emails, Email templates, Documentation, Knowledge, and future content surfaces. |
| Storage Barcode Scanning | Post-Beta |  | Version 1 item. Storage must support barcode scanners from PC and mobile workflows. |
| Storage Default Warehouse | Done | Codex | Approved RFC 2026-06-03; Storage now ensures a default Company warehouse and lets admins change it. |
| Ticket Manual Costs | Done | Codex | Approved RFC 2026-06-03; Ticket costs now support manual non-stock entries alongside Storage reservations. |

## Ready To Pick Up

### 1. Technician Profile Completion

**Status:** Done
**Owner:** Codex
**Domain:** UserManagement / Ticket
**Goal:** Finish the unified profile cleanup and remove remaining ambiguity.

Initial scope:

- Keep `/tech/profile` as the canonical technician profile shell.
- Keep UserManagement as owner of name, email, phone numbers, timezone, work hours, availability notes, and profile notes.
- Keep Ticket Assignment Settings limited to assignable state, capacity, ticket category matching, ticket tag matching, and ticket assignment notes.
- Confirm production deploy path after the legacy Ticket profile table cleanup:
  - `php artisan optimize:clear`
  - `php artisan migrate --force`
  - `php artisan user-profiles:backfill`
- Update Knowledge documentation after final UI polish.
- Profile image/avatar upload.
- Personal company default/light/dark/system theme preference after branding.

Future scope:

- Decide whether category/tag matching is ticket-only or should become general skills.

### 2. Company Profile And Branding

**Status:** Done  
**Owner:** Codex  
**Domain:** System / UserManagement / UI  
**Goal:** Add company profile and branding defaults for Nexum PSA.

Initial scope:

- Company name and organization details.
- Logo/header branding.
- Brand colors stored in settings.
- Bootstrap-compatible theme variables.
- Prepare personal light/dark mode after global branding exists.

### 3. Company Theme System

**Status:** Done
**Owner:** Codex
**Domain:** System / UI
**Goal:** Finish branding as a proper theme system.

Initial scope:

- Keep Branding as its own admin view under System.
- Keep brand/action colors separate from layout surface colors.
- Add configurable header background/text, page header background/text, footer background/text, body background, content background, sidebar background/text, card background, and border color.
- Add light theme and dark theme surface sets.
- Let company default theme be `light`, `dark`, or `system`. Done.
- Let technician preference choose `company default`, `light`, `dark`, or `system`. Done.
- Update CSS variables so shell layout never depends on hardcoded brand colors.
- Add tests and Knowledge documentation.

Future scope:

- Full Bootstrap component theming beyond the current shell, card header, and primary/secondary buttons.

### 4. Reporting Domain Foundation

**Status:** Done
**Owner:** Codex
**Domain:** Report / Platform
**Goal:** Create a proper reporting system for cross-domain reports.

Why this is needed:

- `/tech/reports` started as a global placeholder, not a real report module.
- Ticket SLA reporting is currently owned by the Ticket module as a pragmatic beta step.
- Future reports need consistent navigation, permissions, filters, exports, saved views, and ownership.

Initial scope:

- Use `Report` as the domain name unless an RFC decides otherwise.
- Create a report registry where modules can register report entries.
- Let domain modules own their report data/query logic while the Report domain owns the hub, shell, navigation, permissions, filters, and export behavior.
- Move or register the Ticket SLA report through the Report domain.
- Document report ownership rules in architecture docs and Knowledge.

### 5. Module Settings Audit

**Status:** Done
**Owner:** Codex
**Domain:** Platform / All Existing Domains
**Goal:** Audit existing modules for beta-critical settings and hardcoded behavior.

Initial scope:

- Check System, User Management, Clients, Contacts, Tickets, Email, Inbox, Calendar, Notification, Knowledge, Nextcloud, Commercial, Sales, Economy, Storage, Assets, and Tasks.
- Identify behavior that is currently hardcoded but should be configurable.
- Verify settings live in the correct domain and are reachable from Admin or Profile where appropriate.
- Verify defaults exist for clean installs.
- Verify permissions protect settings routes.
- Update `docs/TODO.md` with scoped follow-up items instead of starting large unrelated fixes.
- Update Knowledge documentation when the audit changes documented behavior.

Audit output:

- `docs/audits/2026-06-01-module-settings-audit.md`

### 6. Admin Settings Discoverability Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** System / Admin Navigation
**Goal:** Make existing beta-ready settings surfaces discoverable from the Admin hub and sidebar.

Initial scope:

- Add Calendar settings to Admin landing page and admin side navigation.
- Add Notification channels to Admin landing page.
- Add Nextcloud settings to Admin landing page.
- Add integration-specific links for N-able RMM, Tactical RMM, and BookStack to Admin landing page.
- Add User roles, permissions, and two-factor settings to Admin landing page.
- Add Ticket assignment rules and technician assignment settings to Admin landing page.
- Do not add links to unfinished settings surfaces.
- Add/adjust tests for Admin hub visibility.

### 7. Visible Unfinished UI Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Existing Domains
**Goal:** Remove or implement visible beta UI that advertises unfinished behavior.

Initial scope:

- Remove or replace Asset detail "Feature coming soon" related-ticket text.
- Review N-able RMM "Fetch network equipment (Coming soon)" card and either hide it or implement useful disabled/help behavior.
- Review integration cards for buttons/toggles that expose unfinished functionality.
- Review login branding and old `tdPSA` placeholder under branding/naming cleanup.
- Add tests where behavior changes.

Completed:

- Asset detail now shows a neutral related-ticket empty state without promising unfinished behavior.
- N-able manual sync no longer exposes the network-device coming-soon action.
- Login views now use Bootstrap, current company branding defaults, and neutral email placeholder text.
- Commercial Contract/Service settings routes now render working admin hub pages instead of legacy view specifications.
- Contract create no longer renders appended legacy specification text.
- Sales lead detail now renders a working beta detail page instead of a legacy "not started" specification.
- Email account health check jobs now persist real IMAP/SMTP test results instead of unconditional OK placeholders.
- Scheduled BookStack pull/push jobs now surface missing server/token/actor configuration in integration health.
- Commercial Contract settings route now uses `/tech/admin/settings/cs/contracts`; the old `/contacts` typo redirects.
- Commercial Units creation now uses POST with CSRF instead of a GET route that created database rows.
- Commercial Cost deletion now uses DELETE instead of a GET route.
- Queue and Worker setup examples now render the current Laravel `base_path()` in the UI instead of a hardcoded development path.

### 8. Asset Settings Slice

**Status:** Done
**Owner:** Codex
**Domain:** Asset
**Goal:** Add beta-ready Asset settings for behavior that works immediately.

Completed:

- Asset settings route: `/tech/admin/settings/assets`.
- Settings storage in `common_settings` with `type=asset` and `name=defaults`.
- Admin can configure enabled asset types, default asset type, default IP mode, and default manual status.
- Manual Asset form and HTTP fallback create/update paths use the settings.
- Asset Knowledge documentation added.

### 9. Contact Settings Slice

**Status:** Done
**Owner:** Codex
**Domain:** Contact
**Goal:** Add beta-ready Contact settings for defaults and relation choices.

Completed:

- Contact settings route: `/tech/admin/settings/contacts`.
- Settings storage in `common_settings` with `type=contact` and `name=defaults`.
- Admin can configure default contact type, default status, default relation type, and enabled relation types.
- Contact form and StoreContact action use the settings.
- Duplicate protection remains mandatory.

### 10. Legacy Planning Files Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Documentation
**Goal:** Move planning/specification Markdown out of production view paths.

Initial scope:

- Move `resources/views/tech/tasks/*` planning files into `docs/` or module `Docs/`.
- Move `resources/views/tech/admin/billing/*` planning files into `docs/` or module `Docs/`.
- Move or delete obsolete `app/Modules/*/Views/**/*.md` and `*.blade.md` files.
- Keep production view paths limited to renderable Blade/PHP views.
- Verify route rendering and tests after cleanup.

Completed:

- Moved resource view specs to `docs/legacy/view-specs/resources/views`.
- Moved module view specs to `app/Modules/{Domain}/Docs/legacy-view-specs`.
- Updated runtime documentation file references in Asset, Storage, and Integration views/controllers.
- Verified no `.md` or `.blade.md` files remain under `resources/views` or module `Views` folders.
- Moved remaining task, task-template, and billing view specifications out of `resources/views`.
- Moved remaining Commercial contract and Sales lead module view specifications into module legacy docs.
- Removed empty unused runtime Blade files from Clients, Commercial, Sales, Ticket, and global admin settings paths.

### 11. Missing Settings Ownership RFC

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Existing Domains
**Goal:** Decide settings ownership for active modules that do not yet have clear settings surfaces.

Initial scope:

- Create one RFC covering Asset, Contact, Knowledge, Risk, Task, Warroom, and Report settings ownership.
- Define which settings are beta-critical versus post-beta.
- Decide admin route placement and permission names.
- Define default seed behavior for clean installs.
- Define Knowledge documentation requirements.

Progress:

- RFC created and approved: `docs/rfc/2026-06-01-module-settings-ownership.md`.
- Asset Settings slice completed.
- Contact Settings slice completed.
- Task Settings slice completed.
- Warroom Settings slice completed.
- Knowledge Settings slice completed.
- Risk Settings slice completed.

### 12. Domain API Foundation

**Status:** Done
**Owner:** Codex
**Domain:** API / Platform / All Existing Domains
**Goal:** Define and implement consistent API surfaces for domains that need external integration access.

Why this is needed:

- Nexum PSA has focused heavily on UI workflows, but external integrations, automation, mobile clients, and future AI tooling need stable APIs.
- API ownership, authentication, permissions, versioning, validation, rate limiting, and documentation must be consistent before each domain invents its own API style.

Initial scope:

- API ability naming and ownership is defined in the Integration module.
- Visible "Scopes coming soon" UI was replaced with working Sanctum abilities.
- Client/Site, Asset, Contact, Ticket, Task, Knowledge, Storage, Calendar, Risk, Email Inbox,
  Notification, Sales, Taxonomy, Commercial, Economy, Report, User Management, and Custom Fields
  API scopes are implemented.
- Client create/update API supports `custom_fields`.
- Custom field definitions are exposed through a read-only discovery API.
- Current API routes are documented in Integration Knowledge documentation.
- OpenAPI documentation is generated for the current beta API surface.
- Representative auth, ability, validation, and route tests exist across the domain modules.

Future scope:

- Add domain APIs for future modules as they become beta-ready.
- Add richer filtering, includes, bulk operations, and webhooks when there is a concrete workflow.
- Add stricter service-token governance if external automation grows beyond scoped Sanctum tokens.

### 13. Report Builder And Scheduled Client Reporting

**Status:** Post-Beta
**Owner:**
**Domain:** Report / Client / Notification / Email
**Goal:** Let admins build reusable reports and schedule automatic delivery to clients.

Initial future scope:

- Custom report builder with selectable data sources, filters, grouping, and columns.
- Saved report templates.
- Client-specific scheduled reporting.
- Delivery through email and, later, customer portal surfaces.
- Per-client report preferences and recipient lists.
- Report preview before sending.
- Delivery history and failure tracking.
- Permissions for creating, editing, scheduling, and sending reports.

### 14. Email Branding And HTML Template Editor

**Status:** Implemented On Dev / Human Review Pending
**Owner:** Codex
**Domain:** Email / System / Branding
**Goal:** Make outbound email templates brand-aware and easier to edit safely.

Implemented 2026-08-24:

- Added shared, self-hosted visual/source HTML editing for reusable Email template bodies and
  Marketing campaign bodies.
- Separated editable Body HTML, plaintext, and complete Layout HTML.
- Added explicit `branding` and `custom` layout modes. Subject/body/plaintext edits do not freeze
  branding; only `Customize layout` materializes a custom layout, and reset resumes branding.
- Replaced hardcoded email chrome with a shared layout sourced from current Company Profile logo,
  light-theme surfaces, content/action colors, support email, and website.
- Added authoritative, sandboxed previews for unsaved Email template and Marketing editor values.
- Added server validation for active/interactive HTML, unsafe schemes/CSS, full documents in body
  fragments, and the required single `{{ email_body }}` custom-layout slot.
- Added immutable Marketing layout snapshots so later template or branding changes do not alter
  already-created campaign emails.
- Migrated Dev with a verified database backup and backfilled all existing campaign-email layouts.
- Added renderer, policy, controller, snapshot, preview, and regression tests plus Knowledge docs.

Review gate:

- Complete `HR-2026-08-24-004` visual and email-client review before promotion or public claims.
- Resolve the existing Knowledge `body_markdown` size constraint or split the combined Email article;
  Marketing documentation synced, but the 98,860-byte Email overview currently exceeds the Dev
  `TEXT` column and cannot be synchronized.

Future scope:

- Dedicated email-specific branding fields if web theme colors are not suitable for email clients.
- Per-client, per-language, per-queue, or per-workflow template selection.
- Safer variable validation and missing-variable warnings.
- Optional block/drag-and-drop project data and asset workflows behind a separately approved shared
  content-editor direction.

### 15. Ticket Workflow Requirements Enforcement

**Status:** Done
**Owner:** Codex
**Domain:** Ticket
**Goal:** Enforce the requirements already stored on workflow transitions.

Initial scope:

- Enforce `requires_note` before a transition can run.
- Enforce `requires_resolution` before resolve/close style transitions.
- Enforce `requires_knowledge_update` once documentation request tracking exists.
- Surface blocked reasons in Ticket show.
- Add tests and update Knowledge documentation.
- Update Knowledge page under `Nexum PSA -> Ticket`.

Out of scope for first pass:

- Large drag-and-drop workflow builder.
- Complex timers.
- AI write-tool execution.

### 14. Ticket Knowledge Follow-Up

**Status:** Done
**Owner:** Codex
**Domain:** Ticket / Knowledge
**Goal:** Make missing documentation visible from ticket work.

Initial scope:

- Add a lightweight “Documentation needed” action or event on Ticket show.
- Store a traceable request that points to ticket, category, client, and reason.
- Show pending documentation requests in Knowledge or Ticket settings.
- Keep articles/manual creation separate for now.
- Add tests and Knowledge documentation.

Future scope:

- KI-assisted article draft from ticket context.
- Workflow requirement: cannot close some categories without documentation update.

### 15. Contract SLA UI Polish

**Status:** Done
**Owner:** Codex
**Domain:** Commercial  
**Goal:** Make structured SLA binding clearer in contract screens.

Initial scope:

- Show SLA policy in contract index.
- Improve contract create/edit wording around “System default SLA”.
- Add a compact SLA summary to contract show.
- Ensure active contract SLA behavior is documented.
- Add tests if views or validation change.

### 16. SLA Reporting Foundation

**Status:** Done
**Owner:** Codex
**Domain:** Ticket / Reports  
**Goal:** Start basic operational reporting for SLA.

Initial scope:

- Query counts for response overdue, resolve overdue, responded within SLA, resolved within SLA.
- Start with a simple tech/admin report page or rightbar summary.
- Use ticket timestamps already available.
- Keep business-hours calculations out of first pass unless explicitly needed.
- Added `/tech/reports/tickets/sla` and linked it from the Reports hub.

### 17. Storage Barcode Scanning

**Status:** Post-Beta
**Owner:**
**Domain:** Storage
**Goal:** Support barcode-driven storage workflows from both desktop and mobile.

Initial scope:

- PC barcode scanners that behave like keyboard input.
- Mobile camera scanning for warehouse and technician workflows.
- Barcode lookup for storage items, boxes, reservations, picking, and stock adjustments.
- Settings for barcode formats and duplicate handling.
- Manual search fallback when barcode scanning is not available.

### 18. Storage Unit-Aware Picking And Adjustments

**Status:** Post-Beta
**Owner:**
**Domain:** Storage / Ticket
**Goal:** Replace the conservative safety blocks for identified inventory with explicit unit selection.

Initial scope:

- Add serial selection and batch/expiry-aware quantity allocation to Ticket picking.
- Add unit-aware inventory corrections that update Item, StockUnit, and Movement atomically.
- Define FEFO defaults and an authorized override for expiry-controlled stock.
- Preserve provenance and location when moving, consuming, or correcting identified units.
- Keep generic quantity-only adjustment and picking blocked for identified stock until this workflow exists.

### 19. AI Tool Hardening For Tickets

**Status:** Blocked  
**Owner:**  
**Domain:** Integration / Ticket  
**Blocked by:** Ticket Workflow v1 and stronger action guards.

Initial future scope:

- Expose safe read tools for SLA risk, my tickets, and ticket details.
- Add write tools only for explicitly allowed Ticket Actions.
- Log every AI tool execution.
- Require agent and role permission for write tools.

## Recently Completed

### Ticket Assignment Settings Split

- `user_profiles` now owns timezone, working hours, availability notes, and profile notes.
- Ticket assignment settings own only assignment-specific fields.
- Legacy `ticket_technician_profiles` data is migrated to `ticket_assignment_settings`.
- Legacy ticket technician profile tables are dropped after migration.
- Ticket assignment scoring reads assignment settings plus UserManagement profile data.

### Ticket SLA v1

- Tickets store `sla_id`, `sla_source`, `sla_source_id`, `sla_snapshot`, `first_response_due_at`, and `resolve_due_at`.
- SLA resolution order: Ticket Rule, active Contract, Default SLA.
- Ticket Rules can set SLA.
- Contracts can store structured `sla_id`.
- Ticket show displays SLA details.
- Ticket index displays SLA risk badges and supports `SLA risk` sorting.
- Knowledge article: `Ticket SLA - v1`.

### Ticket Actions v1

- Shared action definitions in `TicketAction`.
- Basic action gate in `TicketActionGuard`.
- Controller guards mutable ticket operations.
- `ApplyTicketSla` backend action exists for future Workflow/KI.
- Knowledge article: `Ticket Actions - v1`.

### Ticket Workflow v1

- Workflow, state, and transition tables/models exist.
- Default workflow is generated from active ticket statuses.
- New tickets use the active global default workflow.
- Ticket show displays available workflow transitions.
- Status changes are validated against workflow transitions.
- Blocked transitions write ticket events.
- Knowledge article: `Ticket Workflow - v1`.

### Ticket Workflow Editor v2

- Admin workflow index links to create/edit.
- Workflow form persists metadata, active/default flags, states, and transitions.
- Transition requirements are stored for later enforcement.
- Tests cover create and edit flows.
