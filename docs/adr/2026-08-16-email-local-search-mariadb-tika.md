# ADR: Local Email Search With MariaDB Full-Text And Sandboxed Tika

Status: Accepted
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Related ADRs:
- `2026-08-11-email-owned-mail-client-domain.md`
- `2026-08-11-email-mailbox-access-and-rule-authority.md`
- `2026-08-16-email-attachment-quarantine-local-clamav.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-local-advanced-search.md`

## Context

Mail currently uses parenthesized SQL `LIKE` clauses across raw/decoded subject, sender and body.
MariaDB already has a `FULLTEXT(subject, body_text)` index, but the current query does not use it and
cannot provide bounded ranked search, phrases, facets, clean attachment extraction, durable indexing
health or rebuild/progress. The accepted Mail RFC requires a local/installation-controlled default,
permission-aware counts/snippets, immediate revocation filtering, deletion propagation and an index
failure mode that never blocks core mail.

Choosing a hosted search service would create a new mailbox-content processor. Clean attachment
content also needs a parser; extensions/MIME alone are insufficient, and running arbitrary parser
commands with filenames would create a shell/file boundary.

## Decision

Use a rebuildable local MariaDB/InnoDB search-document table with native `FULLTEXT` indexes as the
default Mail search backend. Use a separately supervised Apache Tika server on loopback for bounded
text extraction from supported attachments only after exact current ClamAV clean evidence. Neither
service is an authorization source or a durable archive.

### Search Documents

Create one derived document per source Email message, bound to its account, conversation and source
projection versions. Store normalized decoded subject, participant tokens, body text and bounded
clean attachment extraction in separate columns plus non-content facet keys. Use native full-text
ranking for normal terms/phrases and relational predicates for account/folder/date/flag/unread/tag/
category/Ticket facets. Preserve the existing decoded/raw exact search compatibility through a
bounded hybrid fallback for short/literal terms.

Every result query begins with current mailbox/search authority and active source-placement scope.
Ticket/PSA filters additionally intersect their domain's current authorization. Snippets and facet
counts are derived only from that authorized result set. The index never grants access and is never
queried first to reveal hidden accounts/counts.

Indexing is asynchronous on an `email-search` queue after durable Mail projection changes. Failure or
lag is visible but never rolls back inbound sync, provider reconciliation, send, Ticket capture or
rules. Deletion/revocation/source-content changes invalidate cached user results immediately and
enqueue bounded document update/removal. Rebuild runs are explicit, resumable and idempotent.

### Attachment Extraction

Tika runs locally in a sandboxed service identity/container with no outbound network, no provider
credentials, a read-only runtime, bounded memory/CPU/time/request/response, parser allowlist and
loopback-only endpoint. Nexum streams bytes; it never gives Tika a filesystem path or URL. Endpoint
configuration is fixed/allowlisted and uses no redirects.

Only an attachment whose exact SHA-256 has current `clean` evidence may be sent to Tika. Unsupported,
encrypted, oversized, malicious, stale or scanner-unavailable files produce typed no-text evidence.
Extractor output is untrusted text: normalize/control-strip, cap bytes/codepoints/nesting and never
render as HTML. Store extractor/version/policy/hash provenance so changed bytes or policy cannot reuse
old text.

### Privacy And Operations

Search text/documents remain inside the installation database. No external search or Tika endpoint is
authorized. Query logs/audit record actor, scope IDs, result count bucket, timing and safe parser code,
not the search term, snippets, subject, sender, filenames or body. Per-user cached result IDs are
short-lived, access-version keyed and contain no copied content.

Production requires MariaDB FULLTEXT capability, a supervised `email-search` worker, local Tika,
health/backlog/rebuild monitoring, storage/retention capacity and representative EXPLAIN/benchmark.
SQLite tests use the same parser/policy with a deterministic test adapter; they do not pretend to
prove MariaDB query plans.

## Rationale

- Existing MariaDB avoids a new external durable mailbox-content service.
- A derived table can be rebuilt/purged independently and keeps search failure out of core mail.
- Relational authorization/facets remain exact while FULLTEXT provides ranking and scalable text
  lookup.
- Local sandboxed Tika supports common clean document formats without shelling out with attacker
  filenames.
- Explicit parser/extractor versions make stale results and rebuilds observable.

## Alternatives Considered

- **Hosted Algolia/Elastic/OpenSearch.** Rejected without separate data-egress, tenant isolation,
  region/legal, encryption, retention, deletion and cost approval.
- **Self-hosted Meilisearch/OpenSearch.** Not selected as the default because it adds another durable
  replicated content store and operational lifecycle before current scale requires it.
- **Keep only `%LIKE%`.** Retained for narrow compatibility, rejected as the advanced backend because
  it lacks scalable ranking/facets/index health and attachment extraction.
- **Run `pdftotext`/LibreOffice/unzip directly per file.** Rejected as a fragmented shell/process
  boundary with inconsistent format and sandbox behavior.
- **Index raw MIME/HTML.** Rejected because it leaks untrusted/quoted/hidden content and attachment
  bytes outside the explicit safe projections.

## Consequences

Positive:

- Search stays installation-local and permission-filtered with observable rebuild/lag.
- Normal Mail continues during index/parser outages.
- Clean attachment text can participate under one malware/content-policy boundary.

Negative:

- MariaDB-specific integration tests/benchmarks and Tika operations are required.
- Derived text consumes database/storage capacity and must follow every lifecycle/hold/deletion path.
- FULLTEXT tokenization/language behavior differs from substring search and needs clear UI semantics.
- Tika is a parser attack surface and must remain sandboxed, patched and resource-limited.

## Follow-Up

- Implement the linked order-22 slice after order 21 and keep `HR-2026-08-16-022` Pending until real
  MariaDB plan/performance, Tika outage/sandbox, revocation, deletion and browser/API behavior pass.
- Any external or alternative durable search provider requires a new ADR and explicit data-egress
  approval rather than a configuration-only switch.
