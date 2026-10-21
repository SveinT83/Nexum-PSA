Status: Implemented on Dev, 2026-10-20. Main and production have not been promoted by this work.
Human-review checklist HR-2026-10-20-MAIL-API is Reviewed: Svein Tore approved the delivered scope on 2026-10-21. Existing default-off collaboration,
acknowledgement and live-runtime switches keep their independent approval gates.

## Start With The Correct Access

1. Open **API Management** and select or create a token for your own active user.
2. Select the Mail abilities needed for the operation. Existing tokens do not receive new scopes
   automatically. A token scope is a ceiling; it never creates mailbox View, Organize or Send access.
3. Open **Swagger** at /api/documentation on the intended installation. Authorize with the bearer
   token. Expand the relevant Email group to see exact paths, required fields and errors.
4. Read /api/v1/email/mailbox/context, then the paginated accounts and senders endpoints.
   View-authorized accounts and Send-authorized identities are separate lists.

Account configuration requires email.account_manage and the provider-management policy; it grants
no mailbox content access. Templates require email.template_manage. Rule management, publication,
canonical cutover and maintenance keep their specific browser permissions. Inactive or system
identities cannot use the new human Mail operations.

## Read, Search And Personal State

Use GET /api/v1/email/mailbox/conversations with view, filter, account_id, folder_id, q, page and
per_page. It shares the browser's account-scoped grouping and literal search. Follow pagination
and truncation metadata; legacy conversation context is bounded at 200. Read the exact selected
placement before an action.

Reading a resource does not mark it read. POST .../placements/{placement}/opened explicitly records
opening. POST .../placements/{placement}/read-state with {"read":true} changes the user's personal
state. Shared/delegated mail stays local; a personal owner also requests provider Seen through the
operation ledger. Inspect local_status and provider_status separately. read:false reverses personal
state. These commands do not acknowledge Notifications, Tickets or another user's mail.

Attachment and raw-source downloads require their own token scopes and current exact source
access. Raw source has separate permission. Downloads are private and preserve source-part identity.

## Write And Action Workflows

- **Compose / Reply / Reply all / Forward:** create a private draft, use its opaque version on every
  edit/upload/removal, preview that version, then send with a stable idempotency_key. Read the
  submission and Sent reconciliation. Acceptance, unknown outcome, proven Sent copy and recipient
  delivery are different states.
- **Trash / Archive / Move / mailbox read / flag:** use the exact placement operation. Ordinary
  DELETE in both Inbox and API requests provider Trash, preserving the local source. Missing Trash
  is a blocker, never expunge. Folder create/rename/move/delete share the provider ledger.
  Empty-into processes at most 50 placements per call, with per-item outcomes and remaining count.
- **Send/receive:** POST account refresh. folder_id is validated but effective work is account sync.
  A 202 receipt means queued; GET refresh status reports later progress.
- **Rules:** source rule-context, administrative draft/validate/publish-preview/publish, personal-rule
  creation and execution/reprocess/undo APIs expose the existing browser outcomes. Publication needs
  a current checksum and publication permission. Smart Inbox prefill only prepares reviewed input;
  batch apply returns independent item results.
- **AI and signature:** summary and composer-assist obey the configured agent and egress/runtime
  policy. Assistance does not send, change recipients or apply provider work. Signature GET is pure;
  PATCH saves your sanitized signature and supplied preferences.
- **Ticket:** selected-placement create/link/suppression requires both domains' normal permissions
  and Ticket work context. Creation and Mail provenance commit together. Mail stays in its provider
  mailbox and TD-number correlation remains in place.
- **Delegation:** a personal owner creates a reasoned bounded grant, reads it back and explicitly
  revokes it. Revocation removes subsequent access. Emergency access requires separate permission,
  exact mailbox confirmation, duration and independently selected powers.
- **Unread handover:** preview an exact account/folder/date/count/target snapshot, read it, then apply
  that run. Current access epochs are checked again. Newly applied and already-unread items are
  separate outcomes. Provider Seen remains unchanged.
- **Administration:** account PUT is a full form. Passwords are write-only; blank update values
  preserve existing account-owned credentials. Saving queues connection tests and keeps the account
  inactive until incoming and outgoing tests succeed. Activation uses an explicit active boolean.
  Settings PUT and template create/update/preview share the validated browser behavior.
- **Maintenance:** preview bounded import or cursor rebaseline, inspect fingerprint/expiry/status,
  then start/apply the exact run. Canonical preview/review/attestation/apply/rollback and correlation
  recovery remain operator-only and preserve existing gates.

## Errors And Recovery

401 requires authentication. 403 means current token/user/policy denial. 404 hides inaccessible or
missing exact resources. 409 means version/source/preview evidence changed: reload or preview again.
422 contains validation errors or a domain blocker. 423 is an occupied shared draft lease. 503 means
a required integration/runtime is unavailable. Do not enable switches merely to make a call succeed.

After timeout, read the draft/submission/operation/run before retrying. Reuse the same idempotency key
for the same logical action. Never repeat an unknown SMTP write as a fresh send.

## Verification And Availability

The Dev Mail suite passed 812 tests / 9,259 assertions; eight optional tests were skipped.
Final adapter/Swagger evidence is in docs/plans/2026-10-20-mail-api-verification.md. Tests do not prove
real IMAP/SMTP writes, inbox delivery, employee OpenWebUI retrieval or production rollout.
HR-2026-10-20-MAIL-API was approved by Svein Tore on 2026-10-21. Main promotion, deployment and release remain separate actions owned by Svein Tore.

Authoritative source: app/Modules/Email/Docs/knowledge/mail-api-operations.md.
Contract inventory: app/Modules/Email/Docs/email-api-contracts.json.
