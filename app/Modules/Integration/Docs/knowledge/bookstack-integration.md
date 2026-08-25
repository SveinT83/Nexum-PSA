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

## Rate-Limit Coordination And Diagnostics

BookStack requests for the same configured server and token identity share one cache-backed request
reservation across web, scheduler, and queue processes. The cache key contains only a hash of the
connection identity; it does not expose the server address, token ID, or token secret.

Normal requests default to one request per second. A `429 Too Many Attempts` response publishes a
shared cooldown, honors numeric or HTTP-date `Retry-After` values and `X-RateLimit-Reset`, and falls
back to 15, 30, and 60 second retry delays. This prevents a second PHP process from immediately
repeating a request while another process is already rate limited.

The Integration cache store must support atomic locks and be shared by every web and worker process
for cross-process coordination. The standard database, Redis, and file cache stores support this
contract when all processes use the same configured store.

New BookStack failures record `last_error_at`. Admin shows the exact recorded timestamp with the
last error, and the sanitized status API returns it without credentials or raw provider payloads.
Historical errors without recorded timing are labelled accordingly.

## Safety Rules

Do not overwrite BookStack source metadata when updating repository-owned documentation.

Do not create duplicate chapters or pages when matching content by slug inside the existing Nexum PSA book.

Do not print API tokens or database passwords while debugging sync issues.
