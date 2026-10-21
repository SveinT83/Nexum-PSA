# Mail Domain API Coverage Matrix

Status: Implemented and automated on Dev; HR-2026-10-20-MAIL-API Reviewed by Svein Tore on 2026-10-21; real-provider verification not demonstrated
Date: 2026-10-20
Owner: Codex
Plan: [full coverage plan](2026-10-20-mail-domain-api-plan.md)
Authority: `/var/Projects/tdPSA` on `Dev`, including its dirty working copy
Baseline HEAD: `36918edbcaae1ce7b1208ba34f84a3a1719f0e98`

## How To Read The Matrix

`Present` means an API contract is registered and its source was inspected, not that it was called
or human-reviewed in this run. `Partial` means only part of the outcome is supported. `Missing`
means no corresponding registered Email API was found. `Decision` means current behavior and the
approved target need reconciliation before exposing the write. Gated runtime remains gated.

Historical proposals in the proposal column are relative to `/api/v1`; the implemented method/path contracts are in app/Modules/Email/Docs/email-api-contracts.json. Sources below
are relative to `app/Modules/Email/` unless they explicitly start with `docs/` or another module.
Each row covers a capability, not a count of endpoints. Presentation toggles are inventoried
separately from persisted commands and do not each require an endpoint.

## Capability Inventory

| ID | State | Capability | Current source / contract and gap | Proposed completion |
| --- | --- | --- | --- | --- |
| M01 | Implemented on Dev | Mail context, account and sender discovery | `Livewire/Tech/MailWorkspace.php::navigationData`, `sendableAccounts`, action capability helpers; accounts are nested in some resources but no standalone Mail discovery API | GET /email/mailbox/context and /email/mailbox/accounts; separate View and Send choices and safe runtime readiness |
| M02 | Implemented on Dev | Folder hierarchy, special roles and filtered counts | `MailWorkspace.php::folderQuery`, `stats`; `Livewire/Tech/MailSidebar.php::render`; no folder list API | GET /email/mailbox/accounts/{account}/folders with current authorized counts, capabilities and bounds |
| M03 | Implemented on Dev | Unified conversation lists and folder views | `MailWorkspace.php::conversationPlacementPaginator`, `durableConversationLeaderIds`; legacy Inbox API selects only provider Inbox and null ticket_id | GET /email/mailbox/conversations with current view/folder/account filters, stable ordering and continuation |
| M04 | Implemented on Dev | Exact conversation, placement and linked-mail reader | `MailWorkspace.php::conversationThread`, `selectedPlacement`, `loadMoreLegacyConversation`; legacy show rejects ticket-linked Mail | GET /email/mailbox/conversations/{conversation} and /email/mailbox/placements/{placement}; account-safe legacy continuation |
| M05 | Implemented on Dev | Search and filters across current Mail views | `Controllers/Api/V1/InboxController.php::messages` exposes q/state/account/from on unrouted Inbox; Mail has broader placement/list filters | Extend new Mail list queries with current search semantics; advanced indexing remains existing Order 22 |
| M06 | Implemented on Dev | Incoming attachment bytes | `Controllers/Tech/MailAttachmentController.php::download` and legacy Inbox download; API `EmailAttachmentResource` provides metadata only | GET /email/mailbox/placements/{placement}/attachments/{attachment}/download using exact source part and bearer auth |
| M07 | Implemented on Dev | Exact raw RFC822 source | `Controllers/Tech/MailRawSourceController.php::show`; no bearer download route | GET /email/mailbox/placements/{placement}/raw-source; explicit raw authority and private streaming |
| M08 | Implemented on Dev | Opened-by recording and authorized history | `MailWorkspace.php::recordOpened`; `Actions/RecordEmailMessageOpened.php`; presence API is ephemeral and different | POST /email/mailbox/placements/{placement}/opened and scoped opened-by read; never infer acknowledgement |
| M09 | Implemented on Dev | Ordinary selected-message Mark as read/unread | `MailWorkspace.php::markSelectedRead`, `markSelectedUnread`, `setSelectedReadState`; advanced acknowledgement is gated and different | POST /email/mailbox/placements/{placement}/read-state; shared/delegate local only, personal owner mirrors provider, separate outcome statuses |
| M10 | Implemented on Dev | Provider placement operations and recovery | `MailboxPlacementOperationsController::store`: mark_seen, mark_unseen, flag, unflag, archive, trash, move; `RemoteOperationsController`: index/show/retry/cancel/undo | Reuse existing operations and exact source evidence; no replacement dispatcher |
| M11 | Implemented on Dev | Provider folder create, rename, hierarchy move, empty and delete | `MailWorkspace.php::createProviderFolder`, `moveProviderFolder`, `renameProviderFolder`, `moveManagedFolderMail`, `deleteProviderFolder`; shared guarded actions exist | POST folders and typed rename/move/empty/delete commands; preview blockers, same-account targets, 50-item progress and remaining count |
| M12 | Implemented on Dev | Manual send/receive and selected-folder refresh | `InboxController::poll` exists; `MailWorkspace.php::sendAndReceiveMail`, `refreshSelectedFolder` queue account fetches | Typed refresh selection, bounded receipt/status/read-back; do not promise folder-only provider work |
| M13 | Implemented on Dev | Private compose/reply/reply-all/forward/provider-draft lifecycle | `Controllers/Api/V1/MailboxDraftsController.php`: index/store/show/update/discard; private owner, opaque public ID, HMAC fence | Preserve/reverify payload defaults, generation/owner/source policy and omitted/null semantics |
| M14 | Implemented on Dev | Durable draft attachment upload/removal | `MailboxDraftAttachmentsController`: store/destroy; shared counterparts also exist | Reuse generation-bound attachment operations; binary draft download is not current browser parity |
| M15 | Implemented on Dev | Provider Drafts synchronization | `MailboxDraftsController::syncProvider`, provider_draft store mode and draft status resource | Reuse manual provider sync and truthful reconciliation; autosave stays local |
| M16 | Implemented on Dev | Exact preview and once-only send | `MailboxDraftsController::preview`, `send`; `Actions/SubmitEmailComposerDraft.php` | Reuse signed preview, version and durable submission before SMTP; unknown send never blindly retried |
| M17 | Implemented on Dev | Submission and Sent reconciliation read-back | `OutboundSubmissionsController::show`, `sentReconciliation` | Preserve accepted/unknown/failed/Sent distinction; no recipient-delivery claim |
| M18 | Implemented on Dev | Explicit shared draft, editor lease, rebase and send | `SharedMailboxDraftsController` and `Livewire/Tech/Concerns/ManagesSharedComposerDraft.php`; runtime reviewed separately | Reuse 13 current routes and fences; retain HR-2026-08-16-009/011 and default-off collaboration |
| M19 | Implemented on Dev | Ephemeral reading/typing presence | `MailboxPresenceController`: show/heartbeat/leave | Reuse current source authorization and TTL; no content in presence; runtime remains gated |
| M20 | Implemented on Dev | Official API live invalidation/catch-up transport | `MailWorkspace.php::catchUpInvalidation`; `Services/EmailLiveCatchUpService.php`; browser broadcasting auth exists | Explicit authorized cursor/catch-up contract with opaque invalidations and bounded re-query; reuse Order 8, no content broadcast |
| M21 | Implemented on Dev | Conversation classification | `ConversationClassificationController`: show/update/destroy; Taxonomy owns definitions | Reuse assignment APIs; expose authorized choices in M01, preserve account-conversation scope |
| M22 | Implemented on Dev | Administrative rules and publication lifecycle | `Controllers/Api/V1/RulesController.php`: admin-only draft/validate/publish/version/preview; browser toggle can use same draft/publish outcome; browser destroy lacks API | Complete rule delete/draft-discard and builder context/validation references without a bypassing active-toggle write |
| M23 | Implemented on Dev | Personal rule creation and reviewed prefill | `MailWorkspace.php::createPersonalRule`; `Actions/CreatePersonalEmailRule.php`; admin rule API rejects personal rules | POST /email/mailbox/personal-rules plus authorized context; expose only actual supported lifecycle, visible reviewed proposals |
| M24 | Implemented on Dev | Rule execution/run discovery and recovery | Single execution/run/status and apply/cancel/retry/full-rerun/undo exist; `MailWorkspace.php::ruleExecutionAttemptsForPlacement` has scoped discovery absent as a collection API | GET scoped rule executions/reprocess runs and existing guarded commands; no successful-action replay |
| M25 | Implemented on Dev | Smart Inbox review, batch cleanup and Always do this | Seven SmartInboxSuggestions API routes exist; `Livewire/Tech/SmartInboxReviewQueue.php::applySelected`, `alwaysDoThis` add batch and reviewed rule-prefill outcomes | Typed bounded batch apply and rule-prefill contracts, per-item results and current evidence; no automatic rule publication |
| M26 | Implemented on Dev | Direct Mail AI summary and composer assistance | `MailWorkspace.php::generateMailAiSummary`, `applyComposerAi`; Smart Inbox analysis is a different operation | POST scoped summary/composer-assist actions; existing AI runtime, egress decisions, current draft fences and sanitized failures |
| M27 | Implemented on Dev | Own Mail signature read/update | `Controllers/Tech/SignatureController.php::update`; `Services/EmailSignatureRenderer.php`; no Email signature API | GET/PATCH /email/mailbox/signature; write-free GET projection, sanitized HTML/tokens and mode preferences |
| M28 | Implemented on Dev | Mail-side Ticket discovery/create/link | `MailWorkspace.php::createTicketForSelected`, `linkSelectedToTicket`, `ticketConversationLinksForPlacement`; Ticket-side APIs are adjacent, not equivalent | Typed source-placement Ticket context/create/link; both domain policies, once-only result and explicit partial/atomic recovery |
| M29 | Implemented on Dev | Mail conversation Not a Ticket suppression | `MailWorkspace.php::suppressSelectedTicketCorrelation`; `Actions/SuppressEmailConversationTicketCorrelation.php` | POST scoped ticket-suppression; preserve source/provider state, existing captured Ticket evidence and audit |
| M30 | Implemented on Dev | Account config, grants, connection tests and activation | `Controllers/Admin/AccountsController.php`: index/create/store/edit/update/test/toggleActive; no account API | Admin account context/read/create/update, grant writes, test receipt and explicit target activation; write-only passwords and binding fences |
| M31 | Implemented on Dev | Global config, health, retention preview and Mail AI settings | `Controllers/Admin/ConfigController.php`: index/update; `Services/EmailSystemHealth.php` | GET/PATCH scoped email settings plus health/retention preview; configuration authority adds no content grant |
| M32 | Implemented on Dev | Own delegation and access history | `Controllers/Tech/MailboxAccessController.php`: index/store/revokeDelegation/revokeBreakGlass; `MailboxAccessHistoryController` | Scoped access/delegation/history APIs with reason, bounded period, revocation and current policy |
| M33 | Implemented on Dev | Emergency mailbox access administration | `Controllers/Admin/EmergencyMailboxAccessController.php`: index/store/revoke | Explicit admin emergency preview/create/revoke/history contract with distinct permission, audit and expiry; no ordinary admin content bypass |
| M34 | Implemented on Dev | Explicit unread backlog handover | `Controllers/Tech/UnreadHandoverController.php`: index/preview/apply | Account handover context/preview/apply/status; exact snapshot and current epochs, never provider Seen inferred as personal state |
| M35 | Implemented on Dev | Historical import operations | `Controllers/Admin/MailboxMaintenanceController.php`: index/previewHistoricalImport/startHistoricalImport/cancelHistoricalImport | Maintenance context and bounded historical-import preview/start/status/cancel; no implicit send/provider mutation |
| M36 | Implemented on Dev | Provider reconciliation and cursor rebaseline | `MailboxMaintenanceController`: start/cancelProviderReconciliation, preview/applyCursorRebaseline | Typed maintenance runs/status/cancel and cursor preview/apply; current authority, UID namespace and bounded scope |
| M37 | Implemented on Dev | Canonical shadow/cutover/parity/rollback | `Controllers/Admin/CanonicalCorrelationController.php` and `CanonicalCutoverController.php` | Operator-only typed preview/run/inspect/review/apply/cancel/resume/rollback/status; retain exact-file evidence and existing gates |
| M38 | Implemented on Dev | Ticket-correlation conflict list/resolve | `Controllers/Admin/TicketCorrelationConflictController.php`: index/resolve | GET scoped conflicts and POST exact resolution preview/apply outcome; recheck Ticket/Mail scope and audit |
| M39 | Implemented on Dev | Email template context/list/create/update/preview | `Controllers/Admin/Templates/EmailTemplateController.php`; no template delete browser route | Admin template context/read/create/update/preview; use HTML/branding policy and no test send hidden inside preview |
| M40 | Implemented on Dev | Legacy Inbox hide/delete compatibility | `Controllers/Tech/InboxController.php::destroy` soft-deletes locally and may call direct IMAP deleteByUid under sync_delete, unlike guarded permanent-delete target | Decide compatibility first; recommend ordinary Trash, explicitly named legacy hide if retained, permanent provider delete only via Order 24 |
| M41 | Implemented on Dev | Complete OpenAPI contracts and discoverable errors | 73 registered APIs, 46 matching source HTTP attributes, 27 without matching attribute; schema details not fully audited | Complete method/path/ability/request/response/error/binary docs and validate generated schema against runtime routes |
| M42 | Implemented on Dev | Persisted own folder-navigation preferences | `Livewire/Tech/MailSidebar.php::selectFolder`, `toggleFolderNavigationFolder`, `persistFolderNavigationStates`; `Models/EmailFolderNavigationPreference.php` | GET/PATCH own mailbox preferences, exact permitted folder IDs, explicit target expansion state rather than retry-sensitive toggling |
| M43 | Implemented on Dev | Automated maintained Mail parity inventory | Existing API/query/UI tests exist; route discovery alone misses Livewire-only outcomes and unannotated schemas | Add meaningful method/path/schema and capability contract checks with same-actor browser/API outcome tests |
| M44 | Implemented on Dev | Advanced conversation/multi-account acknowledgement | `ConversationActionsController`: preview/show/apply/retry/cancel; exact bounded placement snapshot and personal/provider effects; runtime remains disabled per existing review | Reuse existing run/item/status APIs; preserve HR-2026-08-16-012 and distinguish this from ordinary selected-message read state |

## Full Product Dependencies

Unimplemented target capabilities remain with Orders 17-32 of the existing completion index.
Each must deliver its own API with its feature. They are not counted as missing adapters for
already-working UI: recipient/portal rules, quarantine, advanced search, legal hold/export/lifecycle,
permanent deletion, entity matching, automatic replies, provider drivers, calendar/OOO, file
handoff, advanced collaboration and reporting/productivity.

## Evidence And Verification

The JSON companion records the complete registered Email API/browser routes with middleware,
controller/source locations, adjacent cross-domain routes, source hashes, public Livewire methods
and source OpenAPI coverage. Generated appendices below make every registered route inspectable.
Public method classification identifies business outcomes versus reusable query/capability and
presentation helpers; it does not assert each helper requires its own endpoint.

No API behavior test, real provider action or production read was executed. Historical review/test
statements remain in their existing source documents; this matrix does not renew those claims.

## Registered API Routes

All rows have Sanctum plus the listed ability. Present/OA describes source registration only.

| Method | Path | Ability | Capability | HTTP attribute | Source |
| --- | --- | --- | --- | --- | --- |
| GET / HEAD | `/api/v1/email/inbox/messages` | `email.read` | M05 | Implemented on Dev | `Controllers/Api/V1/InboxController.php:47` |
| GET / HEAD | `/api/v1/email/inbox/messages/{message}` | `email.read` | M05 | Implemented on Dev | `Controllers/Api/V1/InboxController.php:130` |
| POST | `/api/v1/email/inbox/messages/{message}/spam` | `email.update` | M10 | Implemented on Dev | `Controllers/Api/V1/InboxController.php:182` |
| POST | `/api/v1/email/inbox/poll` | `email.update` | M12 | Implemented on Dev | `Controllers/Api/V1/InboxController.php:217` |
| POST | `/api/v1/email/mailbox/conversation-actions/preview` | `email.read` | M44 | Implemented on Dev | `Controllers/Api/V1/ConversationActionsController.php:21` |
| GET / HEAD | `/api/v1/email/mailbox/conversation-actions/{run}` | `email.read` | M44 | Implemented on Dev | `Controllers/Api/V1/ConversationActionsController.php:65` |
| POST | `/api/v1/email/mailbox/conversation-actions/{run}/apply` | `email.update` | M44 | Implemented on Dev | `Controllers/Api/V1/ConversationActionsController.php:70` |
| POST | `/api/v1/email/mailbox/conversation-actions/{run}/cancel` | `email.update` | M44 | Implemented on Dev | `Controllers/Api/V1/ConversationActionsController.php:92` |
| POST | `/api/v1/email/mailbox/conversation-actions/{run}/retry` | `email.update` | M44 | Implemented on Dev | `Controllers/Api/V1/ConversationActionsController.php:81` |
| GET / HEAD | `/api/v1/email/mailbox/conversations/{conversation}/classification` | `email.read` | M21 | Implemented on Dev | `Controllers/Api/V1/ConversationClassificationController.php:35` |
| PUT | `/api/v1/email/mailbox/conversations/{conversation}/classification` | `email.update` | M21 | Implemented on Dev | `Controllers/Api/V1/ConversationClassificationController.php:64` |
| DELETE | `/api/v1/email/mailbox/conversations/{conversation}/classification` | `email.update` | M21 | Implemented on Dev | `Controllers/Api/V1/ConversationClassificationController.php:112` |
| GET / HEAD | `/api/v1/email/mailbox/conversations/{conversation}/presence` | `email.drafts.read` | M19 | Implemented on Dev | `Controllers/Api/V1/MailboxPresenceController.php:21` |
| POST | `/api/v1/email/mailbox/conversations/{conversation}/presence` | `email.drafts.write` | M19 | Implemented on Dev | `Controllers/Api/V1/MailboxPresenceController.php:39` |
| DELETE | `/api/v1/email/mailbox/conversations/{conversation}/presence` | `email.drafts.write` | M19 | Implemented on Dev | `Controllers/Api/V1/MailboxPresenceController.php:78` |
| POST | `/api/v1/email/mailbox/conversations/{conversation}/smart-inbox/analyze` | `email.read` | M25 | Implemented on Dev | `Controllers/Api/V1/SmartInboxSuggestionsController.php:164` |
| GET / HEAD | `/api/v1/email/mailbox/drafts` | `email.drafts.read` | M13 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:29` |
| POST | `/api/v1/email/mailbox/drafts` | `email.drafts.write` | M13 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:82` |
| GET / HEAD | `/api/v1/email/mailbox/drafts/{draft}` | `email.drafts.read` | M13 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:151` |
| PATCH | `/api/v1/email/mailbox/drafts/{draft}` | `email.drafts.write` | M13 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:159` |
| DELETE | `/api/v1/email/mailbox/drafts/{draft}` | `email.drafts.write` | M13 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:199` |
| POST | `/api/v1/email/mailbox/drafts/{draft}/attachments` | `email.drafts.write` | M14 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftAttachmentsController.php:22` |
| DELETE | `/api/v1/email/mailbox/drafts/{draft}/attachments/{attachment}` | `email.drafts.write` | M14 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftAttachmentsController.php:50` |
| POST | `/api/v1/email/mailbox/drafts/{draft}/preview` | `email.send` | M16 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:245` |
| POST | `/api/v1/email/mailbox/drafts/{draft}/provider-sync` | `email.drafts.write` | M15 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:222` |
| POST | `/api/v1/email/mailbox/drafts/{draft}/send` | `email.send` | M16 | Implemented on Dev | `Controllers/Api/V1/MailboxDraftsController.php:286` |
| POST | `/api/v1/email/mailbox/drafts/{draft}/share` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:31` |
| POST | `/api/v1/email/mailbox/placements/{placement}/operations` | `email.update` | M10 | Implemented on Dev | `Controllers/Api/V1/MailboxPlacementOperationsController.php:37` |
| GET / HEAD | `/api/v1/email/mailbox/remote-operations` | `email.read` | M10 | Implemented on Dev | `Controllers/Api/V1/RemoteOperationsController.php:33` |
| GET / HEAD | `/api/v1/email/mailbox/remote-operations/{operation}` | `email.read` | M10 | Implemented on Dev | `Controllers/Api/V1/RemoteOperationsController.php:81` |
| POST | `/api/v1/email/mailbox/remote-operations/{operation}/cancel` | `email.update` | M10 | Implemented on Dev | `Controllers/Api/V1/RemoteOperationsController.php:137` |
| POST | `/api/v1/email/mailbox/remote-operations/{operation}/retry` | `email.update` | M10 | Implemented on Dev | `Controllers/Api/V1/RemoteOperationsController.php:110` |
| GET / HEAD | `/api/v1/email/mailbox/remote-operations/{operation}/undo` | `email.read` | M10 | Implemented on Dev | `Controllers/Api/V1/RemoteOperationsController.php:162` |
| POST | `/api/v1/email/mailbox/remote-operations/{operation}/undo` | `email.update` | M10 | Implemented on Dev | `Controllers/Api/V1/RemoteOperationsController.php:192` |
| GET / HEAD | `/api/v1/email/mailbox/shared-drafts/{draft}` | `email.drafts.read` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:79` |
| PATCH | `/api/v1/email/mailbox/shared-drafts/{draft}` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:196` |
| DELETE | `/api/v1/email/mailbox/shared-drafts/{draft}` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:440` |
| POST | `/api/v1/email/mailbox/shared-drafts/{draft}/attachments` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:225` |
| DELETE | `/api/v1/email/mailbox/shared-drafts/{draft}/attachments/{attachment}` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:257` |
| POST | `/api/v1/email/mailbox/shared-drafts/{draft}/lease` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:97` |
| PATCH | `/api/v1/email/mailbox/shared-drafts/{draft}/lease` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:140` |
| DELETE | `/api/v1/email/mailbox/shared-drafts/{draft}/lease` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:169` |
| POST | `/api/v1/email/mailbox/shared-drafts/{draft}/preview` | `email.send` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:349` |
| POST | `/api/v1/email/mailbox/shared-drafts/{draft}/rebase` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:320` |
| POST | `/api/v1/email/mailbox/shared-drafts/{draft}/rebase-preview` | `email.drafts.write` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:292` |
| POST | `/api/v1/email/mailbox/shared-drafts/{draft}/send` | `email.send` | M18 | Implemented on Dev | `Controllers/Api/V1/SharedMailboxDraftsController.php:393` |
| GET / HEAD | `/api/v1/email/mailbox/submissions/{submission}` | `email.send` | M17 | Implemented on Dev | `Controllers/Api/V1/OutboundSubmissionsController.php:16` |
| GET / HEAD | `/api/v1/email/mailbox/submissions/{submission}/sent-reconciliation` | `email.send` | M17 | Implemented on Dev | `Controllers/Api/V1/OutboundSubmissionsController.php:26` |
| GET / HEAD | `/api/v1/email/rule-reprocess-runs/{run}` | `email.rules.read` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:133` |
| POST | `/api/v1/email/rule-reprocess-runs/{run}/apply` | `email.rules.execute` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:141` |
| POST | `/api/v1/email/rule-reprocess-runs/{run}/cancel` | `email.rules.execute` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:148` |
| POST | `/api/v1/email/rule-reprocess-runs/{run}/full-rerun` | `email.rules.execute` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:162` |
| POST | `/api/v1/email/rule-reprocess-runs/{run}/retry` | `email.rules.execute` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:155` |
| GET / HEAD | `/api/v1/email/rules` | `email.rules.read` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:170` |
| POST | `/api/v1/email/rules/drafts` | `email.rules.write` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:24` |
| GET / HEAD | `/api/v1/email/rules/executions/{attempt}` | `email.rules.read` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:222` |
| GET / HEAD | `/api/v1/email/rules/executions/{attempt}/undo` | `email.rules.read` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:239` |
| POST | `/api/v1/email/rules/executions/{attempt}/undo` | `email.rules.execute` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:255` |
| GET / HEAD | `/api/v1/email/rules/{rule}` | `email.rules.read` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:186` |
| GET / HEAD | `/api/v1/email/rules/{rule}/draft` | `email.rules.read` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:48` |
| PUT | `/api/v1/email/rules/{rule}/draft` | `email.rules.write` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:57` |
| POST | `/api/v1/email/rules/{rule}/draft/validate` | `email.rules.write` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:67` |
| POST | `/api/v1/email/rules/{rule}/preview` | `email.rules.read` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:196` |
| POST | `/api/v1/email/rules/{rule}/publish` | `email.rules.write` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:89` |
| GET / HEAD | `/api/v1/email/rules/{rule}/publish-preview` | `email.rules.write` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:81` |
| POST | `/api/v1/email/rules/{rule}/reprocess-preview` | `email.rules.execute` | M24 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:112` |
| GET / HEAD | `/api/v1/email/rules/{rule}/versions` | `email.rules.read` | M22 | Implemented on Dev | `Controllers/Api/V1/RulesController.php:102` |
| GET / HEAD | `/api/v1/email/smart-inbox/suggestions` | `email.read` | M25 | Implemented on Dev | `Controllers/Api/V1/SmartInboxSuggestionsController.php:37` |
| GET / HEAD | `/api/v1/email/smart-inbox/suggestions/count` | `email.read` | M25 | Implemented on Dev | `Controllers/Api/V1/SmartInboxSuggestionsController.php:103` |
| GET / HEAD | `/api/v1/email/smart-inbox/suggestions/{suggestion}` | `email.read` | M25 | Implemented on Dev | `Controllers/Api/V1/SmartInboxSuggestionsController.php:142` |
| PATCH | `/api/v1/email/smart-inbox/suggestions/{suggestion}` | `email.update` | M25 | Implemented on Dev | `Controllers/Api/V1/SmartInboxSuggestionsController.php:235` |
| POST | `/api/v1/email/smart-inbox/suggestions/{suggestion}/apply` | `email.update` | M25 | Implemented on Dev | `Controllers/Api/V1/SmartInboxSuggestionsController.php:275` |
| POST | `/api/v1/email/smart-inbox/suggestions/{suggestion}/dismiss` | `email.update` | M25 | Implemented on Dev | `Controllers/Api/V1/SmartInboxSuggestionsController.php:207` |

## Registered Browser Routes

All 72 rows are mapped; create/edit form routes need API context, not HTML replicas.

| Method | Path | Capability | Source |
| --- | --- | --- | --- |
| GET / HEAD | `/tech/admin/settings/email/accounts` | M30 | `Controllers/Admin/AccountsController.php:36` |
| POST | `/tech/admin/settings/email/accounts` | M30 | `Controllers/Admin/AccountsController.php:58` |
| GET / HEAD | `/tech/admin/settings/email/accounts/create` | M30 | `Controllers/Admin/AccountsController.php:49` |
| PUT | `/tech/admin/settings/email/accounts/{account}` | M30 | `Controllers/Admin/AccountsController.php:113` |
| GET / HEAD | `/tech/admin/settings/email/accounts/{account}/edit` | M30 | `Controllers/Admin/AccountsController.php:102` |
| POST | `/tech/admin/settings/email/accounts/{account}/folders/{folder}/cursor-rebaseline` | M35, M36 | `Controllers/Admin/MailboxMaintenanceController.php:139` |
| POST | `/tech/admin/settings/email/accounts/{account}/folders/{folder}/cursor-rebaseline/preview` | M35, M36 | `Controllers/Admin/MailboxMaintenanceController.php:122` |
| POST | `/tech/admin/settings/email/accounts/{account}/historical-import` | M35 | `Controllers/Admin/MailboxMaintenanceController.php:47` |
| POST | `/tech/admin/settings/email/accounts/{account}/historical-import/preview` | M35 | `Controllers/Admin/MailboxMaintenanceController.php:35` |
| POST | `/tech/admin/settings/email/accounts/{account}/historical-import/{run}/cancel` | M35 | `Controllers/Admin/MailboxMaintenanceController.php:67` |
| GET / HEAD | `/tech/admin/settings/email/accounts/{account}/mailbox-maintenance` | M35, M36 | `Controllers/Admin/MailboxMaintenanceController.php:27` |
| POST | `/tech/admin/settings/email/accounts/{account}/provider-reconciliation` | M35, M36 | `Controllers/Admin/MailboxMaintenanceController.php:81` |
| POST | `/tech/admin/settings/email/accounts/{account}/provider-reconciliation/{run}/cancel` | M35, M36 | `Controllers/Admin/MailboxMaintenanceController.php:106` |
| POST | `/tech/admin/settings/email/accounts/{account}/test` | M30 | `Controllers/Admin/AccountsController.php:258` |
| POST | `/tech/admin/settings/email/accounts/{account}/toggle` | M30 | `Controllers/Admin/AccountsController.php:177` |
| GET / HEAD | `/tech/admin/settings/email/canonical-cutover` | M37 | `Controllers/Admin/CanonicalCutoverController.php:40` |
| POST | `/tech/admin/settings/email/canonical-cutover/audit` | M37 | `Controllers/Admin/CanonicalCutoverController.php:103` |
| POST | `/tech/admin/settings/email/canonical-cutover/backfill` | M37 | `Controllers/Admin/CanonicalCutoverController.php:86` |
| POST | `/tech/admin/settings/email/canonical-cutover/merge` | M37 | `Controllers/Admin/CanonicalCutoverController.php:120` |
| POST | `/tech/admin/settings/email/canonical-cutover/mode` | M37 | `Controllers/Admin/CanonicalCutoverController.php:139` |
| GET / HEAD | `/tech/admin/settings/email/canonical-cutover/{run}` | M37 | `Controllers/Admin/CanonicalCutoverController.php:189` |
| POST | `/tech/admin/settings/email/canonical-cutover/{run}/apply` | M37 | `Controllers/Admin/CanonicalCutoverController.php:215` |
| POST | `/tech/admin/settings/email/canonical-cutover/{run}/rollback` | M37 | `Controllers/Admin/CanonicalCutoverController.php:230` |
| GET / HEAD | `/tech/admin/settings/email/config` | M31 | `Controllers/Admin/ConfigController.php:23` |
| POST | `/tech/admin/settings/email/config` | M31 | `Controllers/Admin/ConfigController.php:71` |
| GET / HEAD | `/tech/admin/settings/email/correlation` | M37 | `Controllers/Admin/CanonicalCorrelationController.php:29` |
| POST | `/tech/admin/settings/email/correlation` | M37 | `Controllers/Admin/CanonicalCorrelationController.php:48` |
| GET / HEAD | `/tech/admin/settings/email/correlation/candidates/{candidate}/inspect` | M37 | `Controllers/Admin/CanonicalCorrelationController.php:94` |
| POST | `/tech/admin/settings/email/correlation/candidates/{candidate}/review` | M37 | `Controllers/Admin/CanonicalCorrelationController.php:108` |
| GET / HEAD | `/tech/admin/settings/email/correlation/{run}` | M37 | `Controllers/Admin/CanonicalCorrelationController.php:75` |
| POST | `/tech/admin/settings/email/correlation/{run}/cancel` | M37 | `Controllers/Admin/CanonicalCorrelationController.php:140` |
| POST | `/tech/admin/settings/email/correlation/{run}/resume` | M37 | `Controllers/Admin/CanonicalCorrelationController.php:126` |
| GET / HEAD | `/tech/admin/settings/email/emergency-access` | M33 | `Controllers/Admin/EmergencyMailboxAccessController.php:17` |
| POST | `/tech/admin/settings/email/emergency-access` | M33 | `Controllers/Admin/EmergencyMailboxAccessController.php:50` |
| POST | `/tech/admin/settings/email/emergency-access/{accessId}/revoke` | M33 | `Controllers/Admin/EmergencyMailboxAccessController.php:82` |
| GET / HEAD | `/tech/admin/settings/email/rules` | M22 | `Controllers/Admin/RulesController.php:32` |
| POST | `/tech/admin/settings/email/rules` | M22 | `Controllers/Admin/RulesController.php:92` |
| GET / HEAD | `/tech/admin/settings/email/rules/create` | M22 | `Controllers/Admin/RulesController.php:51` |
| POST | `/tech/admin/settings/email/rules/reprocess-runs/{run}/apply` | M24 | `Controllers/Admin/RulesController.php:235` |
| POST | `/tech/admin/settings/email/rules/undo/{attempt}` | M24 | `Controllers/Admin/RulesController.php:246` |
| PUT | `/tech/admin/settings/email/rules/{rule}` | M22 | `Controllers/Admin/RulesController.php:153` |
| DELETE | `/tech/admin/settings/email/rules/{rule}` | M22 | `Controllers/Admin/RulesController.php:204` |
| GET / HEAD | `/tech/admin/settings/email/rules/{rule}/edit` | M22 | `Controllers/Admin/RulesController.php:134` |
| POST | `/tech/admin/settings/email/rules/{rule}/reprocess-preview` | M24 | `Controllers/Admin/RulesController.php:214` |
| POST | `/tech/admin/settings/email/rules/{rule}/toggle` | M22 | `Controllers/Admin/RulesController.php:185` |
| GET / HEAD | `/tech/admin/settings/email/ticket-correlation-conflicts` | M38 | `Controllers/Admin/TicketCorrelationConflictController.php:15` |
| POST | `/tech/admin/settings/email/ticket-correlation-conflicts/{conflict}/resolve` | M38 | `Controllers/Admin/TicketCorrelationConflictController.php:35` |
| GET / HEAD | `/tech/admin/system/templatesManagement/email` | M39 | `Controllers/Admin/Templates/EmailTemplateController.php:33` |
| POST | `/tech/admin/system/templatesManagement/email` | M39 | `Controllers/Admin/Templates/EmailTemplateController.php:81` |
| GET / HEAD | `/tech/admin/system/templatesManagement/email/create` | M39 | `Controllers/Admin/Templates/EmailTemplateController.php:63` |
| POST | `/tech/admin/system/templatesManagement/email/preview` | M39 | `Controllers/Admin/Templates/EmailTemplateController.php:102` |
| PUT | `/tech/admin/system/templatesManagement/email/{template}` | M39 | `Controllers/Admin/Templates/EmailTemplateController.php:90` |
| GET / HEAD | `/tech/admin/system/templatesManagement/email/{template}/edit` | M39 | `Controllers/Admin/Templates/EmailTemplateController.php:76` |
| GET / HEAD | `/tech/inbox` | M05 | `Controllers/Tech/InboxController.php:32` |
| GET / HEAD | `/tech/inbox/attachments/{attachment}/download` | M06 | `Controllers/Tech/InboxController.php:247` |
| POST | `/tech/inbox/poll` | M12 | `Controllers/Tech/InboxController.php:93` |
| GET / HEAD | `/tech/inbox/show/{message}` | M05 | `Controllers/Tech/InboxController.php:126` |
| DELETE | `/tech/inbox/{message}` | M40 | `Controllers/Tech/InboxController.php:186` |
| POST | `/tech/inbox/{message}/spam` | M10 | `Controllers/Tech/InboxController.php:167` |
| GET / HEAD | `/tech/mail` | M01, M02, M03, M04 | `Controllers/Tech/MailController.php:16` |
| GET / HEAD | `/tech/mail/access` | M32 | `Controllers/Tech/MailboxAccessController.php:19` |
| GET / HEAD | `/tech/mail/access-history` | M32 | `Controllers/Tech/MailboxAccessHistoryController.php:13` |
| POST | `/tech/mail/access/accounts/{accountId}/delegations` | M32 | `Controllers/Tech/MailboxAccessController.php:75` |
| POST | `/tech/mail/access/accounts/{accountId}/delegations/{delegationId}/revoke` | M32 | `Controllers/Tech/MailboxAccessController.php:101` |
| POST | `/tech/mail/access/emergency/{accessId}/revoke` | M32 | `Controllers/Tech/MailboxAccessController.php:123` |
| GET / HEAD | `/tech/mail/accounts/{account}/unread-handover` | M34 | `Controllers/Tech/UnreadHandoverController.php:31` |
| POST | `/tech/mail/accounts/{account}/unread-handover/preview` | M34 | `Controllers/Tech/UnreadHandoverController.php:78` |
| POST | `/tech/mail/accounts/{account}/unread-handover/{run}` | M34 | `Controllers/Tech/UnreadHandoverController.php:117` |
| POST | `/tech/mail/broadcasting/auth` | M20 | `Controllers/Tech/EmailBroadcastingController.php:16` |
| GET / HEAD | `/tech/mail/placements/{placement}/attachments/{attachment}/download` | M06 | `Controllers/Tech/MailAttachmentController.php:28` |
| GET / HEAD | `/tech/mail/placements/{placement}/raw-source` | M07 | `Controllers/Tech/MailRawSourceController.php:25` |
| PATCH | `/tech/mail/signature` | M27 | `Controllers/Tech/SignatureController.php:12` |

## Public Livewire Method Declarations

157 declarations across the Email Livewire components/concern. Inherited declarations and UI aliases are not separate business capabilities. Presentation and lifecycle rows do not assert an endpoint gap.

| Source | Method | Classification | Capability |
| --- | --- | --- | --- |
| `Tech/Concerns/ManagesSharedComposerDraft.php:29` | `composerSharedEditable` | business operation or context | M18 |
| `Tech/Concerns/ManagesSharedComposerDraft.php:36` | `shareComposerDraft` | business operation or context | M18 |
| `Tech/Concerns/ManagesSharedComposerDraft.php:89` | `acquireComposerSharedLease` | business operation or context | M18 |
| `Tech/Concerns/ManagesSharedComposerDraft.php:117` | `renewComposerSharedLease` | business operation or context | M18 |
| `Tech/Concerns/ManagesSharedComposerDraft.php:143` | `releaseComposerSharedLease` | business operation or context | M18 |
| `Tech/MailSidebar.php:20` | `refreshAfterMailStateChanged` | component lifecycle/projection | M01 |
| `Tech/MailSidebar.php:25` | `selectFolder` | persisted personal preference command | M03, M42 |
| `Tech/MailSidebar.php:47` | `toggleFolderNavigationFolder` | persisted personal preference command | M42 |
| `Tech/MailSidebar.php:71` | `render` | component lifecycle/projection | M01 |
| `Tech/MailWorkspace.php:115` | `mount` | component lifecycle/projection | M01 |
| `Tech/MailWorkspace.php:155` | `getListeners` | component lifecycle/projection | M01 |
| `Tech/MailWorkspace.php:163` | `handleEmailProjectionInvalidated` | business operation or context | M20 |
| `Tech/MailWorkspace.php:174` | `catchUpInvalidation` | business operation or context | M20 |
| `Tech/MailWorkspace.php:221` | `acknowledgeConversation` | business operation or context | M44 |
| `Tech/MailWorkspace.php:237` | `openConversationAcknowledgement` | presentation/preparation; no independent persisted outcome | M44 |
| `Tech/MailWorkspace.php:262` | `closeConversationAcknowledgement` | presentation/preparation; no independent persisted outcome | M44 |
| `Tech/MailWorkspace.php:269` | `previewConversationAcknowledgement` | business operation or context | M44 |
| `Tech/MailWorkspace.php:299` | `applyConversationAcknowledgement` | business operation or context | M44 |
| `Tech/MailWorkspace.php:332` | `refreshConversationAcknowledgement` | business operation or context | M44 |
| `Tech/MailWorkspace.php:345` | `cancelConversationAcknowledgement` | query/capability helper | M44 |
| `Tech/MailWorkspace.php:360` | `conversationAcknowledgementAvailable` | business operation or context | M44 |
| `Tech/MailWorkspace.php:578` | `setView` | business operation or context | M03 |
| `Tech/MailWorkspace.php:599` | `selectAccount` | business operation or context | M03 |
| `Tech/MailWorkspace.php:616` | `selectFolder` | business operation or context | M03 |
| `Tech/MailWorkspace.php:639` | `applyFilters` | business operation or context | M03 |
| `Tech/MailWorkspace.php:660` | `selectPlacement` | business operation or context | M04, M08 |
| `Tech/MailWorkspace.php:710` | `loadMoreLegacyConversation` | business operation or context | M04 |
| `Tech/MailWorkspace.php:719` | `setSelectedUnreadForMe` | business operation or context | M09 |
| `Tech/MailWorkspace.php:753` | `markSelectedReadForMe` | business operation or context | M09 |
| `Tech/MailWorkspace.php:758` | `markSelectedRead` | business operation or context | M09 |
| `Tech/MailWorkspace.php:763` | `markSelectedUnread` | business operation or context | M09 |
| `Tech/MailWorkspace.php:839` | `sendAndReceiveMail` | business operation or context | M12 |
| `Tech/MailWorkspace.php:869` | `refreshSelectedFolder` | business operation or context | M12 |
| `Tech/MailWorkspace.php:892` | `markSelectedSpam` | business operation or context | M10 |
| `Tech/MailWorkspace.php:965` | `suppressSelectedTicketCorrelation` | business operation or context | M29 |
| `Tech/MailWorkspace.php:998` | `createTicketForSelected` | business operation or context | M28 |
| `Tech/MailWorkspace.php:1048` | `createTicketFromAiReview` | business operation or context | M28 |
| `Tech/MailWorkspace.php:1065` | `toggleTicketLinkPanel` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:1092` | `linkSelectedToTicket` | business operation or context | M28 |
| `Tech/MailWorkspace.php:1148` | `setProviderSeenForSelected` | business operation or context | M10 |
| `Tech/MailWorkspace.php:1155` | `setProviderFlaggedForSelected` | business operation or context | M10 |
| `Tech/MailWorkspace.php:1162` | `archiveSelected` | business operation or context | M10 |
| `Tech/MailWorkspace.php:1167` | `trashSelected` | business operation or context | M10 |
| `Tech/MailWorkspace.php:1172` | `toggleMovePanel` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:1204` | `moveSelectedToFolder` | business operation or context | M10 |
| `Tech/MailWorkspace.php:1274` | `openRuleAction` | presentation/preparation; no independent persisted outcome | M23 |
| `Tech/MailWorkspace.php:1319` | `applySmartInboxPersonalRulePrefill` | business operation or context | M23 |
| `Tech/MailWorkspace.php:1390` | `closePersonalRuleModal` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:1395` | `createPersonalRule` | business operation or context | M23 |
| `Tech/MailWorkspace.php:1448` | `generateMailAiSummary` | business operation or context | M26 |
| `Tech/MailWorkspace.php:1513` | `clearMailAiSummary` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:1518` | `applyComposerAi` | business operation or context | M26 |
| `Tech/MailWorkspace.php:1673` | `toggleClassificationEditor` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:1702` | `saveClassification` | business operation or context | M21 |
| `Tech/MailWorkspace.php:1766` | `clearClassification` | business operation or context | M21 |
| `Tech/MailWorkspace.php:1773` | `startReply` | business operation or context | M13 |
| `Tech/MailWorkspace.php:1778` | `startReplyAll` | business operation or context | M13 |
| `Tech/MailWorkspace.php:1792` | `startForward` | business operation or context | M13 |
| `Tech/MailWorkspace.php:1797` | `editProviderDraft` | business operation or context | M13 |
| `Tech/MailWorkspace.php:1839` | `startCompose` | business operation or context | M13 |
| `Tech/MailWorkspace.php:1872` | `cancelComposer` | query/capability helper | M13 |
| `Tech/MailWorkspace.php:1888` | `cancelReply` | query/capability helper | M13 |
| `Tech/MailWorkspace.php:1893` | `removeComposerAttachment` | business operation or context | M14 |
| `Tech/MailWorkspace.php:1906` | `removeComposerDraftAttachment` | business operation or context | M14 |
| `Tech/MailWorkspace.php:1962` | `removeReplyAttachment` | business operation or context | M14 |
| `Tech/MailWorkspace.php:1967` | `toggleNewFolderForm` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:1984` | `createProviderFolder` | business operation or context | M11 |
| `Tech/MailWorkspace.php:2034` | `toggleRemoteOperations` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2039` | `retryRemoteOperation` | business operation or context | M10 |
| `Tech/MailWorkspace.php:2078` | `cancelRemoteOperation` | query/capability helper | M10 |
| `Tech/MailWorkspace.php:2114` | `undoRemoteOperation` | business operation or context | M10 |
| `Tech/MailWorkspace.php:2160` | `saveComposerDraft` | business operation or context | M13 |
| `Tech/MailWorkspace.php:2191` | `discardComposerDraft` | business operation or context | M13 |
| `Tech/MailWorkspace.php:2243` | `sendComposer` | business operation or context | M16 |
| `Tech/MailWorkspace.php:2385` | `sendReply` | business operation or context | M16 |
| `Tech/MailWorkspace.php:2390` | `updatedSearch` | business operation or context | M03 |
| `Tech/MailWorkspace.php:2402` | `updatedListFilter` | business operation or context | M03 |
| `Tech/MailWorkspace.php:2418` | `clearSearch` | presentation/preparation; no independent persisted outcome | M03 |
| `Tech/MailWorkspace.php:2431` | `render` | component lifecycle/projection | M01 |
| `Tech/MailWorkspace.php:2571` | `canCreateFolderForSelectedAccount` | query/capability helper | M11 |
| `Tech/MailWorkspace.php:2576` | `canManageFoldersForSelectedAccount` | query/capability helper | M11 |
| `Tech/MailWorkspace.php:2581` | `canSendAndReceiveMail` | query/capability helper | M12 |
| `Tech/MailWorkspace.php:2586` | `canRefreshSelectedFolder` | query/capability helper | M12 |
| `Tech/MailWorkspace.php:2591` | `openFolderManager` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2613` | `closeFolderManager` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2623` | `changeFolderManagerAccount` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2643` | `toggleFolderManagerFolder` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2661` | `startFolderRename` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2683` | `cancelFolderRename` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2690` | `startFolderMove` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2712` | `cancelFolderMove` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2719` | `moveProviderFolder` | business operation or context | M11 |
| `Tech/MailWorkspace.php:2766` | `renameProviderFolder` | business operation or context | M11 |
| `Tech/MailWorkspace.php:2818` | `startFolderDelete` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2855` | `cancelFolderDelete` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/MailWorkspace.php:2863` | `moveManagedFolderMail` | business operation or context | M11 |
| `Tech/MailWorkspace.php:2922` | `deleteProviderFolder` | business operation or context | M11 |
| `Tech/MailWorkspace.php:2972` | `folderManagerRows` | business operation or context | M11 |
| `Tech/MailWorkspace.php:3043` | `folderManagerAccountLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3051` | `folderManagerAccounts` | business operation or context | M11 |
| `Tech/MailWorkspace.php:3096` | `mailCountLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3101` | `conversationCountLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3109` | `folderParentTargetsFor` | business operation or context | M11 |
| `Tech/MailWorkspace.php:3136` | `folderMoveTargetsFor` | business operation or context | M11 |
| `Tech/MailWorkspace.php:3154` | `senderLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3166` | `senderInitial` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3174` | `recipientSummary` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3193` | `messagePreview` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3201` | `isUnreadForMe` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3222` | `listFilterOptions` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3241` | `usesBreakGlassForPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3256` | `canUsePersonalUnreadForPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3261` | `canUseTicketContextForPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3266` | `canViewRawSourceForPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3279` | `folderLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3291` | `canOrganizePlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3298` | `canSendFromPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3309` | `canReplyAllFromPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3315` | `canEditProviderDraftPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3326` | `canReplyToPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3331` | `canMarkSpamPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3339` | `canCreateTicketFromPlacement` | query/capability helper | M28 |
| `Tech/MailWorkspace.php:3348` | `canOpenTicketFromPlacement` | query/capability helper | M28 |
| `Tech/MailWorkspace.php:3355` | `canLinkTicketFromPlacement` | query/capability helper | M28 |
| `Tech/MailWorkspace.php:3363` | `canUseAiTicketCreateAction` | query/capability helper | M28 |
| `Tech/MailWorkspace.php:3384` | `ticketConversationLinksForPlacement` | business operation or context | M28 |
| `Tech/MailWorkspace.php:3424` | `canUseRuleAction` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3433` | `canUseAiAction` | query/capability helper | M26 |
| `Tech/MailWorkspace.php:3448` | `canUseComposerAiAction` | query/capability helper | M26 |
| `Tech/MailWorkspace.php:3476` | `canUseComposerAiDraftReply` | query/capability helper | M26 |
| `Tech/MailWorkspace.php:3492` | `canCreatePersonalRuleForPlacement` | query/capability helper | M23 |
| `Tech/MailWorkspace.php:3501` | `canOpenAdminRulesForPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3511` | `personalRuleConditionOptions` | business operation or context | M23 |
| `Tech/MailWorkspace.php:3525` | `personalRuleActionOptions` | business operation or context | M23 |
| `Tech/MailWorkspace.php:3543` | `ruleExecutionAttemptsForPlacement` | business operation or context | M24 |
| `Tech/MailWorkspace.php:3563` | `hasArchiveTarget` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3568` | `hasTrashTarget` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3576` | `moveTargetFolders` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3591` | `hasMoveTargets` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:3596` | `classificationForPlacement` | business operation or context | M21 |
| `Tech/MailWorkspace.php:4563` | `sentReconciliationForPlacement` | business operation or context | M17 |
| `Tech/MailWorkspace.php:4573` | `remoteOperationLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:4585` | `remoteOperationBadgeClass` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:4597` | `isProviderDraftPlacement` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:5601` | `composerDraftAttachmentSizeLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/MailWorkspace.php:5778` | `composerModeLabel` | presentation or authorized query/capability helper | M01 |
| `Tech/SmartInboxReviewQueue.php:108` | `toggleReview` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/SmartInboxReviewQueue.php:119` | `closeReview` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/SmartInboxReviewQueue.php:128` | `analyze` | review operation or proposal context | M25 |
| `Tech/SmartInboxReviewQueue.php:142` | `beginCorrection` | review operation or proposal context | M25 |
| `Tech/SmartInboxReviewQueue.php:196` | `cancelCorrection` | presentation/preparation; no independent persisted outcome | M01 |
| `Tech/SmartInboxReviewQueue.php:203` | `saveCorrection` | review operation or proposal context | M25 |
| `Tech/SmartInboxReviewQueue.php:252` | `dismiss` | review operation or proposal context | M25 |
| `Tech/SmartInboxReviewQueue.php:280` | `apply` | review operation or proposal context | M25 |
| `Tech/SmartInboxReviewQueue.php:318` | `applySelected` | review operation or proposal context | M25 |
| `Tech/SmartInboxReviewQueue.php:353` | `alwaysDoThis` | review operation or proposal context | M25, M23 |
| `Tech/SmartInboxReviewQueue.php:400` | `render` | component lifecycle/projection | M01 |


## Completion Evidence (2026-10-20)

Current inventory: 175 Mail HTTP operations (109 write/action, 66 read), 144 path patterns,
all 72 browser routes explicitly mapped, 155 public Livewire declarations reviewed and registered.
All 44 capability groups have implemented API paths. M18/M19/M20/M44 preserve existing runtime gates.
The old State/Current-source wording above records the original gap analysis; the JSON retains
baseline_state and baseline_counts explicitly. There are zero missing Swagger operations.

See 2026-10-20-mail-api-verification.md, the maintained API contracts and browser parity manifest.
Ordinary Delete-to-Trash is approved and implemented in both browser and API. Personal rule context,
normal-reader execution history and Mail's combined Spam/optional Archive outcome are included.
No pending Mail product Orders 17-32 were replaced by endpoints or unimplemented placeholders.
