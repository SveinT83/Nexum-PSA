# RFC: Full Contact Browser/API Parity

Status: Approved by Svein Tore, 2026-10-27. Implementation: Done On Dev; scoped Main candidate verified.
Date: 2026-10-27
Owner: Codex. Product owner and intended human reviewer: Svein Tore.
Human-review checklist: HR-2026-10-27-CONTACT-API (Pending / planned scope).
Related: coverage matrix ../plans/2026-10-27-contact-api-coverage-matrix.md and .json;
ADR ../adr/2026-10-27-contact-api-authority-and-identity.md (Proposed).

## Context
Svein requested "Contact domain. Lag 100% API støtte". Authoritative Dev currently
has 8 Contact-owned browser route rows, 8 Clients-owned legacy alias rows, and
9 Contact-owned API route rows / 10 HTTP operations excluding HEAD. The inventory
also includes embedded Livewire workflows and Client/Site contact lists; counting
only controller routes would miss meaningful operations. Six operations have
OpenAPI declarations in Contact controllers, several without typed contracts.
Full support means proven operation coverage, not a percentage based on route counts.

The approved ownership-repair RFC covers repair, not full browser/API parity.
The canonical identity RFC/ADR require stable client_users bridge IDs and existing
cross-domain history. Issue #254 is approved but open: Contact create still hardcodes
Viewer and lacks its required role selector. Its 2026-10-09 comment records overlapping
contributor changes. Preserve and reconcile that work before touching the shared form.
Fresh Contact baseline passes 49 tests / 353 assertions; this does not verify the
proposed extension. Main and production availability have not been verified here.

## Goals
Expose every supported Contact read, write and action through the official typed API:
listing and lookup, complete detail, form discovery, duplicate selection, canonical
create/edit/reuse, Client/Site context, consent and address inputs already supported
by authoritative workflows, settings, create-time portal invitation and ownership repair.
Require token abilities AND current native actor/object rights. Preserve exact identity,
normal side effects, audit, predictable validation and independent result read-back.
Document every route, embedded workflow and cross-module boundary in the matrix.

## Non-Goals
A new contact merge engine, arbitrary relation types, external-reference CRUD, generic
phone/email/address collection editors, a destructive contact lifecycle, automatic
Marketing enrollment or sends, portal membership administration/acceptance, new role
grants, migration of Ticket IDs, provider activation, production rollout or Main merge.
These are not current Contact workflows. Existing repair's guarded orphan soft-delete
remains in scope; the legacy delete URL currently only redirects with a warning.
No new destructive endpoint will be invented from the existence of a model or route.

## Current Behavior
- List API supports q/email/phone/status, but ignores browser Client/Site filters and
  does not search job_title. Pagination is not bounded by explicit validation.
- Resource omits loaded addresses, resolved Client/Site presentation, bridge/user
  compatibility metadata, external-reference count, statistics and related signals.
- POST is an upsert requiring both contacts.create and contacts.update scopes.
  PATCH/PUT call StoreContact and retain historical bridge IDs. API writes can
  replace Client/Site context; native browser create can instead reuse a person.
- Settings and the form's defaults, client suggestions, default Site selection,
  duplicate suggestions and allowed operations have no API discovery contract.
- StoreContact supports legacy address fields; API validation omits them. It accepts
  type on update but syncContactFields does not persist it. Partial update preservation,
  null/omission semantics and ambiguous duplicate matching need explicit regressions.
- Create-time portal invitations use CustomerPortal's authoritative action and
  after-commit queued delivery; ordinary edit never sends. UI defaults are UI-only.
- Existing repair supports inspection, move, bulk fix, orphan migration and detach,
  with previews, audit and stable bridges. Four repair mutations lack OpenAPI declarations.
- Existing API middleware checks token abilities; these Contact controllers lack
  corresponding native actor permission checks. Tokens must not confer human rights.
- RelatedSignals owns the embedded history query; Taxonomy/Marketing/Sales/CustomerPortal
  keep ownership of their separate operations. No Contact-owned public portal surface exists.

## Proposed Change
1. Move API registration into Contact/routes.php with a scoped API branch and keep
   api.php as a compatibility loader. Preserve current URLs and PUT/PATCH semantics.
2. Add shared Contact API authorization using current active native actor rights:
   contact.view, contact.create, contact.update, contact.manage_settings, plus
   customer_portal.invite for explicit invitations. Ownership repair requires its
   explicit contacts.ownership_manage ability and native authority appropriate to
   the actual operation; guarded orphan deletion additionally requires contact.delete.
   Define and test the exact repair policy without creating or assigning new human rights.
   Revalidate authority on execution and receipt reads. Lookup/form-only rights must
   not silently grant the full contact, customer, signal or user directory.
3. Add bounded context/lookup/duplicate/statistics operations and canonical Client/Site
   filters. Stateless requests express context explicitly; clearing context means
   omitting those filters, with no synthetic browser-session mutation endpoint.
   Discover default Site, relation options and existing values without accepting
   arbitrary disabled relation values. Reject mismatched Client/Site inputs.
4. Return typed full detail and compatibility summaries, addresses, resolved relations
   and safe linked-user/reference metadata. Never expose credentials, raw invitation
   tokens or unrelated User details. Provide the embedded related Signal summary
   through the Signal-owned query with explicit field and authority bounds.
5. Preserve existing upsert while documenting both required scopes and existing-contact
   effects. Add explicit non-overwriting reuse for create-form parity. Use the shared
   Contact actions, preserving untouched fields/relations on PATCH. Reject conflicting
   email/phone identities and ambiguous matches rather than selecting an arbitrary person.
   Expose supported legacy address/language data and fix accepted-but-ignored type updates
   with narrow regressions. Do not introduce arbitrary model-field mass assignment.
6. Support explicit create-time portal invitation with Viewer default and the authoritative
   predefined role options. Finish the already-approved #254 selector in the shared form
   as a separately tested slice, reusing CustomerPortal validation/actions. Both UI/API
   must persist the selected role; job title and contact relation remain distinct.
   Contact plus invitation must be transactional, with after-commit delivery. API and
   legacy writes never inherit the UI default. Ordinary PATCH/PUT never send/resend.
   Explicit external-effect execution is default-off until installation activation;
   previews remain usable. No real invitation is sent by implementation tests.
7. Add Contact settings read/update using ContactSettings and the existing validation.
   Add narrow contacts.settings.read/manage and contacts.portal_invite abilities only
   alongside their routes/tests. Form discovery returns only necessary defaults, not
   general admin access. Existing token abilities and native rights remain separately required.
8. Fully type and document all existing repair operations and dry_run outcomes. Require
   explicit previews and stale-state protection for risky API effects. Use a small durable
   Contact command/receipt boundary for invitation and repair replay/concurrency protection,
   with actor/token/action/target/body binding, authoritative state fingerprint, unique
   idempotency key, transactional locks and exact result read-back. Replays must recheck
   rights; changed-body keys conflict. Never replay unknown external delivery blindly.
   Preserve the existing audited dry_run behavior and stable bridge IDs.
9. Supply exact OpenAPI request/response schemas, enums, filters, pagination, errors,
   abilities and operation IDs. Verify the exact runtime/OpenAPI operation sets; preserve
   getClientTicketContacts and its downstream Ticket contact_id instructions. Verify
   catalog exposure through the authoritative Integration/MCP path without widening
   an actual connector's grants or enabling write tools automatically.

## Impact Analysis
Contact owns routes, controllers, queries/actions/resources and tests. Clients aliases
and Client/Site tabs must keep canonical visibility and stable IDs. CustomerPortal owns
roles, validation, invitation records, audit and queued email; it receives no competing
membership lifecycle. Signal owns related history. Ticket/Asset/Sales/Nextcloud/Marketing
and linked Users retain legacy identity and historical references. Integration owns API
ability catalog/OpenAPI/MCP discovery; grants are not changed. Knowledge owns handbook
publication. Existing ContactForm, ownership lookup and guide changes must be preserved.
The API adds no general UI redesign; #254 is the bounded approved create-form change.

## Data And Migration Plan
Reuse contacts, contact_emails, contact_phones, contact_addresses, contact_relations,
client_users, common_settings, activity_log and CustomerPortal tables. No contact backfill,
bridge deletion/renumbering, real customer mutation or bulk repair is part of rollout.
Plan one additive Contact API command/receipt table with unique replay binding, locked
state and encrypted sensitive payload/result fields; finalize migration name, indexes,
retention and compatibility tests before implementation is called complete. Record exact
Dev migration and production upgrade commands in verification evidence. Production
migration is a separate human gate. Preserve encryption keys and receipts on rollback.
New routes can be disabled; unknown sends require reconciliation, not blind resend.
After deployment regenerate Swagger and normal caches. No worker/scheduler activation,
queue purge or provider changes. Existing invitation delivery requires its ordinary worker;
queued or SMTP-accepted does not prove inbox receipt.

## Testing Plan
For each matrix row prove happy path, missing ability, revoked/missing native rights,
invalid target, validation and exact saved read-back. Cover list filters/title search,
normalized phone and ambiguous duplicates, bounded pagination, default Site selection,
create reuse vs upsert, omitted/null fields, addresses/type/status/consent preservation,
bridge stability and no foreign-customer identity. Test settings and role options,
all #254 entry points, denied invitation/invalid scope/duplicate access rollback,
ordinary edit no-send and queue-after-commit. Fake external delivery in normal tests.
Cover repair dry_run/execute/stale state, replay/concurrency, receipt isolation and
no destructive legacy deletion. Run Contact plus affected Clients/CustomerPortal,
Ticket compatibility, Signal/Integration/Knowledge tests, scoped PHP syntax/Pint,
exact Swagger contracts and trusted HTTPS smoke. Run native database concurrency
checks where SQLite cannot prove locking. Human review stays Pending until named acceptance.

## Documentation Plan
Update Contact README, Contact/CustomerPortal source articles and Integration API guide,
coverage evidence, TODO and human-review checklist. Sync via the supported Knowledge/
BookStack integration; read actual article bodies and inherited employee access back.
Local Markdown or a queued sync is not publication proof. Record a blocker and owner
if publication fails. Employee OpenWebUI retrieval is a separate verification claim.
Provide the public-safe website handoff only when completed work qualifies under its process.

## Sequential Feature Slices
1. Authority, route ownership and typed baseline contracts.
2. Full reads, discovery, context/duplicate lookups, statistics and safe related history.
3. Complete canonical create/edit/reuse and legacy address compatibility.
4. Approved #254 role selector and explicit guarded/idempotent API create invitation.
5. Settings, full repair contracts and preview/replay/read-back protection.
6. Exact route/Swagger/MCP coverage, cross-module regression, handbook and handoff.
Each slice updates the matrix and TODO; do not claim domain parity before every row passes.

## Open Questions
Approve this full current-workflow scope and its sequential Dev implementation, including
the existing approved #254 dependency. Technical defaults above are proposed together;
no per-slice reapproval is needed if the approved scope remains unchanged.

## Approval
Svein Tore explicitly approved this RFC and tested Main promotion in conversation on
2026-10-27: "Godkjenner og merge til main". Sequential Dev implementation and scoped
Main merge are authorized. Human scenario review remains Pending; production deployment
and runtime/provider activation are not authorized by this approval.
