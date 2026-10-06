The Knowledge domain is the internal documentation and knowledge base layer in Nexum PSA.

It provides the local content model used by technicians and by the BookStack integration.

Manual article defaults are controlled from Admin -> Knowledge Settings.

## Structure

Knowledge uses a BookStack-compatible hierarchy:

- Shelves group books.
- Books contain pages and chapters.
- Chapters group related pages inside a book.
- Articles are the local page records.

The main Nexum PSA documentation book is the `Nexum PSA` book.

## Local Articles

Articles store:

- Markdown source.
- Rendered HTML.
- Visibility.
- Status.
- Owner and author metadata.
- Optional shelf, book, and chapter placement.
- Optional source metadata for synced systems.

The article row is the stable published projection. Creating or editing content in the Tech UI or
Knowledge API creates an immutable revision proposal; it does not replace a published article.
Authorized reviewers inspect the exact proposal, a concise before/after diff, and the complete
rendered preview before approving or rejecting it. An authorized publisher must then publish the
approved revision. Rollback creates a new proposal from historical content and never rewrites or
deletes revision history.

Revision states are `draft`, `ready_for_review`, `approved`, `rejected`, `publishing`, `published`,
`publication_failed`, `superseded`, and `conflict`. Approval and publication compare the proposal's
base revision with the current published pointer; stale proposals fail closed as conflicts.

Workflow permissions are deliberately separate:

- `knowledge.manage_drafts` creates and submits proposals.
- `knowledge.approve` approves or rejects general proposals.
- `knowledge.publish` publishes an approved proposal.
- `knowledge.rollback` creates a rollback proposal.
- `knowledge.admin` exposes complete workflow and audit metadata.

The disabled-login `Nexum Documentation Agent` has no roles and only the internal permissions needed
to persist and apply an already-authorized revision. Human users never receive those permissions.

Knowledge visibility is separate from Work Context. `internal`, `client-wide`, and `public` decide
who can read an article. They do not mean that the article itself owns internal or client work.
Client-wide articles may use `client_scope_id`, while public and internal articles clear that
client-specific scope.

## BookStack Revisions And Review Candidates

Nexum is authoritative for Knowledge content. BookStack synchronization records immutable
sync-relevant article revisions and a last proven common local/provider base.

A clean provider-only change may update the article only when the administrator has enabled
automatic clean inbound synchronization and the current Nexum revision still matches the exact
base. Unknown history, simultaneous edits, or disabled automatic inbound creates a review
candidate instead of changing the article.

The article page shows the BookStack state. For a conflict or inbound candidate, authorized users
can compare the current Markdown with the provider candidate and choose:

- **Accept BookStack candidate**, which creates a new Nexum revision; or
- **Keep Nexum and push**, which binds and verifies the exact published Nexum revision.

Remote deletion and missing external identifiers preserve the article and require explicit
resolution. Accepting a provider candidate now opens it in the normal approval workflow instead of
mutating the published article. Existing explicitly enabled clean inbound fast-forward behavior
remains governed by the revision-safe synchronization contract from Issue #276.

An approved BookStack-backed revision remains `publishing` until a separate provider read-back proves
the exact canonical content identity. Provider failure records a sanitized, retryable state on the
same revision and does not complete a linked documentation request.

## Customer Portal

The Customer Portal can show Knowledge articles that are safe for customer access:

- `published` articles with `public` visibility.
- `published` articles with `client-wide` visibility when `client_scope_id` matches the active
  portal Client.

Draft, archived, internal, and other-client articles are hidden from portal routes. Portal article
views increment the normal article view count.

## API

Knowledge API routes are available under `/api/v1/knowledge`.

Scopes:

- `knowledge.read`
- `knowledge.create`
- `knowledge.update`

Routes:

- `GET /api/v1/knowledge/shelves`
- `POST /api/v1/knowledge/shelves`
- `GET /api/v1/knowledge/shelves/{shelf}`
- `PUT/PATCH /api/v1/knowledge/shelves/{shelf}`
- `DELETE /api/v1/knowledge/shelves/{shelf}`
- `GET /api/v1/knowledge/books`
- `POST /api/v1/knowledge/books`
- `GET /api/v1/knowledge/books/{book}`
- `PUT/PATCH /api/v1/knowledge/books/{book}`
- `DELETE /api/v1/knowledge/books/{book}`
- `GET /api/v1/knowledge/chapters`
- `POST /api/v1/knowledge/chapters`
- `GET /api/v1/knowledge/chapters/{chapter}`
- `PUT/PATCH /api/v1/knowledge/chapters/{chapter}`
- `DELETE /api/v1/knowledge/chapters/{chapter}`
- `GET /api/v1/knowledge/articles`
- `GET /api/v1/knowledge/articles/{article}`
- `POST /api/v1/knowledge/articles`
- `PUT /api/v1/knowledge/articles/{article}`
- `PATCH /api/v1/knowledge/articles/{article}`
- `DELETE /api/v1/knowledge/articles/{article}`

`POST /api/v1/knowledge/articles` creates a draft article shell and an immutable revision proposal.
`PUT` and `PATCH /api/v1/knowledge/articles/{article}` create a new proposal against the current
published pointer. Responses expose the proposal revision identity and state; none of these routes
silently publishes content.

Hierarchy create and update endpoints accept `sync_to_book_stack`. Shelf, book, and chapter changes
can still be queued directly. Article content is queued only when an approved revision enters the
publication action, so unapproved API or Tech proposals cannot reach BookStack.

BookStack-owned records cannot be edited through the API unless two-way sync is enabled.

## Nexum Relationship Sync

Knowledge articles can be exchanged with another Nexum installation through the
Relationship module when the relationship has Knowledge sync enabled.

Only non-internal articles are eligible. Client-wide articles keep their
`client_scope_id` locally, while the receiving installation stores its own local
article row and remote identity link. Incoming remote updates are marked as
conflicts when the local article has diverged since the last synced checksum.

## Repository Documentation

Code-owned documentation lives under:

```text
app/Modules/{Domain}/Docs/knowledge
```

Publish repository documentation into Knowledge with:

```bash
php artisan knowledge:sync-docs
```

Limit the sync to one module when needed:

```bash
php artisan knowledge:sync-docs --module=Ticket
```

Queue BookStack push after syncing:

```bash
php artisan knowledge:sync-docs --push
```

This command records the repository path and content checksum/version in an immutable revision,
updates the published projection through repository authority, and can mark the exact published
revision for BookStack push. Repository-owned articles reject ordinary and AI proposal paths; the
repository sync command is their automatic content authority.

## BookStack Ownership

If a Knowledge record already comes from BookStack, repository sync must preserve:

- `source_system`
- `source_type`
- `source_id`

This prevents duplicate pages and lets the guarded BookStack sync action update the existing page
instead of creating a separate local copy. Repository sync must not replace revision/conflict state
or claim that a legacy checksum is a proven common base.

## Ticket Suggestions

Ticket-side Knowledge suggestions score every published article that the technician
may access before applying the result limit. Matching uses exact normalized terms,
with title matches weighted above body matches. Weak matches below the minimum score
are omitted so the UI can show its empty state instead of unrelated guidance.
Equal scores are ordered by article ID to keep results deterministic.

## Operational Notes

The queue worker must run for queued BookStack pushes.

Production rollout of the revision-safe BookStack tables and worker behavior requires the migration,
cache clear, queue restart, controlled pull, and human checks in `HR-2026-09-03-003`.
Automatic clean inbound synchronization remains off until explicitly enabled.

The general revision approval workflow requires migration
`2026_09_04_080000_add_knowledge_revision_workflow` and the manual UI, permission, Ticket, retry,
rollback, and production checks in `HR-2026-09-04-001`.

If Artisan commands cannot connect to the development MySQL server while the web application can, verify sandbox/network restrictions before changing `.env` or database settings.
