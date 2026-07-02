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

Articles edited inside Nexum are saved locally first. If they belong to a BookStack-backed hierarchy and two-way sync is enabled, the page is marked for push.

Knowledge visibility is separate from Work Context. `internal`, `client-wide`, and `public` decide
who can read an article. They do not mean that the article itself owns internal or client work.
Client-wide articles may use `client_scope_id`, while public and internal articles clear that
client-specific scope.

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

`POST /api/v1/knowledge/articles` uses `StoreArticle`, so article defaults, owner/creator metadata,
slug generation, and Markdown rendering stay aligned with the Tech UI.

`PUT` and `PATCH /api/v1/knowledge/articles/{article}` use `UpdateArticle`, which re-renders
Markdown and updates the slug when the title changes.

Hierarchy create and update endpoints accept `sync_to_book_stack`. When BookStack is active and
two-way sync is enabled, this marks the shelf, book, chapter, or article as `pending_push` and queues
the BookStack push worker. API-created articles under BookStack-backed books or chapters are also
marked for push, matching the Tech UI.

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

This command updates Knowledge records and marks changed records as `pending_push`. It does not replace the BookStack integration. It prepares local Knowledge content for the existing push worker.

## BookStack Ownership

If a Knowledge record already comes from BookStack, repository sync must preserve:

- `source_system`
- `source_type`
- `source_id`

This prevents duplicate pages and ensures the existing BookStack page is updated instead of creating a separate local copy.

## Operational Notes

The queue worker must run for queued BookStack pushes.

If Artisan commands cannot connect to the development MySQL server while the web application can, verify sandbox/network restrictions before changing `.env` or database settings.
