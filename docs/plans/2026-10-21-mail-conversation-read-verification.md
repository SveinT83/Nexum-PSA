# Mail Conversation Read Command: Dev Verification

Date: 2026-10-21. Owner: Codex. Product approval: Svein Tore, "Godkjenner. Iverksett".
Status: implemented on Dev, merged to Main and deployed to production; human review In Review.
RFC: ../rfc/2026-10-21-mail-conversation-read-command.md.
ADR: ../adr/2026-10-21-ordinary-mail-conversation-read-boundary.md.
Human-review checklist: HR-2026-10-21-MAIL-CONVERSATION-READ in ../human-review.md.
Svein Tore subsequently authorized scoped Main promotion and production deployment because Dev
does not receive mail. This overrides the previous deployment hold; the remaining real-mail checks
stay open for controlled production validation.

## Resulting Behavior

The reader has a split Mark as read/unread button. The primary button continues to affect one
selected email. Its arrow prepares an exact snapshot of the whole authorized conversation in the
active account and displays that mailbox and the counted Mark all N messages in conversation as
read action. Inbox, Sent and other-folder context is included even outside the current list filter.
The menu supports mouse, keyboard and touch. Enter opens it; Escape and outside click close it.

Preparing or closing the menu changes no read state. Applying uses the frozen membership: later
arrivals stay unread, another account is not expanded into the scope, and other users' personal
states do not change. Duplicate placements coalesce the personal effect. A stale menu cannot
retarget another conversation. Authorization and account/access epoch, placement, binding and
provider UID evidence are checked again when work is applied.

The ordinary scope reuses the durable conversation acknowledgement run/item ledger. Only this
one-account, fixed-read operation is independently available when its installed tables exist.
The advanced multi-account acknowledgement flag remains false; HR-2026-08-16-012 stays In Review.
Shared/delegated accounts change the actor's personal state only. A current personal owner also
requests provider Seen through the existing remote-operation ledger with current Organize checks.
Provider pending/failed/denied results are separate from successful personal reads. No automatic
cross-account expansion or broad permission change was introduced.

More than 25 placements uses the existing bounded continuation job. Browser/API summaries refresh
from persisted results, including when a synchronous continuation has already completed before
an HTTP response. Refresh status reads results; it never applies a preview. Already-read selected
mail does not hide the conversation command. Incomplete legacy identity or scope over the configured
preview cap is refused before acknowledgement; a visible page is never silently treated as all.
The current default cap is 100 placements, with the existing hard maximum retained.

## Implementation Scope

- Email model: EmailConversationActionRun ordinary scope discriminator.
- Shared actions: PreviewEmailConversationAcknowledgement, ApplyEmailConversationAcknowledgement
  and CancelEmailConversationAcknowledgement.
- Runtime services: EmailConversationAcknowledgementBoundary and its unavailable exception.
- Shared projection: new Support/EmailConversationReadSummary.php, used by browser and API.
- Browser: Livewire/Tech/MailWorkspace.php and Views/Livewire/Tech/mail-workspace.blade.php.
- API: ConversationActionsController and MailboxReadController discovery; existing guarded routes,
  abilities and mailbox ceilings remain authoritative. Advanced-disabled API returns HTTP 503.
- API contracts: Docs/email-api-contracts.json, Docs/mail-browser-api-parity.json,
  Support/EmailApiDocumentation.php and generated storage/api-docs/api-docs.json.
- Tests: EmailConversationAcknowledgementSafetyTest adds seven ordinary-command regressions.
- Documentation: RFC/index, ADR, TODO, human-review entry, README-email-system.md and the two Email
  Knowledge sources, plus this report and the public-safe website handoff.

The API ordinary preview takes scope_kind=ordinary_conversation_read and placement_id. The server
derives the account, durable conversation, personal-read target and owner-only provider behavior.
Caller-supplied alternate scope members or provider/read-target options are rejected. Apply, retry,
cancel and status use the existing exact-run endpoints and same actions as the browser. Context
discovery and the response's read_summary are documented in the typed OpenAPI contract.

## Verification Evidence

The final affected Dev package passed 105 tests with 2,263 assertions in 76.80 seconds. Coverage
includes the existing acknowledgement, unread, remote-operation/undo and documentation contracts.
Seven added regressions cover full membership outside the filter, duplicates, future arrivals,
actor/account isolation, ordinary API while the advanced gate is false, primary-button behavior,
provider authorization/outcome separation, cap/legacy rejection, revocation, stale menus, bounded
27-message continuation and persisted read-back after synchronous continuation. The new synchronous
continuation regression failed before the correction (stale 202 instead of completed 200) and passed
after refreshing the persisted run/items. Initial new ordinary regressions failed before implementation.

All 12 affected PHP files passed syntax and Pint checks. Scoped changed-line whitespace passed.
Swagger was regenerated successfully; the ordinary scope, placement input, typed summary and schema
references were checked. No new frontend package or asset build is required.

Real headless Edge/Playwright checks used a separate synthetic SQLite runtime, never the shared
Dev database or production mailbox. Desktop 1440x1000 and mobile 390x844 menus were visually inspected.
Enter/Escape, opening/closing, narrow-screen bounds and touch apply passed with zero JavaScript
errors. Independent SQLite/read-state read-back after touch apply showed six synthetic messages,
six read for the synthetic actor and zero provider operations. The temporary server and SSH port
forward were stopped after verification. Evidence is retained outside the repository under
/tmp/nexum-conversation-read-20261021 and the local temporary folder of the same name.

Installed Dev ledger schema was read back: operation varchar(48), scope_kind varchar(40), both
required tables present. The broad advanced flag was false and shared Dev had zero ordinary-scope
runs at the live read-back. No shared mailbox was acknowledged and no actual provider write was
performed for this work. Existing overlapping dirty Dev work and the ordinary Git index are preserved.

## Handbook And Access

Authoritative Knowledge sources are email-inbox-overview.md and mail-api-operations.md under the
Email module's Docs/knowledge. BookStack page 495 (Email Inbox Overview), revision 74, and page 636
(Mail API Operations (Dev)), revision 6, were published and independently read back on 2026-10-21.
They explain the exact menu, counted snapshot, later arrivals, provider separation, limits and
production availability with this human review still In Review. Both pages and their chapter inherit
permissions; the parent book retains its existing overrides. The existing internal staff Editor
role has book/chapter/page view rights. No access scope or permission was changed. Employee
OpenWebUI retrieval was not tested and is a separate verification claim.

The public-safe website handoff describes the user benefit and Dev verification, with an explicit
hold until the remaining real-mail human review and editorial approval; Main and production
rollout are complete.
It must not be published as production functionality from these Dev tests alone.

## Remaining Human Review And Deployment

Svein Tore must perform the concrete checklist HR-2026-10-21-MAIL-CONVERSATION-READ: use a six-mail
thread with one mail in the list filter, verify primary versus counted whole-thread actions, menu
no-op behavior, later-arrival exclusion and actor/account isolation, then use a controlled personal
mailbox for real provider Seen read-back and the intended worker for a larger continuation.
Check truthful partial/failure results and employee handbook availability. Passing automated tests
and approval of the RFC do not complete that checklist. The broader August review stays independent.

No migration, data backfill, package installation or new frontend build is needed. The explicitly
authorized production rollout completed normal cache/Swagger/worker actions, documented in the
production report. No deploy command remains pending for this release. Future authorized deployments
require the installed ledgers and default queue worker; normal maintenance commands include:

```sh
php artisan optimize:clear
php artisan l5-swagger:generate
php artisan queue:restart
```

These are deployment instructions, not authorization to run them in production. Leave the advanced
acknowledgement/live/collaboration runtime flags unchanged. The generated Swagger file contains
other already-dirty Dev API work: regenerate it from the reviewed promotion
candidate rather than copying the whole Dev artifact into an unrelated release.

The initial Dev-only verification performed no commit, push or deployment. The subsequent explicit
production request was fulfilled through PR #305 and application SHA
71ce9ff10d2e04220b557e56dc206c2ddc1d883d; see the production deployment report.
The earlier Trash correction in PR #304 is separate. Rollback removes ordinary command exposure
while retaining action, audit, provider and completed personal read-state history.

## Controlled Production Rollout (Completed; Human Review In Review)

Svein Tore explicitly requested production deployment on 2026-10-21 because Dev does not receive
mail. This includes the scoped Main promotion needed for that deployment. No manual scenario is
inferred as passed; HR-2026-10-21-MAIL-CONVERSATION-READ is In Review. The advanced August review,
selected-read/account-setup/live/collaboration/rules/compose/correlation and old missing-mail/save
checks retain their independent status and are not approved by this request. Broad runtime flags
stay off. Production preflight found both installed ledgers, the database queue and all five
advanced/live/collaboration flags false. Production starts at application SHA
11cf40227e9dfa52747b96cdf58e5f64ae706f43; Main includes the separately reviewed Trash correction
through PR #304. This controlled code-only rollout includes that existing Main correction.

Release assembly uses a separate Git index and an immutable Main export on the Dev host. Every
existing application preimage equals Main, apart from the overview's prior Main-status wording;
only the selected feature files and scoped documentation are included. Shared Dev HEAD/index
and unrelated dirty paths remain unchanged. Final Main, deployment and independent verification evidence is recorded in
2026-10-21-mail-conversation-read-production-deployment.md.
