# Feature Slice: Microsoft 365 Provider Driver

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-email-microsoft-graph-provider-driver.md`
Owners: Integration / Email / Security Operations
Human Review: `HR-2026-08-16-027`

## Goal

Implement a first-class Microsoft Graph Mail driver with Integration-owned OAuth, immutable provider
identity, complete folder/message delta reconciliation, private webhook hints, drafts/send/Sent and
all supported normalized Mail operations. Migrate an account only through previewed, parity-checked,
single-writer cutover with bounded rollback.

## Dependencies

Orders 5-12 and 21 must be stable, especially secure credential/binding lifecycle, provider-originated
reconciliation, private invalidation, shared drafts/outbound and attachment quarantine. Order-24
permanent deletion and order-29 Calendar consume separately advertised capabilities and are not
silently enabled here.

## Additive Data Model

Reserve migrations `2026_08_16_166000` and `167000`.

Add Graph driver state owned by Integration/Email:

- connection cloud/mode, tenant/application/directory-mailbox identity hashes, consent/scope version,
  restricted-mailbox policy evidence and active token-version reference;
- per-account/folder opaque delta cursor, cursor generation, immutable-ID mode, last complete/start/
  finish state, safe health/error and reconciliation references;
- webhook subscriptions with opaque provider ID hash, resource/folder, client-state secret version,
  binding/scope version, lifecycle/renewal/expiry/status and safe events;
- bounded migration runs/items mapping old IMAP identities to Graph immutable IDs with source/target
  parity, ambiguity, activation and rollback evidence.

Provider access/refresh/client secrets and raw delta/subscription URLs are encrypted/private and never
copied into Email rows/jobs/audit. Jobs carry durable IDs plus expected positive binding/config/scope
versions. Down refuses while active accounts/cursors/subscriptions/cutover evidence depend on schema.
Migrations contact neither Microsoft nor current mailboxes.

## Integration Lifecycle

Integration UI supports delegated consent and application mode as explicit separate flows. Validate
OAuth state/PKCE/nonce/callback, current actor and expected cloud/tenant. Verify mailbox identity and
least scope before staging; activation uses the provider lifecycle/global bind mutex and drains Email
provider operations, IDLE/subscriptions/reconciliation/outbound work.

Application mode additionally requires tenant-admin consent plus machine-verifiable restricted
mailbox scope; inability to prove the restriction blocks. Safe UI shows provider/cloud/mode, tenant/
mailbox label, capabilities, consent/expiry/health and migration state, never endpoints, object IDs,
tokens, usernames or hidden mailbox counts.

Rotation, reconsent, scope expansion/reduction, mailbox/tenant identity change and revoke each create
an immutable version/event. Secret refresh may preserve binding only when tenant/mailbox/scopes are
identical. Identity/scope change requires a new binding and cutover/rebaseline. Revocation cancels
subscriptions, queued hints and new I/O fail closed.

## Driver And Reconciliation

Implement provider-neutral contracts for capabilities, folder hierarchy, bounded metadata/delta,
exact MIME/attachment reads, drafts, send, flags/categories, move/copy/trash and folder operations.
Every Graph request includes immutable-ID preference, fixed cloud host, exact mailbox resource,
bounded page/bytes/deadline and current binding under the shared account lock.

Initial sync and cursor recovery use bounded delta pages with resumable durable progress. A delta
token invalidation starts an explicit rebaseline preview; it never resets silently. Order-7 projectors
apply provider state, import exact new messages with rules/provider mutation disabled where required,
record conflicts with pending local operations and preserve personal unread.

Subscriptions omit resource content. After validation, change/lifecycle hints coalesce per account/
folder and dispatch delta catch-up. Renew before expiry, process reauthorization/removed/missed and
run scheduled delta even without hints. Duplicate, lost, late, reordered and revoked notifications
are harmless.

## Draft, Send And Operations

- Provider Draft ID and contained immutable message ID are modeled separately; update conflicts use
  current change/eTag evidence and shared draft-lock policy.
- Send uses the unified order-11 outbound reservation, exact frozen account/sender/recipients/MIME and
  one provider write boundary. Reconcile the eventual Sent item through immutable draft/message/
  Internet Message-ID evidence; response loss never replays blindly.
- Flags/read/categories, move/copy/trash and folders use the existing operation ledger with expected
  immutable provider ID/change token. Provider success is verified/reconciled, not inferred.
- Provider-specific throttling honors safe `Retry-After` within job/overall budgets and never logs raw
  response bodies/URLs/tokens/content.

## Migration And Rollback

Preview proves exact mailbox identity, authorized actor, source/target folder parity, bounded message/
draft/Sent/attachment samples plus whole-account durable attestation, unresolved work, token/scope and
private-storage readiness. Ambiguous mapping blocks or becomes an explicit reviewed item.

Apply pauses/drains old provider work, freezes both bindings, activates one new Graph generation,
projects mappings/cursors and leaves old evidence read-only. No message/rule/Ticket/notification/
personal state/provider flag is mutated by the migration itself. Rollback requires unchanged old
binding, no Graph-only write/submission or unresolved work and a fresh reverse preview. Otherwise use
reconciliation/rebaseline, never dual write.

## Permissions And API

Dedicated Integration permissions govern app registration/consent/application access; Email account
manage plus mailbox-sync manage govern account cutover. Mail users see only capabilities/actions their
ordinary account grants allow. API token abilities intersect exact account and provider capability;
OAuth/admin endpoints are not exposed to general Email API tokens. Config-only actors never read
mailbox content, samples or hidden counts.

## Tests

- OAuth state/PKCE/nonce/tenant/cloud/callback, delegated versus restricted application scope, least
  privilege, scope drift/reconsent/revoke/refresh and secret/telemetry/session safety.
- Immutable IDs on every request, move stability, folder hierarchy/roles, delta pagination/token
  expiry/rebaseline, duplicate/lost/reordered events and provider throttling/deadlines.
- Webhook validation/client-state/lifecycle/expiry/renewal/coalescing/rate/request caps, no resource
  content and scheduled delta recovery after missed/removed/revoked hints.
- Exact MIME/attachments, drafts update/conflict/send, eventual Sent, response-loss no replay, flags/
  categories/read, move/copy/trash/folders and operation conflicts; no personal-unread coupling.
- IMAP-to-Graph preview/whole parity/ambiguous mapping/apply/rollback, single writer, binding/identity/
  scope drift, in-flight operation/draft/outbound/reconciliation blockers and canonical/Ticket safety.
- Personal/shared/system/delegated/application mailbox authorization, non-enumerating UI/API, worker/
  scheduler/failed-job/health, migrations/down guard and affected Email/Integration tests.

## Documentation And Operations

Update Email/Integration/UserManagement Knowledge, provider consent/security/migration/incident
runbooks, API/OpenAPI, TODO, completion index and `docs/human-review.md`. Register a Dev-only Entra app
with least scopes and restricted expendable mailbox cohort; configure HTTPS webhook/lifecycle routes,
queue/scheduler and renewal. Deploy additive schema/permissions with `umask 0002`, clear caches,
rebuild group-writable views and restart workers. No migration creates consent/subscriptions/cutover.

`HR-2026-08-16-027` remains Pending until a named reviewer verifies real delegated and, if enabled,
restricted application consent; identity/scopes; delta/webhook loss; every Mail operation; migration/
rollback; privacy and sanitized health. Driver activation is a separate explicit action.

## Done Criteria

- [ ] Graph uses immutable provider identity and complete delta plus scheduled reconciliation, with
  private content-free webhooks only as latency hints.
- [ ] Integration lifecycle enforces least scopes, exact tenant/mailbox binding, safe rotation/
  revocation and restricted app-only access without credential/identity leakage.
- [ ] Draft/send/Sent and supported operations reuse provider-neutral ledgers and never duplicate on
  uncertainty or mix personal unread with provider read.
- [ ] Tests, migrations, UI/API, workers/scheduler, docs/runbooks and `HR-2026-08-16-027` are complete
  while named human review/provider activation remain Pending.
