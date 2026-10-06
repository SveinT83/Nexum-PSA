The BookStack integration connects Nexum PSA Knowledge with an external BookStack instance.

It supports revision-safe pull synchronization from BookStack into Nexum and exact-revision push
synchronization from Nexum Knowledge back to BookStack when two-way sync is enabled.

Nexum is the authoritative repository. Provider content is never allowed to silently overwrite a
diverged or historically unknown Nexum article.

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

## Revision And Conflict Model

Every sync-relevant article state has an immutable canonical revision hash. The per-article
BookStack state remembers the last proven common local and remote hashes, the exact synchronized
revision, pending outbound revision, inbound candidate revision, direction, origin, timestamps,
operation identity, and conflict state.

Existing BookStack-backed articles begin as `baseline_unknown`. The first equal pull may establish
a common base. An unequal unknown baseline becomes a review candidate and does not overwrite the
article.

For a known base:

- equal local and remote hashes are a no-op and prevent feedback loops;
- a remote-only change can fast-forward when automatic clean inbound sync is enabled;
- a local-only change remains pending outbound;
- simultaneous changes create a conflict candidate;
- a remote rename or move participates in the same decision;
- remote deletion preserves the article and creates a visible `remote_deleted` state.

The Knowledge article page shows current and candidate metadata and Markdown. Users with
`knowledge.manage_drafts` may accept the BookStack candidate as a normal proposal. It does not
change the published article until the proposal is approved and published. Users with the existing
sync-resolution permission may keep Nexum and bind the exact published revision for push.

The automatic clean inbound setting is independent of two-way outbound synchronization and is off
by default. With it disabled, remote changes remain review candidates.

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

A push binds the exact current published Nexum revision. Existing provider pages are read before
update so unexpected provider drift fails closed. Every write is followed by a separate read-back;
the read-back hash must exactly match the bound revision before the state is marked synchronized.

For the general Knowledge workflow, only an approved revision can enter BookStack publication. The
revision remains `publishing` and any linked Ticket documentation request remains open until this
provider read-back succeeds. Transport, provider, or read-back mismatch records a sanitized
`publication_failed` result; retry reuses the same revision and operation identity so it cannot
silently duplicate publication.

If a newer local edit is saved while an older push is in flight, the delivered revision is
recorded but the newer revision remains pending. An ambiguous create response is recovered only
when exactly one provider page matches the expected parent, title, and content identity. This makes
retries idempotent without guessing.

Missing external identifiers and remote deletion require an explicit Keep Nexum action before
recreation. Integration disablement or transport failure preserves pending and candidate state.

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

The job also uses a cross-process overlap lock. Re-enabling the integration causes a fresh
local/provider comparison; it does not replay stale assumptions.

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
push summary, automatic inbound policy, and last error, but never returns token ID or token secret
values.

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

## Large Page Storage

Knowledge stores `articles.body_markdown` and nullable `articles.body_html` as `MEDIUMTEXT`. This
preserves BookStack pages above the 65,535-byte `TEXT` ceiling without truncation. The schema change
is owned by `2026_08_25_210000_expand_knowledge_article_body_capacity`; its rollback refuses to
shrink while either body contains more than 65,535 bytes.

A production rollout requires a database backup, paused BookStack/default workers during migration,
column-type read-back, `php artisan optimize:clear`, worker restart, and one controlled full pull.

## Safety Rules

Do not treat a legacy source checksum as proof of a common revision.

Do not overwrite a diverged Nexum article with provider content.

Do not delete a Nexum article because the provider page is missing.

Do not mark an outbound revision synchronized until provider read-back proves the exact canonical
hash.

Do not overwrite BookStack source metadata when updating repository-owned documentation except
through the guarded synchronization actions.

Do not create duplicate chapters or pages when matching content by slug inside the existing Nexum PSA book.

Do not print API tokens or database passwords while debugging sync issues.

Production rollout of revision-safe synchronization requires the migration and operational checks
in `HR-2026-09-03-003`.

The general Knowledge approval/publication layer additionally requires migration
`2026_09_04_080000_add_knowledge_revision_workflow` and the permission, review, retry, and
read-back checks in `HR-2026-09-04-001`.
