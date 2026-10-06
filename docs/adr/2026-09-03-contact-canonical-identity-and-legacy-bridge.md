# ADR: Contact Is Canonical And Client User IDs Remain Stable Bridges

Status: Accepted
Date: 2026-09-03
Decision Makers: Svein Tore Ramstad / Codex
Related RFC: ../rfc/2026-09-03-canonical-contact-workflow-and-legacy-cutover.md

## Context

Nexum has a canonical Contact domain and an older client_users person table. Several mature modules
still store Client User IDs, while newer modules and Customer Portal store Contact IDs. Deleting or
recreating client_users during a UI cutover would null foreign keys, leave polymorphic references
dangling, and disconnect Tickets, Assets, Sales, Nextcloud, Marketing, Telephony, and Intake history.

Marketing also protects lifetime delivery through independent Contact, Client User, and email
identity keys. A migration that silently changes only recipient IDs could weaken or split that
evidence.

## Decision

Contact is the sole canonical person identity and the only technician-visible create/edit workflow.
Client pages consume Contact-owned queries and routes.

Existing client_users rows remain stable, internal compatibility bridges:

- contact_id links the bridge to its canonical Contact;
- the bridge primary key is not changed or recycled;
- bridge rows are not deleted as an ordinary consequence of editing a Contact;
- modules that still require a Client User ID continue to resolve the same row;
- new canonical workflows create or update the bridge behind StoreContact when Client/Site context
  requires compatibility.

The production cutover is an additive, idempotent, forward-only data migration. It maps every legacy
row to Contact, creates Contact relations, and fills canonical IDs in downstream tables that already
support dual identity.

Marketing identity enrichment must go through Marketing's guarded delivery action. Existing delivery
and identity-key evidence is never deleted or reassigned. Conflicts fail closed for review.

## Rationale

- Stable bridge IDs preserve all existing relationships without requiring a simultaneous rewrite of
  every dependent domain.
- One canonical UI immediately removes duplicate person creation while allowing module-by-module
  storage modernization later.
- Additive migration is safer than destructive table replacement and can be rerun after interruption.
- Using Marketing's existing invariant keeps the Contact cutover from accidentally authorizing a
  duplicate campaign email.
- A forward-only production repair is honest because canonical records may receive new activity as
  soon as deployment completes.

## Consequences

Positive:

- Client and Contacts show the same person records.
- Old production contacts become canonical automatically during normal migration.
- Historical Ticket, Asset, Sales, Nextcloud, Marketing, Telephony, Intake, and User references
  remain resolvable.
- Future modules have one Contact identity to consume.

Negative:

- client_users cannot be dropped until remaining modules are deliberately migrated.
- Contact writes must maintain compatibility fields for older consumers.
- An ambiguous production identity stops the cutover and requires review instead of guessing.
- Multi-client Contacts may retain more than one internal bridge row even though the ordinary form
  presents one current Client/Site context.

## Alternatives Considered

- Delete client_users after copying. Rejected because it would lose or null existing relationships.
- Rewrite every dependent table in one release. Rejected because it makes one UI fix an unsafe
  platform-wide destructive migration.
- Keep both user-facing forms. Rejected because it perpetuates duplicate contacts and inconsistent
  validation.
- Update Marketing recipient IDs directly. Rejected because it bypasses durable no-resend identity
  evidence and conflict handling.
- Require an operator-only import command. Rejected because production could be promoted without the
  command being run; the forward-only migration makes the required cutover part of deployment.

## Follow-Up

- Migrate remaining legacy-only domain relations to canonical Contact IDs in separately approved
  slices.
- Remove client_users only after every consumer has migrated and a later ADR approves deletion.
- Keep production backup, cutover read-back, historical relationship checks, and smoke verification
  open as deployment operations in HR-2026-09-03-005; Svein approved the Dev UI review on 2026-09-04.
