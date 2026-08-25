# Feature Slice: Email Compose, Draft, Send, and Sent API Parity

Status: Private/API Rework Implemented / Human Review Pending; Shared Collaboration Gated
Date: 2026-08-19
Reworked: 2026-08-24
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 11)
Review ID: `HR-2026-08-16-011`
Authoritative contract: `docs/feature-slices/2026-08-16-email-mail-compose-draft-send-sent-api-parity.md`

## Outcome

Order 11 now has one Email-owned private draft and outbound-submission boundary for the Livewire
Mail workspace and `/api/v1/email/mailbox`. The 2026-08-21 selected-placement repair remains intact.
The 2026-08-24 rework additionally closes the cross-user active-draft lookup, adds opaque optimistic
fencing, exact attachment-generation ownership, a durable pre-SMTP submission ledger, stable
accepted/unresolved status recovery, and provider Sent reconciliation of that submission.

This does **not** activate Order 9. Shared drafts, leases, typing/presence, and `423` lock behavior
remain unavailable while the collaboration schema is quarantined. Every ordinary draft created or
restored by this slice has explicit `private` scope and one human owner; API requests for `shared`
scope fail validation. That is the safe beta contract until a corrected Order 9 forward migration
and named review exist.

## Implemented Boundary

- `email_composer_drafts` gains an opaque public UUID, explicit private scope, immutable generation
  UUID, and monotonic version. HMAC fence tokens reveal none of those inputs and stale mutations
  return `409` with current safe metadata.
- Draft and attachment lookup always includes owner, private scope, active status, exact generation,
  current mailbox access, and for Reply/Reply All/Forward/provider Drafts the exact active provider
  placement. A send-only Compose account never grants mailbox read access.
- Draft attachments gain opaque IDs and generation evidence. API resources expose filename, MIME,
  size, and position only; disk, path, checksum, user, and generation evidence remain private.
- `email_outbound_submissions` elects one actor/account/channel/client-key request and one exact
  draft-generation/version snapshot before SMTP. It freezes the request fingerprint, attachment
  manifest hash, provider-binding version, signature evidence, reserved Message-ID, safe state,
  Email log link, and Sent-reconciliation link.
- A claimed draft becomes `send_reserved` before the lower provider boundary. Draft edits,
  attachment mutations, discard, and another idempotency key cannot race that generation. A
  provider-not-attempted result may return the same generation to `active`; an ambiguous result
  remains reserved and forbids blind retry.
- Confirmed acceptance stays accepted even if local draft cleanup fails. Repeating the exact client
  key returns the same submission without SMTP. A normal same-account Sent import with the exact
  reserved Message-ID moves the submission to `sent_reconciled` without a provider write.
- `SendEmailComposerMessage` owns the shared recipient, HTML/plain-text, signature, attachment, and
  threading preparation. `SubmitEmailComposerDraft` owns preview, fingerprint, reservation, send,
  outcome classification, cleanup, and reconciliation linkage. Livewire and API call this same
  boundary.

## REST API And Abilities

The versioned routes are under `/api/v1/email/mailbox`:

- draft index/store/show/update/discard;
- attachment upload/removal;
- explicit provider-Drafts sync;
- exact outbound preview and send;
- submission status; and
- Sent-reconciliation status for the submission.

Token abilities are request ceilings:

- `email.drafts.read` for private draft reads;
- `email.drafts.write` for private draft and attachment mutations plus explicit provider sync; and
- `email.send` for preview, send, submission, and reconciliation status.

The current human user must still be active and non-system, hold the normal Email permission, and
retain exact mailbox View/Send access. Out-of-scope accounts, placements, drafts, attachments, and
submissions return non-enumerating Not Found responses. Resources do not return private paths, raw
MIME, Bcc, credentials, canonical IDs, exception text, or raw provider evidence. A timeout or `409`
is never permission to resend.

## Migration And Deployment

Additive migration:
`2026_08_24_110000_add_private_draft_fencing_and_outbound_submissions.php`.

The migration was intentionally **not** run during implementation. It later ran in Dev batch 124
after disposable review and backup, preserving the one existing private draft and leaving the
outbound ledger empty. Other installations apply it only while normal code deployment is
coordinated. It backfills
opaque draft/attachment identity, creates the outbound ledger, and refuses rollback while submission
evidence exists. No Order 9 table or collaboration flag is created or enabled. No queue, scheduler,
cron, provider configuration, or provider mailbox was changed by this rework.

The added draft/attachment public and generation columns intentionally remain nullable at schema
level for rolling-deploy compatibility with the old table shape. The migration backfills all rows
present during its run; current `EmailComposerDraft` and `EmailComposerDraftAttachment` creating
hooks always generate opaque IDs and generation evidence for new application rows. Deployment must
drain old code writers during cutover and verify zero null identities afterward before reopening
normal traffic. A later separately reviewed hardening migration may add `NOT NULL` after the rolling
window; this slice does not pretend a nullable column alone is the invariant.

## Verification

Isolated SQLite verification uses `DB_DATABASE=:memory:`, an explicit unused
`APP_CONFIG_CACHE`, array maintenance/cache stores, `HOME=/tmp`, fake private storage, and fake SMTP.
The focused Order 11 plus adjacent composer package passes 22 tests / 228 assertions, including:

- two users composing privately in the same account and non-enumerating cross-user access;
- stale version conflicts without overwrite;
- shared-scope rejection while collaboration storage is unavailable;
- safe attachment resources and cross-draft/user mutation denial;
- preview/send signature and exact body parity;
- one accepted SMTP call across repeated requests;
- unresolved-provider no-retry with the draft generation fenced;
- provider-binding drift and access revocation before provider access;
- exact Sent reconciliation of the outbound submission; and
- existing placement, lifecycle, and post-SMTP safety regressions.

No real provider call, shared database mutation, config/cache mutation, queue/scheduler change, or
runtime activation was made.

## Remaining Gate

`HR-2026-08-16-011` remains Pending. A named human must review the disposable migration and exercise
the private web/API matrix with intercepted SMTP and a controlled Sent observation. Shared drafts
remain an Order 9 dependency and must not be inferred from this private-scope completion.
