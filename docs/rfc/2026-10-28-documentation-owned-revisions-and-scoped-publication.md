# RFC: Documentation-Owned Revisions And Scoped Publication

Status: Draft - concrete design awaiting named product/authority approval
Date: 2026-10-28
Owner: Svein Tore / Codex
Issue: #275; related #261, #265, #266, #276
Level: 3
Authorization: implement and merge the audited work was requested. This new lifecycle still
requires approval of the concrete authority/publication contract under AGENTS.md. No runtime
behavior, permission, schema or publication is changed by this document.

## Full Target

Documentation remains authoritative for structured operational documents; Knowledge remains
separate. Every author, browser and API writes through Documentation-owned revision Actions.
The published document stays readable while a replacement is drafted, reviewed or failed.
The complete workflow is need -> exact revision -> before/after -> scoped approval -> named
least-privilege publication -> authoritative read-back -> linked-work completion.

A revision contains document/base revision, a canonical immutable content/structured-field
snapshot and checksum, template/category identity and snapshot, Client/site/Work Context/domain
scope, audience/data class/language, source references, human/Agent/system actor identities and
lifecycle/publication timestamps. Immutable version content is separate from append-only
lifecycle evidence. A draft edit creates a successor draft identity rather than mutating a
submitted, approved or published payload. Approval always names its exact checksum/base.

## Existing Evidence And Boundaries

The current Documentation model stores template_id, category_id, client_id, site_id,
work_context_id, title, scope_type, template_snapshot_json, data_json and portal visibility.
UpdateDocumentation and SetDocumentationPortalVisibility own current changes; the full API is
already on Main. Its encrypted command receipts and ETags are not immutable document revisions.
The accepted 2026-10-27 Documentation API ADR explicitly deferred the #275 lifecycle.
The existing Ticket documentation request and #266 revision machinery belong to Knowledge.
Reuse their semantics where appropriate, not their table/domain ownership. The accepted #276
BookStack contract remains the only downstream exact-revision sync model.

The #261/#265 source audit found no existing Team membership authority. Before implementing a
shared audience contract, Svein must choose its source. Do not impersonate role names as Teams,
create a parallel temporary membership system, or infer authority from a caller's context ID.

## Permissions And Audience Contract For Approval

Use explicit Documentation capabilities: browse, draft, linked-revision review, general approve,
publish, rollback and administer. Preserve current deployed API scope aliases and verify both
fresh user capabilities and token abilities. A mapping migration must retain current document
visibility without granting existing readers approval/publication powers. Existing broad roles
are not silently converted to approvers.

An audience is a constrained intersection of owning domain scope, Client/site/Work Context,
classification and allowed role/Team memberships from the approved shared membership source.
Portal visibility remains a separate current CustomerPortalContext decision. A public/Client
publication requires a fresh audience review and never follows automatically from Ticket access.

A Ticket-scoped contribution requires current Ticket record/domain access, Client/Work Context
consistency, the exact linked request and exact proposed document/revision. It grants no index,
search, hierarchy, other-revision or general Documentation API access. The proposed scoped
approval authority is optional per workflow and default-off; it may approve only an unchanged
same-scope proposal, with evidence the technician may currently read. Scope/audience widening,
classification lowering or publication destination changes require a general authorized reviewer.

A derived authorization records exact human, request, document, revision hash, base revision,
Client/site/Work Context, audience hash, workflow revision, action and short expiry. Re-evaluate
membership, owning domain access, workflow and all identities immediately before each operation.
Revocation, expiry, reassignment, a newer base or payload change invalidates the authorization.
A named protected disabled-login system actor publishes only with this exact live grant and the
narrow Documentation publication capability. It never borrows the technician's general role.

## Lifecycle, Concurrency And Failure

Proposed states: draft, submitted, approved, rejected, publishing, publication_failed, published,
superseded. Append durable actor-attributed state events. Approval rejection retains evidence;
correction creates a new revision. Lock document/current published revision and compare the
approved base/hash. Commit the exact selected snapshot and published pointer atomically.
Concurrent changes fail as explicit conflict, never last-write-wins.

Persist one idempotent publication operation for the exact revision/destination, claimed by an
opaque token. Read back authoritative local state and checksum before reporting success or
completing linked work. Queue acceptance is not publication. If an external result is unknown,
reconcile that exact operation; do not blindly resend it or invent a new revision.
A known safely retryable failure retries the same retained identity. The failed proposal remains
visible to its authorized reviewer. Rollback creates, previews, approves and publishes a new
revision copied from a selected prior published version; all original history remains immutable.

BookStack publication is optional and default-off for this domain. Any supported mapping must
reuse #276's revision identity, disabled-state, conflict, keyed retry and actual read-back
contract. No new automatic public/employee destination is created by this RFC. Linked work that
requires downstream publication completes only after the configured exact read-back succeeds.

## Freshness

Store created_at, content_updated_at, verified_at, next_review_at and explicit verification result
separately. An overdue review is a scheduling fact, not proof the content is wrong. A successful
verification without content change appends verification evidence and leaves content time/hash
unchanged. Known-incorrect and could-not-verify are explicit distinct results with reason/actor.
A content correction enters the normal revision workflow. Date scheduling alone cannot certify
content or mark it incorrect. Review notifications require their existing independent preferences.

## Privacy, Baseline And Retention

Secret-bearing Tool/provider output, passwords, private keys and credentials are rejected before
new revision content, preview, metadata, event or model messages are persisted. Reuse the tested
secret-boundary service and data-minimization/outbound policy, with Documentation-owned input
classification and safe bounded errors. Never record rejected raw payloads in audit.
Client-specific content cannot become internal/global through an AI suggestion without explicit
sanitization and fresh scope review. Titles, snippets, hierarchy and links obey the same authority.

The additive migration must preflight and encrypt a verified backup without printing bodies.
Preserve every existing published row, template/category, Client/site/Work Context, portal scope,
IDs and timestamps. Safe legacy rows receive deterministic immutable baseline revisions and exact
read-back. Detect structured sensitive fields before copying. Noncompliant legacy material stays
preserved in its existing restricted record and is quarantined from new AI/revision publication
until an authorized sanitization review; no broad export, deletion or silent public baseline.
The migration must report only counts/IDs to the authorized operator, and be restartable without
duplicating baselines. Existing visibility must never expand while a baseline is incomplete.
Retain used revision, approval, publication, conflict and rollback evidence. Destructive down is
refused after use. Content retention/deletion is an explicit privacy process, not fixture cleanup.

## Implementation And Verification

After the authority contract and membership source are approved:

1. Add Documentation-owned immutable revisions/events, freshness and safe baseline preflight.
2. Route manual/API draft, preview, approval, publication and rollback through shared Actions.
   Preserve current complete API operations with explicit revised contracts/scopes/ETags/read-back.
3. Add exact Ticket contribution and named publication actor with fresh derived authorization.
4. Wire optional #276 downstream publication and independent verified completion.
5. Update English Bootstrap UI, source guides, actual BookStack and human-review checklist.

These are slices of the full target; none alone completes #275. Tests must cover legacy baseline,
manual/AI input, unchanged published surface, exact preview, Client/internal/portal scope,
revocation/Team drift, expired and cross-Ticket grants, stale base, concurrent approval, actor
capabilities, secret rejection, failed/unknown publication, same-operation reconciliation,
read-back, rollback, unchanged-content verification and downstream disabled/conflict boundaries.
Run Dev tests and an exact latest-Main candidate; apply only the reviewed migration on Dev after
backup/read-back. Never activate providers, replay old work or deploy production from this RFC.

## Approval Needed

Approve or amend the proposed default-off Ticket-scoped approval exception and classification /
audience widening restriction, the baseline quarantine behavior and the optional downstream
publication gate. Select the shared Team membership source already requested under #265 before
implementation. AGENTS.md requires an approved Level 3 RFC before these permission/data changes.
