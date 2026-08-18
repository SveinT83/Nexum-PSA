# Feature Slice: Local Advanced Mail Search And Rebuildable Index

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-email-local-search-mariadb-tika.md`
Owners: Email / Integration / Ticket / Taxonomy
Human Review: `HR-2026-08-16-022`

## Goal

Replace unbounded Mail text scans with a local, rebuildable, permission-aware advanced search over
subject, participants, body, clean supported attachment text, date, account, folder, personal/provider
state, tags, categories, conversations and authorized linked PSA context. Search/index failure never
blocks mail, and revocation/deletion removes results/snippets/facets without using the index as access
authority or hidden archive.

## Dependencies And Compatibility

Orders 5, 7-8, 13 and 21 must be stable: source-preserving canonical reads, provider reconciliation,
private projection invalidation, first-class Ticket relationships and attachment clean evidence.
Keep current decoded RFC2047 subject compatibility, raw subject/API output and simple `q` links. The
new parser/query is shared by Mail, legacy Inbox compatibility and Email API; no surface invents a
different authorization or token grammar.

## Additive Data Model

Reserve migrations after order 21, currently `2026_08_16_153000` and `154000`.

Add `email_search_documents` with one row per source Email message:

- source message, account, account-scoped conversation and source/canonical projection versions;
- normalized decoded subject, participant text, safe body text and bounded clean attachment text in
  separate columns with MariaDB FULLTEXT indexes;
- sent/received date, direction, has-attachment and safe normalized facet keys/hashes;
- attachment security/extractor/policy evidence versions, document version/status, indexed/stale/
  removed timestamps and source fingerprint;
- no raw MIME/HTML, BCC, credential/provider response, private path, canonical ID in public resources,
  Ticket body or inaccessible PSA content.

The relational source tables remain authoritative for folders, active placements, personal unread,
provider flags, tags/categories and Ticket/PSA links. Do not duplicate mutable authorization in the
document and trust it later.

Add `email_search_attachment_extractions`:

- exact Email attachment and SHA-256, clean evidence, extractor/parser/policy versions;
- status `pending`, `extracting`, `ready`, `unsupported`, `encrypted`, `oversized`, `stale`, `failed`,
  or `removed`, bounded normalized text and safe reason;
- tokenized claim/lease and timestamps. Unique exact evidence prevents duplicate extraction.

Add `email_search_index_runs` and items for initial/rebuild/repair/remove with frozen source bounds,
fingerprint, requested actor/system policy, status/progress/caps/cancellation and safe errors. Add an
append-only health/event ledger without queries/content. Down refuses while a search-only hold/export
or active run depends on the projection. Migration indexes/rebuilds nothing automatically.

## Indexing Pipeline

After an authoritative Email source/projection mutation commits, append one idempotent search-change
fact and dispatch on `email-search`. The worker:

1. re-reads the current source/account/conversation and active/retained lifecycle;
2. computes bounded normalized subject/participants/body without quoted-history/signature/raw HTML;
3. requests extraction only for exact current clean supported attachments;
4. compare-and-set upserts/removes the derived document by source fingerprint/version; and
5. appends opaque account/user invalidations after commit.

Provider move/flag changes update only relational facets. Provider deletion/retention purge removes or
redacts the document and extraction through the lifecycle action. A Ticket capture does not preserve
the Mail search document after Mail deletion; Ticket owns its own separately authorized search fact.
Access revocation bumps user/access versions and invalidates caches immediately even if the local
document still exists for another authorized user.

Workers use tokenized claims, bounded stale takeover, IDs/versions only in payloads, default 100/hard
500 documents per run and one extraction per claim with byte/time/output caps. Reordered/duplicate
events converge. Failure records lag/backlog and does not retry forever or roll back Mail.

## Query Grammar

Implement one strict parser with quoted phrases and free terms plus allowlisted filters:

- `from:`, `to:`, `cc:`, `subject:` and `attachment:`;
- `after:`, `before:` and exact date ranges;
- `account:`, `folder:`, `has:attachment`, `is:unread`, `is:read`, `is:flagged`, direction;
- `tag:`, `category:`, conversation/thread; and
- `ticket:` or other explicitly registered PSA filters only under that domain's current authority.

Unknown fields, malformed quotes/dates, excessive terms/depth and wildcard/operator injection return
a clear validation error. Users cannot pass raw SQL/MariaDB Boolean syntax. Normalize Unicode/email/
dates deterministically and retain literal `%`, `_`, `!` compatibility. Minimum/maximum term rules and
stopword behavior are disclosed in UI.

FULLTEXT provides ranked normal terms/phrases. A bounded hybrid exact/LIKE fallback preserves raw and
decoded subject, full address and short literal compatibility; it is never an unscoped whole-table
scan and obeys installation query/result/time caps.

## Authorization, Result Grouping And Snippets

Before any content result query, resolve current search authority for every selected account. Normal
owner/grant/delegation uses ordinary Mail Search; break-glass uses its exact Search operation and
records audit before querying. Personal unread predicates use the current epoch resolver and are
unavailable to break-glass.

SQL intersects active authorized source placements/accounts before ranking/counts. Results group by
durable account-scoped conversation, not placement, and choose a deterministic matching source/active
placement. A conversation with two placements/messages is counted once while showing match count and
authorized folder context. Pagination/counts/facets use the same authorized query and stable ordering.

Snippets are generated after authorization from normalized indexed text, escaped as text, bounded and
labelled by source field. Clean attachment filename/text appears only under current attachment
authorization/security. BCC, hidden Ticket/Client/entity, private path and raw headers are never
snippet/facet sources.

Ticket/PSA filters intersect current Work Context/domain View before joining. A user without Ticket
authority cannot infer a link/key/count. Search never turns a Ticket link into Mail access.

## UI And API

- Add compact advanced-filter controls to the existing responsive Mail workspace while retaining
  simple search and URL state. Controls collapse on mobile without changing the praised stack.
- Show active filters, result/facet counts, relevance/date sort, field-labelled snippets, index lag or
  partial state and a clear path to remove filters.
- Do not show unavailable filters/actions or stale cached content after revocation/deletion. Results
  open the exact authorized source placement and preserve back navigation.
- API uses explicit `email.search` ability plus exact account scope, same parser/query/caps and opaque
  cursors. It never returns index internals/extraction text beyond an authorized bounded snippet.
- Admin health/rebuild UI exposes safe counts/age/status/version, not search terms or mailbox content.

## Tika Extraction Boundary

Use the accepted local loopback Tika service only after exact order-21 `clean` evidence. Stream bytes
with original filename omitted or safely generic, no URL/path, redirects or external fetch. Enforce
format allowlist, default 10 MiB/hard policy cap, wall/CPU/memory/response limits and no outbound
network. Normalize untrusted output to bounded text and close every request on failure.

Malicious/non-clean/stale evidence removes existing extraction/document text. Tika unavailable leaves
the message searchable without attachment content and visibly queues bounded retry; it never falls
back to an external parser or blocks core Mail.

## Privacy, Lifecycle And Observability

No search term/snippet/body/address/filename is written to ordinary logs, metrics or audit. Record
actor, authorized scope IDs, parser version, safe filter categories, count bucket, duration/status and
rebuild health only. Rate limits and maximum pages prevent enumeration.

Documents/extractions follow provider deletion, Mail retention, account/user offboarding, DSAR, legal
hold and restore under order 23. Cache TTL is short and keyed by actor access/projection versions.
Backups are not a hidden permanent index; restoration quarantines stale index/outbound work and
requires rebuild before health is declared.

## Out Of Scope

- External/hosted search, fuzzy/semantic/AI retrieval, cross-installation search, indexing malicious/
  unscanned content, searching raw MIME/BCC or copying Ticket bodies into Mail search.
- Search itself mutating read/flag/folder/Ticket state.

## Tests

- Parser grammar, Unicode/decoded/raw subject, literal `%/_/!`, phrases/short/exact terms, injection,
  term/depth/date/cap errors and web/API parity.
- Subject/participants/body and clean supported attachment matches; malicious/non-clean/stale/changed
  evidence exclusion and Tika unavailable/timeout/malformed/oversized/output sanitization.
- Personal/shared accounts, owner/grant/delegation/revocation, break-glass audit/no-personal filters,
  Ticket/PSA Work Context isolation and no hidden counts/facets/snippets.
- Conversation grouping with multiple messages/placements, SQL total/page counts, relevance/date
  ordering, folder/flag/unread/tag/category/Ticket facets and stable source navigation.
- Index event redelivery/out-of-order/race, source changed/deleted during extraction, rebuild/resume/
  cancel/cap, lag/failure without sync/send rollback and compare-and-set winner.
- Provider deletion, retention purge, account/user revoke/offboard, Ticket capture, legal hold/DSAR/
  restore and cache invalidation/remove propagation.
- MariaDB FULLTEXT migration/query/EXPLAIN and representative benchmark; SQLite test adapter cannot
  substitute this check.
- UI desktop/mobile/keyboard/dark/zoom, API cursor/rate/no-leak, worker/scheduler/Tika health,
  migration/down guards and affected Email/Ticket/Taxonomy/Integration tests.

## Documentation And Operations

Update Email/Ticket/Integration Knowledge, search grammar/help, privacy/lifecycle and index/Tika
runbooks, API/OpenAPI, TODO, completion index and `docs/human-review.md`. Install/sandbox Tika, deploy
schema with `umask 0002`, start `email-search` worker, clear caches/rebuild views, run read-only cohort
preview then explicit rebuild. Deployment never queries external services or blocks Mail.

`HR-2026-08-16-022` remains Pending until a named reviewer checks real MariaDB plan/performance,
parser/facets/snippets, every authorization boundary, Tika/attachment states, revocation/deletion,
rebuild/outage, UI/API and sanitized telemetry.

## Done Criteria

- [ ] One local parser/query returns permission-filtered conversation results/counts/facets/snippets
  across required fields without hidden-scope leakage.
- [ ] Rebuildable MariaDB documents and clean-only sandboxed Tika extraction are bounded, versioned,
  lifecycle-aware and non-blocking to core Mail.
- [ ] Revocation/deletion/cache invalidation is immediate enough and verified; no external index or
  hidden archive exists.
- [ ] Tests, migrations, queues/services, UI/API, docs/runbooks and `HR-2026-08-16-022` are complete
  while human review remains Pending.
