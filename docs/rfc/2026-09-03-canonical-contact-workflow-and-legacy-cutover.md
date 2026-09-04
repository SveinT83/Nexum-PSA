# RFC: Canonical Contact Workflow And Legacy Cutover

Status: Done On Dev
Date: 2026-09-03
Owner: Codex
Related Issue: GitHub #253
Human Review: Approved by Svein on 2026-09-04; production cutover operations remain pending


## Context

Nexum currently exposes two person workflows. The Contact domain creates canonical Contact records,
communication endpoints, and polymorphic Client/Site relations. The Client workspace still creates,
edits, lists, and opens legacy client_users rows directly.

This split makes the same person appear differently across Clients and Contacts. It also leaves old
production client contacts outside the canonical Contact domain unless an operator remembers to run
a separate command. Marketing, Telephony, Intake, Customer Portal, Signals, Tickets, Sales, Assets,
Nextcloud, and User Management consume either canonical Contact IDs, legacy Client User IDs, or both.
A cutover must therefore preserve existing identity and history instead of deleting or renumbering
legacy bridge rows.

## Goals

- Make Contact the only technician-visible contact create, edit, list, and detail workflow.
- Let Client and central Contacts entry points open the same Contact form with explicit Client/Site
  context.
- Make Client creation create its primary person through the Contact-owned StoreContact action.
- Keep duplicate detection, validation, permission enforcement, relation persistence, and audit
  behavior behind the canonical Contact boundary.
- Automatically create and link canonical Contacts for every legacy client_users row during the
  normal production migration.
- Preserve every legacy client_users ID so Tickets, Assets, Sales, Nextcloud, and other compatibility
  consumers retain their references.
- Add canonical Contact IDs to existing Marketing, Telephony, Intake, and other dual-identity records
  without removing their historical legacy identity.
- Enrich Marketing delivery identity evidence without weakening the lifetime no-resend invariant.
- Make the cutover idempotent, fail closed on ambiguous identity conflicts, documented, and covered
  by cross-module regression tests.

## Non-Goals

- Drop the client_users table or renumber its rows.
- Convert every legacy Ticket, Asset, Sales, or Nextcloud column in this release.
- Merge ambiguous people solely because their names are similar.
- Replay, resend, rebuild, or delete Marketing delivery or engagement history.
- Redesign Customer Portal roles or invitation policy.
- Add a manual Contact merge interface.

## Current Behavior

- The Client workspace Contacts tab reads Client::contacts(), which returns client_users rows.
- Its New Contact action opens the Clients-owned legacy form.
- Central Contacts uses Contact, ContactEmail, ContactPhone, and ContactRelation.
- StoreContact maintains a client_users compatibility row when Site context exists.
- The optional contacts:migrate-client-users command creates canonical Contacts, but deployment does
  not run it automatically and it does not reconcile downstream dual-identity records.
- Marketing can retain both contact_id and client_user_id. Its durable delivery ledger protects
  Contact, Client User, and normalized-email identities independently.

## Proposed Change

### Canonical Technician Workflow

The Client Contacts tab will query canonical Contacts through a Contact-owned query and link directly
to the existing Contact create, detail, and edit routes. Explicit Client and optional Site context
will be passed into the same Livewire Contact form used by the central Contacts workspace.

The legacy Client User GET routes will remain as compatibility redirects to canonical Contact routes.
Legacy POST and update routes will normalize their payload and call StoreContact so bookmarks or old
form submissions cannot create a second person model. Route permission aliases will enforce Contact
permissions before the broader Client route patterns.

CreateClientWithDefaults will call StoreContact for the primary person. StoreContact will keep the
existing bridge row ID stable when updating Contact context and will record one Contact-owned audit
event for every canonical save.

### Internal Compatibility Bridge

client_users remains internal compatibility state. Existing rows are linked through contact_id and
are never deleted merely because the canonical Contact is edited. Keeping the bridge IDs stable
preserves foreign-key and polymorphic references in Tickets, Assets, Sales, Nextcloud, Marketing,
Telephony, Intake, and other historical records.

Canonical Client/Site visibility comes from contact_relations. Compatibility rows may still support
older modules, but they are no longer a separate technician-facing contact catalogue.

### Automatic One-Time Cutover

A forward-only Laravel data migration will invoke one Contact-owned cutover action. The action is
also available through the existing maintenance command for read-back and safe idempotent reruns.

For every client_users row the cutover will:

1. Respect an existing explicit contact_id link first.
2. Reuse an unambiguous User-account or normalized email/phone Contact match.
3. Create a new Contact when no safe match exists.
4. Copy name, status, title, language, email, phone, and address data additively.
5. create canonical Client and Site relations.
6. retain the original client_users row and ID.

After mapping, the cutover adds canonical Contact IDs to compatible downstream records:

- Marketing list members and campaign recipients;
- Marketing list manual criteria;
- Marketing events through their recipient;
- Telephony calls;
- Intake submissions;
- Signals whose retained payload proves the legacy Client User identity;
- User accounts already linked to the legacy Client User.

Marketing recipient enrichment must use the existing guarded Marketing action so any new Contact
identity key belongs to the same durable delivery. If an identity key points to another delivery,
the recipient is blocked for review and the cutover reports a conflict rather than authorizing a
send.

## Impact Analysis

- Contact owns canonical identity, migration orchestration, Client/Site relation creation, duplicate
  matching, and compatibility-bridge synchronization.
- Clients changes its workspace query, links, legacy redirects, primary-contact creation, tests, and
  Knowledge documentation.
- Marketing retains ownership of audience and delivery evidence; Contact calls its existing guarded
  identity-enrichment boundary.
- Telephony, Intake, Signal, User Management, Ticket, Asset, Sales, and Nextcloud retain existing
  history. Only additive canonical IDs are written where a supported column already exists.
- Existing Contact permissions remain authoritative. No new role grant is introduced.
- No queue, scheduler, external provider, email send, or network action is performed by the cutover.
- The deployment requires a database backup, stopped Marketing/default workers during migration,
  migration read-back, cache clear, and worker restart.

## Data And Migration Plan

- Add one forward-only migration that calls the idempotent cutover action.
- Do not drop, rewrite, or recycle legacy IDs.
- Do not delete Contact, Client User, Marketing recipient, event, delivery, or identity-key history.
- Refuse ambiguous canonical matches or Marketing delivery identity conflicts with sanitized counts.
- Verify zero unlinked client_users rows after a successful run.
- Verify every dual-identity row with a provable legacy mapping also has the matching canonical ID.
- Rollback is code-forward only. The additive canonical records and identity evidence must remain
  because later production activity may depend on them.

## Testing Plan

- Creation from Client and central Contacts produces one canonical Contact and one stable bridge.
- Canonical edits retain the bridge ID and therefore retain dependent legacy relationships.
- The cutover is idempotent and fails closed on ambiguous identity or Marketing delivery conflicts.
- Client, Marketing, Telephony, Intake, Signal, and user links receive the same canonical Contact ID.
- Focused and affected feature tests pass before handoff. The complete Dev-suite outcome and any
  unrelated current-tree failures are recorded explicitly rather than hidden behind a green claim.

## Documentation Plan

- Update Contact and Client Knowledge with the single canonical workflow.
- Update Marketing Knowledge with additive identity enrichment and no-resend behavior.
- Record deployment checks in the human-review checklist.
- Track the implementation and production read-back in TODO.

## Open Questions

None. The user approved the full production cutover on 2026-09-03.

## Approval

Approved by Svein on 2026-09-03 for complete implementation on Dev.
