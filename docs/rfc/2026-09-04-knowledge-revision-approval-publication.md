# RFC: Knowledge Revision Approval And Publication

Status: Approved
Implementation: Done On Dev / Human Review Pending
Date: 2026-09-04
Owner: Svein / Codex
Related Issue: GitHub #266

## Approval

Svein explicitly requested implementation and same-run closure of GitHub Issue #266 on 2026-09-04.
This approval follows the earlier dependency review and authorizes the complete Level 3 workflow
described here. It does not implement the separate article-audience and AI-consumption contract in
Issue #265 or the complete Documentation Agent reasoning workflow.

## Context

Knowledge currently stores the active article body in the mutable `articles` row. Issue #276 added
immutable sync snapshots and exact BookStack read-back, but deliberately excluded the general draft,
approval, publication, Ticket follow-up, and rollback lifecycle. Direct Tech and API updates can
still replace the current article before a reviewer sees a comparison.

The finished workflow must keep the published article stable while a proposed change is reviewed,
attribute automated persistence to a protected actor, stop stale approvals, verify publication, and
leave Ticket documentation work open until the exact revision is proven published.

## Goals

- Make immutable Knowledge revisions the only path for proposed article content changes.
- Keep `articles` as the stable published read surface.
- Provide complete preview, concise before/after diff, submission, approval, rejection, publication,
  retry-visible failure, supersession, and rollback states.
- Separate draft, approval, publication, rollback, and administration permissions.
- Allow a Ticket-authorized technician to review only the exact AI revision linked to that Ticket
  without granting general Knowledge access.
- Use a named, disabled-login, least-privilege Documentation Agent system actor for persistence and
  publication.
- Close a documentation follow-up only after exact local and, when applicable, BookStack read-back.
- Preserve repository documentation as repository-authoritative and record its source identity.

## Non-Goals

- General role/team article audiences or AI Knowledge consumption; those remain in Issue #265.
- Automatically analyzing every closed Ticket.
- Generating prose with an AI provider.
- Replacing BookStack synchronization or its Issue #276 three-way conflict model.
- General Agent memory, GitHub issue generation, or Security Advisory generation.

## Current Behavior

- `StoreArticle` and `UpdateArticle` write the mutable article row directly.
- A `documentation_requested` Ticket event is a marker without independent completion state.
- BookStack sync revisions have content identity but not the complete approval audit contract.
- Repository sync writes the article row directly and then queues BookStack.
- `knowledge.publish` exists in the catalog but the UI does not enforce a publication boundary.

## Proposed Change

### Stable article and immutable revisions

`articles.published_revision_id` identifies the exact current published revision. Every existing
article receives a baseline revision without losing content or source metadata. Revision content and
scope fields are immutable after insert; only lifecycle, decision, publication, and read-back fields
may change.

The revision snapshot includes title, Markdown, rendered HTML, visibility, client scope, hierarchy,
category, priority, next-review date, source system/type/reference/version, human author, AI Agent,
system actor, Ticket, documentation follow-up, and an audience snapshot compatible with future
Issue #265 work.

### State machine

The supported lifecycle is `draft`, `ready_for_review`, `approved`, `rejected`, `publishing`,
`published`, `publication_failed`, `superseded`, and `conflict`. Transitions are serialized under
database locks and appended to a dedicated sanitized event ledger.

Approval and publication both compare the revision base with `articles.published_revision_id`.
Stale work enters `conflict` and cannot overwrite the newer article.

### Authorization

- `knowledge.manage_drafts`: create and submit manual revisions.
- `knowledge.approve`: approve or reject general revisions.
- `knowledge.publish`: publish an approved revision.
- `knowledge.rollback`: create a new revision from published history.
- `knowledge.admin`: inspect all workflow and audit metadata.
- protected system permissions are granted only to the Documentation Agent actor.

A technician with current `ticket.view` and `ticket.update` may view, approve, or reject only an
AI-attributed revision linked through one open documentation follow-up to that exact Ticket. This
route-level exception does not expose the Knowledge index, article route, API, or another revision.

### Publication and read-back

Publishing applies the approved snapshot through the Documentation Agent actor in one transaction,
re-renders Markdown, updates the stable article, supersedes the prior published revision, and reads
the stored row back by exact content identity. Client portal notifications occur only after this
verified publication.

BookStack-backed articles remain `publishing` until the existing push worker proves the exact
revision through provider read-back. Dispatch or provider failure records sanitized failure state and
keeps the documentation follow-up open and retryable.

### Ticket and Documentation Agent boundary

Ticket documentation requests become durable Knowledge-owned follow-ups linked to the existing
Ticket event. An internal action can create a proposed revision for an existing article using a
named AI Agent and the protected Documentation Agent actor. Ticket closure stays independent.

### Repository authority

Repository sync records the repository file identity and checksum/version in the revision ledger.
Non-repository AI revisions against repository-owned articles fail closed. Repository sync remains
the only automatic authority for those pages.

## Impact Analysis

- **Knowledge:** models, actions, routes, controller, Livewire editor, review UI, docs, and tests.
- **Ticket:** documentation-request persistence, Ticket rightbar status, docs, and focused tests.
- **Integration/BookStack:** finalizes or fails the exact workflow revision after provider read-back.
- **Permissions:** additive catalog and reviewed role grants; no broad role synchronization.
- **API:** article updates create revisions rather than silently replacing the published row.
- **Portal:** continues to read only published article rows; notifications move behind verified
  publication.
- **Queue:** existing BookStack queue is reused; no new worker or scheduler is introduced.
- **Risk:** concurrent drafts, stale approvals, external failure, and repository authority all fail
  closed while preserving content.

## Data And Migration Plan

One additive forward migration:

1. extends `knowledge_article_revisions` with workflow provenance and decision/read-back fields;
2. adds `articles.published_revision_id`;
3. creates `knowledge_article_revision_events` and `knowledge_documentation_requests`;
4. baselines every existing article using the current content and source metadata;
5. deploys the new permissions with additive Admin/Superuser/Tech grants only.

Rollback may remove the new workflow tables and columns only before they carry production history.
Published article content is never deleted or rewritten by rollback.

## Testing Plan

- Manual draft, preview, submit, approve, reject, publish, supersede, conflict, and rollback.
- API proposal behavior without direct published mutation.
- Named system actor and least-privilege permission repair.
- Ticket-scoped approval without general Knowledge access and denial for other Tickets/revisions.
- Repository authority and source-version recording.
- Local persistence/render read-back and BookStack success/failure completion.
- Portal notification and existing Knowledge/BookStack/Ticket regression coverage.
- Migration baseline, idempotency expectations, syntax, Pint, route list, Blade compile, and HTTP smoke.

## Documentation Plan

- Update the Knowledge overview with the published-projection, revision, permission, API, Ticket,
  repository, and publication read-back contracts.
- Update Ticket lifecycle documentation with the durable follow-up and exact-object review boundary.
- Update BookStack integration documentation with workflow-approved outbound publication and
  provider read-back completion.
- Track manual verification under `HR-2026-09-04-001`.

## Implementation Evidence

The complete RFC is implemented in the authoritative Dev working copy. Migration
`2026_09_04_080000_add_knowledge_revision_workflow` ran in batch 4 and read-back proved ten
published articles with ten exact baseline revisions, no missing hashes, and no content mismatch.
The protected Documentation Agent has no roles, cannot sign in, and has only the two internal
persistence permissions.

Focused Dev verification covers manual and API proposals, exact Ticket-scoped AI approval and
denial, stale conflict, immutable history, local/BookStack read-back, retry without duplicates,
rollback, repository authority, portal notification timing, routes, Blade compilation, and style.
Human UI and deployment checks remain open in `HR-2026-09-04-001`; they do not change the verified
Dev implementation state.
