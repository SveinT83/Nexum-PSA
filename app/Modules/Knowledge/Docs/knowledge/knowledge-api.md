Verified on Dev and merged to Main in PR #331 on 2026-10-27. Production is unchanged.
Human scenario review remains Pending. Do not describe this as deployed production behavior.

## Before You Start

Use an active internal account and an API key from **Admin > System > Integrations > API**.
A key's abilities narrow the account's current native permissions. A token cannot grant missing
user permissions. Use **API documentation** (`/api/documentation`) on the target installation for
the complete typed request, response and error contracts. Customer API keys use their own active
portal account, contact and membership rather than staff rights.

## Reading And Maintaining The Library

1. Read `GET /api/v1/knowledge/context` to discover defaults, current native capabilities and
   provider status. Read `GET /api/v1/knowledge/lookups?type=shelves` (or `books`, `chapters`,
   `categories`, `clients`, `tags`) for bounded form options. Use `shelf_id`/`book_id` to narrow
   child options. Lists accept `page` and `per_page` from 1 to 100; `q` is at most 255 characters.
2. Use the shelf, book, chapter and article collection/detail endpoints. Book/chapter details
   include the first 100 reading-order entries. Continue through the paginated article/chapter/book
   catalogs using their parent IDs for the complete library. Article filters include status,
   visibility, hierarchy, category, client and `review_due`. Internal Knowledge remains distinct
   from Work Context and does not adopt Documentation's structured-document scope model.
3. Create or update hierarchy using the matching endpoint. Deletion requires an empty container.
   Existing externally owned records remain subject to BookStack's active two-way policy.
4. To create an article, POST its title/body and desired metadata. To edit an existing one, PUT or
   PATCH its exact ID. The result returns `meta.revision_id`, `meta.revision_state` and
   `meta.published_unchanged`. An edit proposes an immutable revision; it does not replace the
   published page. Choose consistent shelf/book/chapter IDs. Unsupported system/source fields fail.
5. Saved article tags are live metadata, matching the current **Tags** component: POST `tag_id`
   or a new `name` to `/articles/{article}/tags`, or DELETE `/articles/{article}/tags/{tag}`.
   This does not publish pending article text. Both the web component and API require draft
   management rights for Knowledge mutation. The shared tag register remains Taxonomy-owned.

## Reviewing And Publishing An Exact Revision

1. Read `/articles/{article}/revisions` and `/revisions/{revision}`. Inspect the full proposed
   title/body/metadata, concise line diff, immutable identity and transition events.
2. POST `/revisions/{revision}/approve` after checking that exact proposal, or `/reject` with a
   required `note`. These require explicit revision-review token abilities and the current native
   approval permission. A Ticket-only technician can view and approve only the linked eligible AI
   revision under the existing Ticket boundary; this never grants library or publication access.
3. A publisher explicitly POSTs `/revisions/{revision}/publish`. Local publication changes the
   published projection and verifies exact content. Externally backed publication remains
   `publishing` / `pending_external` until BookStack read-back proves the same revision.
4. Retry `/revisions/{revision}/retry` only for the same retryable workflow revision after
   reconciling an uncertain transport operation. A stale base fails without replacing newer
   content. `/rollback` creates a new proposal from historical content and preserves history;
   it still needs its own approval and publication.
5. Repository-owned guides are changed in their repository source and synchronized with
   `knowledge:sync-docs`. Ordinary API or AI proposals cannot replace that authority.

## BookStack Conflicts And Sync

Read `/articles/{article}/book-stack` for current state, exact candidate/pending/published IDs and
last proven sync. `accept-remote` submits the candidate to normal review; it does not publish it.
`keep-nexum` queues the exact current published revision for downstream conflict resolution.
The library's **Sync Now** behavior is available through POST `/knowledge/book-stack/pull`, using
the existing Integration action and current Integration permissions. Its result is a bounded
sanitized summary. An HTTP response or queued job alone does not prove external publication.

Provider effects, external hierarchy deletion, externally backed publication, sync or outbound
resolution require `knowledge.providers.execute`, the current `knowledge.sync_bookstack` user
permission, valid integration policy and the default-off `KNOWLEDGE_API_PROVIDER_EXECUTION_ENABLED`
runtime switch. API availability does not enable this switch, activate the integration, add user
rights or start workers. An administrator must arrange any controlled activation separately.

## Defaults And Customer Access

`GET /knowledge/settings` and `PUT /knowledge/settings` correspond to **Admin > Knowledge
Settings** and require `knowledge.manage_settings` plus separate settings read/write token
abilities. They maintain visibility/status, review interval (1-3650 days) and priority (0-100000).
They do not expose provider credentials or publish content automatically.

Portal list/detail are `/knowledge/portal/articles` and `/knowledge/portal/articles/{article}`.
They require `knowledge.portal.read` and the caller's active account/contact/membership. An explicit
`membership_id` must be the caller's current valid membership; an invalid selection never falls
back to another customer. Only published public and matching client-wide articles are included.
Internal, draft, other-client and staff provenance/revision fields are excluded. Existing portal
session authentication also works. A missing or hidden individual article returns 404.

## Safe Writes And Exact Result Read-Back

1. Send `Authorization: Bearer <token>`, `Accept: application/json` and a unique `Idempotency-Key`
   (8-200 characters) for every logical write. New write actions and all provider execution require
   the key. Legacy CRUD still accepts a missing key for compatibility; such calls cannot safely
   be retried as the same logical operation. Do not put credentials or private content in keys.
2. Read the target's `ETag`; send the unchanged value in `If-Match` when writing that state.
   A stale token returns 412. A reused key with different method/path/payload/precondition returns 409.
3. Preserve the returned `operation_id` or `X-Operation-Id`. Read
   `/knowledge/operations/{command}` using the same user/token plus `knowledge.operations.read`.
   Current original rights are rechecked on replay and receipt lookup. Results are encrypted at rest.
4. Repeat a request only with its original key and identical input. A known completed result replays
   without duplicate mutation. Provider claims are persisted before external work. `running` or
   `unknown`, or HTTP 202 with `reconciliation_required`, means stop and reconcile the exact
   local/provider state with an administrator. A new key cannot bypass an unresolved same-target
   claim. Do not infer failure or success from a timeout or send a fresh automatic attempt.
5. Keep three outcomes separate: local operation persistence, downstream queue acceptance and
   verified provider content. Linked documentation requests close only after their native workflow
   has the required exact read-back, independently of the Ticket's support closure.

## Troubleshooting And Access

- 401: authenticate with a current key; revoked keys must be replaced through API Management.
- 403: check both the key ability and the user's current native permission/object access.
- 404: verify the exact record and current customer membership without guessing other customers.
- 409: inspect key reuse, default-off provider policy or the retained uncertain claim.
- 412: reload the exact target and review the concurrent change before preparing a new write.
- 422: use the returned field/state errors, including hierarchy, stale revision or repository authority.
- 428: supply the required idempotency key for the logical write.
- 503: the required migration/runtime dependency is unavailable; ask the administrator to restore it.

Role/team article audiences and AI consumption (#265/#261), and the separate structured
Documentation revision lifecycle (#275), remain distinct work. Current API parity does not claim
those future capabilities or widen employee/customer access.

## Operation And Scope Reference

| Method | Path after /api/v1/ | Token ability | Native authority |
| --- | --- | --- | --- |
| GET | knowledge/shelves | knowledge.read | knowledge.view |
| POST | knowledge/shelves | knowledge.create | knowledge.create |
| GET | knowledge/shelves/{shelf} | knowledge.read | knowledge.view, knowledge.update |
| PUT/PATCH | knowledge/shelves/{shelf} | knowledge.update | knowledge.update |
| DELETE | knowledge/shelves/{shelf} | knowledge.update | knowledge.delete |
| GET | knowledge/books | knowledge.read | knowledge.view |
| POST | knowledge/books | knowledge.create | knowledge.create |
| GET | knowledge/books/{book} | knowledge.read | knowledge.view, knowledge.update |
| PUT/PATCH | knowledge/books/{book} | knowledge.update | knowledge.update |
| DELETE | knowledge/books/{book} | knowledge.update | knowledge.delete |
| GET | knowledge/chapters | knowledge.read | knowledge.view |
| POST | knowledge/chapters | knowledge.create | knowledge.create |
| GET | knowledge/chapters/{chapter} | knowledge.read | knowledge.view, knowledge.update |
| PUT/PATCH | knowledge/chapters/{chapter} | knowledge.update | knowledge.update |
| DELETE | knowledge/chapters/{chapter} | knowledge.update | knowledge.delete |
| GET | knowledge/articles | knowledge.read | knowledge.view |
| POST | knowledge/articles | knowledge.create | knowledge.manage_drafts |
| GET | knowledge/articles/{article} | knowledge.read | knowledge.view, knowledge.manage_drafts |
| PUT/PATCH | knowledge/articles/{article} | knowledge.update | knowledge.manage_drafts |
| DELETE | knowledge/articles/{article} | knowledge.update | knowledge.delete |
| GET | knowledge/context | knowledge.read | knowledge.view, knowledge.manage_drafts, knowledge.create, knowledge.update, knowledge.manage_settings |
| GET | knowledge/lookups | knowledge.read | knowledge.view, knowledge.manage_drafts, knowledge.create, knowledge.update, knowledge.manage_settings |
| GET | knowledge/articles/{article}/revisions | knowledge.revisions.read | knowledge.view |
| GET | knowledge/revisions/{revision} | knowledge.revisions.read | Exact object/current authority |
| POST | knowledge/revisions/{revision}/approve | knowledge.revisions.approve | Exact object/current authority |
| POST | knowledge/revisions/{revision}/reject | knowledge.revisions.approve | knowledge.approve |
| POST | knowledge/revisions/{revision}/publish | knowledge.revisions.publish | knowledge.publish |
| POST | knowledge/revisions/{revision}/retry | knowledge.revisions.publish | knowledge.publish |
| POST | knowledge/revisions/{revision}/rollback | knowledge.revisions.rollback | knowledge.rollback |
| GET | knowledge/articles/{article}/book-stack | knowledge.read | knowledge.view |
| POST | knowledge/articles/{article}/book-stack/accept-remote | knowledge.update | knowledge.manage_drafts |
| POST | knowledge/articles/{article}/book-stack/keep-nexum | knowledge.sync | knowledge.update |
| GET | knowledge/settings | knowledge.settings.read | knowledge.manage_settings |
| PUT | knowledge/settings | knowledge.settings.write | knowledge.manage_settings |
| POST | knowledge/articles/{article}/tags | knowledge.update | knowledge.manage_drafts |
| DELETE | knowledge/articles/{article}/tags/{tag} | knowledge.update | knowledge.manage_drafts |
| POST | knowledge/book-stack/pull | knowledge.sync | integration.view |
| GET | knowledge/operations/{command} | knowledge.operations.read | Exact object/current authority |
| GET | knowledge/portal/articles[/{article}] | knowledge.portal.read | Current own portal account/contact/membership |
