# ADR: Knowledge Published Surface And Revision State Machine

Status: Accepted
Date: 2026-09-04
Decision Makers: Svein / Codex

## Context

Knowledge needs immutable review history without breaking Portal, Ticket suggestions, AI retrieval,
or BookStack consumers that already read the `articles` table. Issue #276 introduced sync revisions,
but not the general publication authority or Ticket-scoped approval boundary.

## Decision

Keep `articles` as the stable published projection and make `knowledge_article_revisions` the
immutable content ledger. A locked state machine changes only lifecycle metadata; publication copies
one approved snapshot to the article through a protected Documentation Agent actor and binds
`published_revision_id` atomically.

Use a dedicated revision-event ledger for sanitized transition audit. Model Ticket documentation
follow-up as a Knowledge-owned record linked to the Ticket and its existing event. Grant scoped
Ticket review through an exact object-level authorizer instead of a broad Knowledge permission.

BookStack read-back completes the same revision when external publication is required. Repository
sync is an explicit authoritative source path and non-repository AI content cannot replace it.

## Rationale

The projection preserves existing read contracts while immutable snapshots make review, rollback,
conflict detection, and attribution reliable. Exact object authorization satisfies Ticket review
without leaking the Knowledge library. Reusing the protected actor and #276 read-back boundary
avoids impersonating technicians or creating a competing integration workflow.

## Consequences

- Article edits become proposals until approved and published.
- Existing articles receive a baseline revision and exact published pointer.
- Published history consumes storage proportional to real content changes.
- API callers must observe pending revision state instead of assuming an update immediately changed
  the article.
- BookStack-backed publication remains open until provider read-back succeeds.
- Issue #265 may later replace the audience snapshot with normalized role/team audience relations
  without changing revision identity or approval history.

## Alternatives Considered

- **Version the article table itself:** rejected because every read consumer would need temporal
  selection and Portal safety would become easier to get wrong.
- **Store only diffs:** rejected because preview, rollback, retention, and independent verification
  would depend on reconstructing a potentially broken chain.
- **Grant Ticket technicians `knowledge.approve`:** rejected because it would broaden access beyond
