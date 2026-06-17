The BookStack integration connects Nexum PSA Knowledge with an external BookStack instance.

It supports pull synchronization from BookStack into Nexum and push synchronization from Nexum Knowledge back to BookStack when two-way sync is enabled.

## Ownership

The Integration domain owns the BookStack API connection and sync jobs.

The Knowledge domain owns the local content model:

- Shelves.
- Books.
- Chapters.
- Articles.

Integration code should not duplicate Knowledge persistence rules. It should use Knowledge models and sync metadata.

## Pull Sync

BookStack pull sync imports shelves, books, chapters, and pages into Knowledge.

Imported records store source metadata:

- `source_system = book_stack`
- `source_type`
- `source_id`
- `source_url`
- `source_checksum`
- `source_synced_at`
- `source_updated_at`

This metadata is used to identify later updates and avoid duplicate content.

## Push Sync

Push sync processes Knowledge records marked as:

```text
sync_status = pending_push
```

The push action can create or update:

- Shelves.
- Books.
- Chapters.
- Pages.

BookStack-backed records are updated using their existing BookStack `source_id`.

Locally-owned records can be created in BookStack when their parent book or chapter has enough BookStack metadata to place them correctly.

## Worker

Queued push is handled by:

```text
App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack
```

The job checks that:

- The BookStack integration exists.
- The integration is active.
- Two-way sync is enabled.
- Server URL and API tokens are configured.

If any requirement is missing, the job exits without pushing.

## Manual Operations

Repository documentation can be synced into Knowledge and queued for BookStack push with:

```bash
php artisan knowledge:sync-docs --push
```

Administrators can also use the BookStack integration settings page to pull from BookStack or push pending local Knowledge changes.

## API Operations

Trusted automation can inspect and run BookStack sync through the Integration API.

Scopes:

- `integration.bookstack.read`
- `integration.bookstack.run`

Routes:

- `GET /api/v1/integrations/book-stack/status`
- `POST /api/v1/integrations/book-stack/test`
- `POST /api/v1/integrations/book-stack/pull`
- `POST /api/v1/integrations/book-stack/push`

The status response is sanitized. It includes health, timestamps, sync mode, last pull summary, last
push summary, and last error, but never returns token ID or token secret values.

Push summaries include shelves, books, chapters, pages, skipped, failed, total, and errors. Skipped
records caused by missing synced parents are treated as unhealthy so API agents can detect and repair
the hierarchy before retrying.

## Safety Rules

Do not overwrite BookStack source metadata when updating repository-owned documentation.

Do not create duplicate chapters or pages when matching content by slug inside the existing Nexum PSA book.

Do not print API tokens or database passwords while debugging sync issues.
