# RFC: Knowledge And BookStack Revision-Safe Synchronization

Status: Approved
Implementation: Done On Dev (2026-09-03)
Date: 2026-09-03
Owner: Svein / Codex
Related Issue: GitHub #276

## Approval

Svein explicitly requested implementation of GitHub Issue #276 on 2026-09-03. This RFC records the
issue's approved behavior and the reversible technical choices required to implement it. It does
not approve the broader Knowledge draft, approval, rollback, or publishing lifecycle tracked by
GitHub Issue #266.

## Context

The existing BookStack integration stores source checksums on Knowledge articles, but pull can
replace local content whenever the provider checksum changes. Push treats an accepted write
response as success without separately proving which provider revision was stored. That is unsafe
when Nexum and BookStack change concurrently, when a queued job is stale, or when a provider write
succeeds but its response is lost.

This is Level 3 work because it changes integration conflict behavior, introduces immutable sync
revision data, adds operator actions and permissions, and requires a database migration.

## Goals

- Identify the exact Nexum article revision participating in each sync decision.
- Remember the last proven common local and remote content hashes per article.
- Fast-forward clean inbound changes only when the local article still matches that exact base and
  the administrator has enabled automatic clean inbound synchronization.
- Preserve both versions and show a visible review candidate whenever local and remote content
  have diverged or the historical baseline is unknown.
- Push one exact published Nexum revision and verify it by a separate provider read-back.
- Keep newer local edits pending when an older queued push finishes.
- Make retries idempotent, including ambiguous provider responses after create.
- Preserve articles and pending work while BookStack is disabled or unavailable.
- Handle remote moves, renames, deletion, and missing external identifiers without guessing.
- Prevent Nexum-to-BookStack-to-Nexum feedback loops.
- Expose only sanitized provider failures in UI, API status, and persisted summaries.

## Non-Goals

- No full Knowledge draft, approval, publication, comparison, or general rollback workflow.
- No automatic text merge.
- No destructive local deletion when a BookStack page disappears.
- No claim that pre-migration source checksums prove a common historical revision.
- No production migration, worker restart, or automatic policy activation in this Dev change.

## Revision Identity

Every sync-relevant article state is represented by an immutable Knowledge article revision. Its
canonical SHA-256 identity includes title, Markdown body, publication state, priority, and
BookStack book/chapter placement. The revision stores the content snapshot needed for review and
an origin such as local, BookStack inbound, remote candidate, or migration baseline.

The per-article BookStack state stores the last proven local and remote hashes, last synchronized
revision, pending outbound revision, inbound candidate revision, external page identity,
direction, origin, timestamps, operation identity, and bounded reason/snapshot metadata.

Existing BookStack-backed articles are migrated to `baseline_unknown`. Equality on a later pull
may establish a known common base. Inequality creates a candidate; it never overwrites the article.

## Inbound Decision

For each provider page, the pull records the current local revision and the remote canonical hash.

- Equal hashes establish or retain a synchronized base and perform no content write.
- Remote-only change fast-forwards only from the exact known base and only when automatic clean
  inbound synchronization is enabled.
- Local-only change remains pending outbound.
- Simultaneous change, disabled automatic inbound, or unknown unequal baseline stores an immutable
  candidate and visible conflict state.
- A provider rename or move participates in the same hash and conflict decision.
- A missing provider page marks the state `remote_deleted` but preserves the Nexum article.
- A transient page-read failure does not masquerade as remote deletion.

Accepting a candidate is an explicit `knowledge.update` action. It creates a new Nexum revision and
records the remote state as the new common base. Keeping Nexum binds the current published revision
as an explicit conflict-resolving outbound operation.

## Outbound Decision

Push binds the exact current published revision before the provider write. Existing pages are
read first; unexpected provider drift blocks the write unless an operator explicitly chose Keep
Nexum. Create and update are followed by a separate read-back whose canonical hash must exactly
match the bound revision.

After network activity, the action reloads the article. If a newer local revision now exists, the
older revision may be recorded as delivered but the newer revision remains pending outbound.

An ambiguous create response is recovered by looking for exactly one provider page with the
expected parent, title, and content identity before attempting another create. Zero or multiple
matches fail closed. Shelf, book, chapter, page, and shelf-membership writes are read back.

## Availability And Retry Behavior

The queued push job has a cross-process overlap lock. Disabled or incomplete integrations exit
without clearing article state. Transport failures retain pending/candidate state and store only
sanitized operational messages. A later retry re-evaluates the current local and provider hashes.

## Permissions And UI

The existing Knowledge article view shows BookStack state. Conflict, inbound candidate, remote
deletion, and missing-identifier states show the current revision beside the candidate or reason.
Only users with `knowledge.update` may accept the candidate or keep Nexum and push. Ordinary
article visibility rules still control who may see the page or its candidate content.

The BookStack settings page controls automatic clean inbound synchronization separately from
two-way outbound synchronization and reports candidates, conflicts, and remote deletions.

## Data Migration

Migration `2026_09_03_170000_create_knowledge_article_sync_revisions` creates immutable revision
and per-article sync-state tables, then baselines existing BookStack-backed articles without
claiming known history. Rollback removes only the new synchronization evidence tables.

## Operational Rollout

Production requires a database backup, paused BookStack/default workers, migration, schema and
baseline-count read-back, cache clear, worker restart, one controlled pull, and human review
`HR-2026-09-03-003`. Automatic clean inbound remains off unless an administrator explicitly
enables it.

## Tests

Required coverage includes clean inbound and outbound, unknown baseline, simultaneous edits,
automatic inbound disabled, stale jobs, duplicate-safe ambiguous create recovery, provider drift,
remote move/rename/deletion, disabled integration, retry preservation, and feedback-loop
prevention. Existing BookStack integration regression coverage must remain green.

## Documentation

Update the BookStack integration and Knowledge overview articles, TODO register, ADR, Feature
Slices, human-review register, and public-safe website handoff.
