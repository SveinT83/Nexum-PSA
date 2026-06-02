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

## API

Knowledge article API routes are available under `/api/v1/knowledge/articles`.

Scopes:

- `knowledge.read`
- `knowledge.create`
- `knowledge.update`

Routes:

- `GET /api/v1/knowledge/articles`
- `GET /api/v1/knowledge/articles/{article}`
- `POST /api/v1/knowledge/articles`
- `PUT /api/v1/knowledge/articles/{article}`
- `PATCH /api/v1/knowledge/articles/{article}`

`POST /api/v1/knowledge/articles` uses `StoreArticle`, so article defaults, owner/creator metadata,
slug generation, and Markdown rendering stay aligned with the Tech UI.

`PUT` and `PATCH /api/v1/knowledge/articles/{article}` use `UpdateArticle`, which re-renders
Markdown and updates the slug when the title changes.

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
