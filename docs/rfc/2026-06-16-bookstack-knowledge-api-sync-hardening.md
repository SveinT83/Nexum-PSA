# RFC: BookStack Knowledge API And Sync Hardening

Status: Approved
Date: 2026-06-16
Owner: Codex

## Context

Codex and other local AI agents need to perform the same Knowledge and BookStack
operations that a technician or administrator can perform in the UI.

The current implementation is uneven:

- The Tech UI can create and update Knowledge shelves, books, chapters, and pages.
- The Tech UI can manually pull from BookStack and push pending local Knowledge
  changes to BookStack.
- The API currently exposes Knowledge articles only.
- API-created or API-updated articles do not currently trigger the same
  BookStack pending-push behavior that the Tech UI applies when content belongs
  to a BookStack-backed hierarchy.
- BookStack push handles chapters internally, but the admin feedback message
  omits chapter counts.
- Integration documentation still has older wording that describes the
  BookStack path as read-only even though two-way push support now exists.

This blocks agent-driven documentation work. An agent can create a local article,
but cannot safely create its shelf/book/chapter hierarchy, request pull/push
sync through the API, or reliably know whether two-way sync actually completed.

## Goals

- Add API parity for Knowledge hierarchy records:
  - Shelves.
  - Books.
  - Chapters.
  - Articles/pages.
- Add API endpoints for BookStack sync operations when the integration is active:
  - Test connection.
  - Pull BookStack into Knowledge.
  - Push pending Knowledge changes to BookStack.
  - Read latest sync status and summaries.
- Make API-created and API-updated Knowledge content follow the same
  BookStack pending-push rules as the Tech UI.
- Keep all endpoints protected by Sanctum abilities and existing tdPSA
  permission boundaries.
- Improve sync diagnostics so failed or skipped push work is visible to API
  callers, UI users, and integration health.
- Update API documentation and Knowledge/BookStack documentation.
- Add feature tests for API abilities, validation, hierarchy behavior, and sync
  operation responses.

## Non-Goals

- Do not replace BookStack with Nexum Knowledge.
- Do not implement realtime conflict resolution between simultaneous BookStack
  and Nexum edits.
- Do not add deep content diff/merge tooling.
- Do not expose BookStack API credentials through the API.
- Do not add broad full-access API behavior beyond the existing explicit
  ability model.
- Do not implement every missing domain API in this slice. This RFC applies the
  API-parity principle to the BookStack/Knowledge path first.

## Current Behavior

Knowledge article API routes exist under:

```text
/api/v1/knowledge/articles
```

Existing abilities:

- `knowledge.read`
- `knowledge.create`
- `knowledge.update`

There are no API routes for:

- `knowledge_shelves`
- `knowledge_books`
- `knowledge_chapters`
- BookStack connection testing.
- BookStack pull sync.
- BookStack push sync.
- BookStack sync status.

Tech UI routes exist for shelf, book, chapter, and article create/update/delete.
BookStack manual pull/push exists only through admin web form posts.

## Proposed Change

Implement this as two small feature slices under the existing approved Domain
API Foundation RFC.

### Slice 1: Knowledge Hierarchy API

Add Knowledge-owned API controllers, resources, routes, and tests for:

```text
GET    /api/v1/knowledge/shelves
POST   /api/v1/knowledge/shelves
GET    /api/v1/knowledge/shelves/{shelf}
PATCH  /api/v1/knowledge/shelves/{shelf}
DELETE /api/v1/knowledge/shelves/{shelf}

GET    /api/v1/knowledge/books
POST   /api/v1/knowledge/books
GET    /api/v1/knowledge/books/{book}
PATCH  /api/v1/knowledge/books/{book}
DELETE /api/v1/knowledge/books/{book}

GET    /api/v1/knowledge/chapters
POST   /api/v1/knowledge/chapters
GET    /api/v1/knowledge/chapters/{chapter}
PATCH  /api/v1/knowledge/chapters/{chapter}
DELETE /api/v1/knowledge/chapters/{chapter}
```

Rules:

- Use existing `StoreShelf`, `StoreBook`, `StoreChapter`, `StoreArticle`, and
  `UpdateArticle` behavior where practical.
- Keep structure normalization aligned with the UI.
- Honor two-way sync:
  - `sync_to_book_stack=true` marks local shelves, books, chapters, and pages
    as `pending_push` only when the BookStack integration is active and two-way
    sync is enabled.
  - Updates to BookStack-owned records are rejected unless two-way sync is
    enabled.
  - Created or updated articles placed under BookStack-backed books/chapters
    are marked `pending_push`, matching the Tech UI.
- Use existing abilities unless tests show a separate ability is required:
  - Read endpoints require `knowledge.read`.
  - Create endpoints require `knowledge.create`.
  - Update and delete endpoints require `knowledge.update`.

### Slice 2: BookStack Sync API And Diagnostics

Add Integration-owned API endpoints under the Integration module:

```text
GET  /api/v1/integrations/book-stack/status
POST /api/v1/integrations/book-stack/test
POST /api/v1/integrations/book-stack/pull
POST /api/v1/integrations/book-stack/push
```

Add explicit abilities:

- `integration.bookstack.read`
- `integration.bookstack.run`

Rules:

- `status` returns sanitized integration state, latest pull summary, latest
  push summary, last sync timestamps, health, and last error.
- `test` checks the configured BookStack connection and stores health/error
  state, without exposing secrets.
- `pull` runs the same `SyncBookStackToKnowledge` action used by admin UI.
- `push` runs the same `PushKnowledgeToBookStack` action used by admin UI and
  requires two-way sync.
- Responses must be JSON summaries suitable for Codex/n8n automation.
- Sync operations should not print tokens or secret configuration.
- Push feedback must include shelves, books, chapters, pages, skipped, failed,
  total, and errors.
- Decide whether skipped push work caused by unsynced parents should mark the
  integration unhealthy or return HTTP 207/422 with an actionable summary.

## Impact Analysis

Affected modules:

- Knowledge: API controllers, resources, route file, tests, Knowledge docs.
- Integration: BookStack sync API controller, ability catalog, API docs,
  BookStack Knowledge docs, tests.
- System integrations data: existing `integrations` records and encrypted
  secrets are reused.
- Queue/scheduler: existing jobs remain; API endpoints run the same actions or
  dispatch the same push worker depending on the chosen slice implementation.

Permissions and API:

- Existing Knowledge abilities continue to protect Knowledge content.
- New Integration abilities protect BookStack sync operations.
- API docs must list abilities only after routes and tests exist.

Risks:

- Exposing sync actions through API can trigger external BookStack writes more
  often than the UI path.
- Two-way sync can overwrite BookStack content if local content is stale.
- Delete endpoints must keep existing safety behavior for non-empty shelves,
  non-empty books, non-empty chapters, and BookStack-owned records.
- Running sync inside HTTP requests can be slow. If this becomes a practical
  issue, return queued job metadata instead of running synchronously.

## Data And Migration Plan

No database migration is expected for the first implementation.

Existing columns are reused:

- `source_system`
- `source_type`
- `source_id`
- `source_url`
- `source_checksum`
- `source_synced_at`
- `source_updated_at`
- `sync_status`
- `source_payload`
- `integrations.config`

If implementation reveals missing sync audit data, stop and update this RFC
before adding migrations.

Rollback:

- API routes can be removed without data loss.
- Pending sync records remain in Knowledge and can still be pushed through the
  admin UI.

## Testing Plan

Add or update feature tests for:

- API tokens without `knowledge.create` cannot create shelves/books/chapters.
- API tokens without `knowledge.update` cannot update/delete hierarchy records.
- API-created shelf/book/chapter records use the same slug, priority, and
  `sync_status` rules as the UI.
- `sync_to_book_stack=true` marks records pending only when two-way sync is
  active.
- API-created and API-updated articles under BookStack-backed hierarchy are
  marked `pending_push`.
- BookStack status/test/pull/push API endpoints require Integration abilities.
- BookStack push API returns shelves/books/chapters/pages/skipped/failed/total.
- BookStack sync API returns sanitized errors and never exposes credentials.
- Existing Integration BookStack tests continue to pass.
- Existing Knowledge module tests continue to pass.

Run at minimum:

```bash
HOME=/tmp php artisan test app/Modules/Knowledge/Tests/Feature/KnowledgeArticleTest.php
HOME=/tmp php artisan test app/Modules/Integration/Tests/Feature/IntegrationModuleTest.php
```

## Documentation Plan

- Update `app/Modules/Knowledge/Docs/knowledge/knowledge-overview.md`.
- Update `app/Modules/Integration/Docs/knowledge/bookstack-integration.md`.
- Update `app/Modules/Integration/Docs/knowledge/api-management.md`.
- Update `app/Modules/Integration/README.md` to remove stale read-only wording.
- Sync updated Knowledge docs to BookStack after implementation if the
  integration is active:

```bash
HOME=/tmp php artisan knowledge:sync-docs --module=Knowledge --push
HOME=/tmp php artisan knowledge:sync-docs --module=Integration --push
```

## Open Questions

- Approve this RFC so Slice 1 and Slice 2 can be implemented?

## Approval

Approved by Svein Tore in conversation on 2026-06-16.
