# Feature Slice: Email Mail Compose, Draft, Send, And Sent API Parity

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADRs:
- `docs/adr/2026-08-11-email-server-authoritative-folders-and-sync.md`
- `docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`
Owner: Svein / Codex
Human Review: `HR-2026-08-16-011`

## Goal

Expose the existing Mail compose, local/provider Drafts, attachment, SMTP reservation, and Sent
reconciliation behavior through one reusable Email-owned action boundary and a complete REST API.
The web workspace and API must produce the same validated content, signature, threading headers,
provider-binding evidence, idempotency result, ambiguous-outcome behavior, and Sent projection.

This is not a second sending implementation. No controller, Ticket workflow, automation, or service
token may construct its own SMTP transport or retry a delivery whose provider outcome is uncertain.

## Dependencies

Implementation starts after completion orders 6 and 9 are stable and re-read:

- Integration-owned provider credentials and immutable provider-binding versions;
- reading/typing presence, shared-draft ownership, lease fencing, and stale-composer protection;
- the current `SendEmailComposerMessage`, `EmailComposerDraftService`, provider Drafts sync,
  `SmtpAccountMailer`, `EmailLog`, `EmailSentReconciliation`, private storage, Mailbox access, and
  canonical source/placement read boundaries.

Order 10 may call the final outbound action later, but a rule or automatic-reply policy is never
authorized merely because this API exists.

## User And API Behavior

- A caller may list, create, read, update, manually sync, discard, and send only drafts in an exact
  currently authorized mailbox account. Reply, Reply All, Forward, provider-Draft editing, and new
  Compose retain the same defaults and restrictions as the Mail workspace.
- Draft updates use an opaque version/fencing token from order 9. A stale client receives a conflict
  with current safe metadata; its content is never silently overwritten or sent.
- Local autosave is the default. Provider Drafts `APPEND` is an explicit operation and preserves the
  existing ambiguous-APPEND/no-replay state machine. The API never treats an unresolved provider
  draft as successfully synchronized or safe to append again.
- Attachment upload, listing, removal, and send use the same private-storage, count, size, filename,
  MIME and future malware-policy boundary as the workspace. Resources expose no disk or path.
- Send accepts one caller-generated idempotency key. The first request atomically reserves an
  immutable outbound snapshot before SMTP. Concurrent or repeated requests return that same
  submission and never create another provider delivery.
- A confirmed SMTP acceptance returns the durable submission, Email log, and Sent-reconciliation
  status. Local failures after acceptance are warnings/reconciliation work, never a false "not sent"
  response.
- A lost or ambiguous SMTP result becomes `outcome_unresolved`. The API returns a stable conflict or
  accepted-for-review response and explicitly forbids blind retry with the same or a new key. Only an
  exact Message-ID/Sent reconciliation may resolve it as accepted; an operator-approved fresh send
  requires a new reviewed draft generation, not replay of the old submission.
- Provider-binding, account, access, draft version, placement, recipients, attachments, body,
  signature, and policy are rechecked immediately before the provider boundary. A stale binding
  fails before network access.

## One Outbound Submission Boundary

Reserve one additive migration after order 10, currently `2026_08_16_134000`; renumber only if an
earlier dependency occupies it. Add `email_outbound_submissions` as the authoritative request and
outcome record rather than using controller-local state or interpreting free-form Email log text.

Each row stores only the information required to prove the operation:

- opaque public UUID, account, actor/system-policy owner, optional composer draft, source message,
  source placement and conversation references;
- mode, caller channel (`mail_web`, `api`, or a later named guarded workflow), normalized client
  idempotency key, immutable request fingerprint, frozen draft/version token, provider-binding
  version, signature policy/version, and attachment-manifest hash;
- reserved RFC Message-ID and status (`reserved`, `provider_write_started`, `accepted`,
  `outcome_unresolved`, `provider_not_attempted`, `sent_reconciled`, `cancelled_prewrite`);
- linked Email log and Sent-reconciliation rows, safe result/reason code, tokenized lease evidence,
  reserved/started/accepted/reconciled timestamps, and normal audit timestamps.

Use a database unique key that elects exactly one submission for the actor/account/channel/client
idempotency identity. The immutable request fingerprint includes normalized To/Cc, subject, sanitized
body, exact ordered attachment checksums/sizes/names, mode, source placement/threading evidence,
account, actor, and signature selection. Reusing one key with a different fingerprint returns a
conflict and exposes neither version.

The submission becomes the single pre-SMTP reservation. Refactor `SendEmailComposerMessage` into a
reusable action that web and API call; keep `EmailLog` as delivery/audit compatibility evidence and
`EmailSentReconciliation` as provider Sent evidence. Do not add a second check-then-send window.
State changes are row-locked/token-owned, terminal acceptance cannot be overwritten by a losing
error path, and stale lease takeover is allowed only while evidence proves provider write never
started.

## Draft And Attachment Boundary

Order 9 owns the final shared-draft schema. This slice adds only API-facing opaque identifiers or
submission references still needed after that schema is stable.

- Draft list/read queries are scoped by current ordinary View and Send, participant/lock authority,
  account, status, and current generation. Break-glass never grants compose, draft, or send access.
- Reply/Forward drafts bind to the exact active source placement. Account or placement drift makes
  the draft stale; it does not silently retarget another copy of a canonical message.
- New Compose binds to one explicit send-authorized account. The API never chooses a default account
  for an account-unbound service token.
- Attachment rows must belong to the exact draft generation and authorized participant. Reusing an
  attachment from another draft, user, revoked mailbox, or prior generation fails non-enumerating.
- Multipart upload streams to private storage with bounded size; it does not load unbounded bytes
  into a queue payload or serialize temporary paths. Partial persistence rolls back rows and removes
  only files created by that failed request.
- Discard and post-send cleanup preserve unresolved provider Draft evidence and immutable submission
  evidence. File deletion follows private-storage and retention rules and cannot hide an ambiguous
  send.

## Threading, Content, And Signature Parity

- Reply/Reply All use the selected source's normalized Message-ID and References exactly as the web
  composer. Forward never claims a reply relationship.
- Recipient parsing, deduplication, own-address removal, line folding, subject prefixing, Unicode,
  HTML sanitization, semantic-empty validation, plain-text generation, and size caps share one
  service. Controllers do not duplicate them.
- Signature content is selected and appended once at send snapshot time. The draft resource returns
  signature mode and safe preview metadata, not a second body that can cause double append.
- Sent APPEND/reconciliation uses the reserved Message-ID and exact provider-binding version. A
  normal provider poll winning the race is terminal success, not a reason for another APPEND.
- API resources continue to expose the original source identities authorized by the route. They do
  not expose canonical IDs, private paths, raw MIME, provider credential references, recipient Bcc,
  exception text, or SMTP secrets.

## REST API And Abilities

Add request-ceiling abilities to `ApiAbilityCatalog`:

- `email.drafts.read` for authorized draft metadata/content and attachment metadata;
- `email.drafts.write` for create/update/upload/remove/manual provider-sync/discard; and
- `email.send` for submission preview and send.

The caller also needs the exact runtime mailbox View/Send decision and draft participation/lease.
Service/workload tokens are bound to explicit account IDs and allowed modes; they cannot impersonate
an arbitrary technician, use a personal mailbox, select a signature owner, or rely on a default
account unless a separately reviewed workload binding explicitly permits it.

Version the resources under `/api/v1/email/mailbox`:

- draft index/store/show/update/discard;
- attachment store/destroy (and guarded content download only if the existing private-file policy
  explicitly supports draft download);
- provider Draft sync/status;
- send preview, create submission, submission show/status; and
- Sent-reconciliation status for the exact submission.

Return non-enumerating not-found responses for account, source placement, draft, attachment,
submission, and reconciliation outside current scope. Use normal validation responses for malformed
input, `409` for stale version/idempotency mismatch/unresolved outcome, `423` for a valid draft lock
held by another participant, and `202` only when durable asynchronous work actually exists. API
documentation describes that a timeout is not permission to resend.

## Bounds And Queue Contract

- At most five attachments and the existing per-file/message byte limits; request-body and PHP/web
  server limits are documented and tested.
- List endpoints use capped pagination and stable ordering. Submission resources never embed raw
  attachment bytes or full provider evidence.
- Queue payloads contain IDs, expected draft/fencing version, expected provider-binding version, and
  lease token only. They do not contain bodies, recipients, attachments, credentials, or raw MIME.
- SMTP remains one bounded synchronous provider boundary behind the durable reservation unless a
  later separately tested queue handoff preserves the exact same reservation/lease semantics.
- Provider Draft and Sent work use the Email account provider lock and disconnect in `finally`.
- Worker redelivery, HTTP timeout, PHP termination, and database failure at every pre-/post-SMTP
  seam are explicitly tested.

## Out Of Scope

- Automatic replies, bulk/marketing mail, arbitrary Bcc, scheduled sending, recall, delivery/open
  tracking, provider search, and blind resend.
- Ticket-specific audience/policy/compose behavior; orders 16-17 reuse this action after their own
  authorization and recipient previews.
- Giving service tokens general personal-mailbox access or allowing break-glass to send.
- Replacing provider Drafts or Sent as provider-authoritative folders.
- Treating an SMTP acceptance as proof that the recipient read or even received the message.

## Data Touched

- New outbound-submission audit/state rows and optional opaque draft identifiers.
- Existing composer drafts/attachments, Email logs, Sent reconciliations, provider Draft state,
  signatures, mailbox placements, private files, API routes/resources and ability catalog.
- Provider Draft/Sent/SMTP state only after the exact explicit action; preview and ordinary local
  autosave produce no provider mutation.

## Tests

- Web/API parity for Compose, Reply, Reply All, Forward, and provider-Draft edit, including recipients,
  threading, Unicode subject, sanitized HTML/plain text, semantic empty body, and one signature.
- Draft list/create/update/version conflict, lock ownership/revocation, participant changes, source
  placement/account drift, personal/shared/system access, and break-glass exclusion.
- Attachment upload/list/remove/send, cross-draft/user/account/generation denial, path traversal,
  partial failure cleanup, missing file, MIME/size/count bounds, and no private-path serialization.
- Concurrent identical send, same-key/different-body conflict, different-key duplicate-warning path,
  stale draft/provider binding before network, and one exact SMTP call.
- Failure before SMTP, during ambiguous SMTP return, immediately after acceptance, during Email-log
  update, draft cleanup, provider Sent APPEND, and normal-poll-first reconciliation. Prove no blind
  replay and truthful accepted/unresolved results.
- Tokenized lease/redelivery/losing-worker races and terminal accepted/reconciled state immunity.
- API ability plus exact account binding, revoked access between request and send, non-enumerating
  IDs, pagination, rate/request bounds, OpenAPI schema and no secret/raw/canonical exposure.
- Legacy workspace behavior and existing EmailLog/Sent reconciliation compatibility, provider Draft
  ambiguity, private-storage, Notification/Ticket non-side-effects, and credential-rotation tests.
- Migration/backfill/down guards, route/cache/view build, queues, worker restart, and affected Email,
  Integration, Ticket and API suites.

## Documentation And Operations

Update Email README and Knowledge, Integration API management/ability documentation, OpenAPI,
private-storage operations, TODO, completion index, and `docs/human-review.md`.

Deploy additive migrations and permission/ability seeds with `umask 0002`, clear caches, rebuild
group-writable views, and restart default/Email workers. Deployment does not send, sync a Draft,
append Sent, migrate a user's local draft, retry an unresolved result, or expose a new service token.
First Dev validation uses a clearly internal recipient only after checking current notification/mail
settings and records whether mailbox receipt is independently confirmed.

`HR-2026-08-16-011` remains Pending until a named reviewer checks every compose mode, attachment and
signature parity, stale/shared draft behavior, API account/token scope, duplicate submission,
ambiguous SMTP behavior, Sent reconciliation, provider-binding rotation, private storage, worker
health, sanitized evidence, and browser/API responses.

## Done Criteria

- [ ] Orders 6 and 9 are stable and their provider-binding/draft-fencing contracts are used directly.
- [ ] Web and API share one outbound submission, validation, signature, SMTP, and reconciliation
  action with one pre-provider reservation and no blind retry.
- [ ] Drafts, attachments, submissions and statuses have explicit abilities, account/participant
  scope, execution-time reauthorization, version conflicts, and non-enumerating resources.
- [ ] Failure/race tests prove one delivery, truthful unresolved outcomes, safe Sent reconciliation,
  immutable evidence, and no secret/private-path/canonical-ID exposure.
- [ ] Focused and affected-module tests, migration guards, OpenAPI, docs, Knowledge, deploy steps, and
  `HR-2026-08-16-011` are complete while human review remains Pending.
