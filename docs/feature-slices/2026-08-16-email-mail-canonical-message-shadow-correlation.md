# Feature Slice: Email Canonical Message Shadow Correlation

Status: Done / Human Review Pending
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADRs:
- `docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`
- `docs/adr/2026-08-11-email-owned-mail-client-domain.md`
Owner: Svein / Codex
Human review: `HR-2026-08-16-004`

## Purpose

Build the conservative, rebuildable shadow evidence required before several mailbox placements may
ever point at one canonical message. The current `email_messages` rows and every placement, Ticket
link, attachment, raw snapshot, conversation, user state, rule result, Smart Inbox record, API read,
and provider operation remain authoritative and unchanged in this slice.

The shadow report answers only whether two current content records are a strong same-delivery
candidate, a weaker possible duplicate, demonstrably different, or too ambiguous to decide. It does
not merge, deduplicate, relink, hide, delete, or change a read path.

## Correlation Boundary

- Candidate discovery is account-safe and begins from bounded indexed facts: normalized Message-ID
  where present, exact provider/content checksum evidence, or an explicit existing relationship.
  Subject similarity alone never creates a candidate.
- Reused, missing, malformed, or synthetic Message-ID values never become a unique identity.
- A candidate is **strong** only when the normalized delivery variant agrees on the complete evidence
  available to both rows: normalized Message-ID, sender, normalized recipients, direction, delivery
  time within the documented tolerance, body/content hash, and attachment metadata/content hashes.
  Missing evidence lowers the result to ambiguous; it never counts as agreement.
- Cross-account candidates always remain separate records in shadow mode. A match cannot widen
  mailbox, conversation, Ticket, search, attachment, raw-source, AI, or API authorization.
- BCC/header variants, recipient differences, attachment differences, sanitized-body differences,
  conflicting raw hashes, or materially different dates make the delivery variants different even
  when Message-ID and subject match.
- All fingerprints are versioned, deterministic, order-independent where the source is a set, and
  contain hashes/reason codes rather than copied subject, address, filename, body, header, or raw
  source.

## Data Contract

Add Email-owned, rebuildable shadow records:

1. `email_canonical_correlation_runs`: actor, bounded account/message scope, algorithm version,
   frozen minimum/maximum message IDs, evidence snapshot/run byte budgets, aggregate evidence bytes,
   status/counters, started/finished timestamps, and sanitized failure.
2. `email_canonical_correlation_candidates`: run, stable ordered pair of message IDs, each owning
   account ID, candidate class, reason codes, versioned evidence hashes, review state, and timestamps.
3. `email_canonical_correlation_inspections`: candidate, inspecting actor, exact evidence hashes, and
   inspection time. This is the content-review audit prerequisite; it contains no message content.

The ordered pair is unique per run. The three tables use explicit `DATETIME` audit instants. A
rebuild may delete only a run whose candidates remain unreviewed and have no inspection audit; the
schema cascades keep that purely rebuildable cleanup atomic. Deleting an Email message must not
cascade a retained human decision silently. Reviewed exclusions and inspection audit therefore need
a durable carry-forward key or explicit preservation before any later cleanup.

No canonical ID is added to an externally visible resource and no current foreign key is changed in
this slice.

## Workflow

- A maintenance action creates a bounded run from a frozen local scope. It performs no provider,
  network, AI, rule, Ticket, Notification, or mailbox mutation.
- Discovery is chunked and resumable. Candidate groups and pair counts have hard caps; an oversized
  group is recorded as `ambiguous_oversized_group` and requires narrower review rather than an
  unbounded Cartesian comparison.
- The initial and final frozen snapshots are each capped at 64 MiB of conservatively estimated local
  evidence input. The complete run has a durable 256 MiB aggregate evidence-read cap. Raw files are
  not hashed before the lightweight SQL/filesystem-size preflight proves the scope is within budget.
  Exceeding either cap fails closed and requires a narrower message-ID window.
- Re-running the same algorithm and frozen scope is idempotent. A later algorithm version creates a
  new run; it does not rewrite previous review evidence.
- The Admin report is metadata-only. Configuration-only operators see account-scoped counts,
  candidate class, reason codes, and opaque candidate IDs, never message content or the existence of
  inaccessible personal mailbox content. Content inspection uses ordinary current mailbox View and
  a separately audited review action.
- Review may mark a pair `confirmed_candidate`, `keep_separate`, or `needs_more_evidence`; shadow
  review still performs no merge. `confirmed_candidate` and `keep_separate` require an exact current
  audited inspection by that reviewing actor. Inspection and review reauthorize ordinary current
  View independently for both recorded accounts and require each message to remain bound to the
  exact account recorded on the candidate. A moved-account or otherwise mismatched message is
  unavailable rather than disclosed or reviewed.
- If precise and oversized discovery paths overlap, the deterministic fail-safe result remains
  oversized and explicitly requires a narrower scope; discovery order cannot hide that condition.

## Required Verification

- Exact same-account and cross-account delivery variants; missing/reused/malformed Message-ID;
  recipient/BCC, body, date, raw, and attachment divergence; missing evidence; synthetic mail; and
  oversized candidate groups.
- Deterministic order-independent fingerprints, algorithm-version isolation, resumable/idempotent
  runs, frozen high-water, bounded queries, retry after partial failure, and no N-squared unbounded
  path.
- No placement/message/conversation/Ticket/tag/classification/user-state/raw/attachment/provider
  mutation and no timestamps changed on authoritative rows.
- Personal/shared account authorization, metadata-only Admin output, hidden inaccessible candidates,
  cross-account no-leak behavior, and execution-time reauthorization for content review.
- Existing Mail workspace, Inbox/API, search, Ticket linkage, Smart Inbox, retention, provider
  deletion, remote operation, historical import, and attachment regressions remain unchanged.

## Deploy And Rollback

- Additive migrations only. Do not launch a run from migration or deployment.
- Clear caches and restart long-lived workers after code/schema rollout. The first Dev action is a
  bounded preview/report and must record exact counts before any later cutover slice is opened.
- Rollback removes only unreviewed rebuildable shadow data. Reviewed exclusions or confirmations must
  be exported or carried forward explicitly. Any inspection audit also blocks rollback until it is
  exported or carried forward. Rollback never changes authoritative message or placement rows.

## Implementation Result

- Migration `2026_08_16_110000_create_email_canonical_correlation_shadow.php` is additive and has not
  been run on shared Dev.
- The Admin surface supports exact account scope plus optional minimum/maximum message IDs, durable
  queue/resume/cancel state, metadata-only reports, current-View audited inspection, and immutable
  review decisions.
- Discovery covers normalized Message-ID, stored checksum, current Ticket relationship, and current
  conversation relationship. Full evidence is conservative for malformed/reused IDs, recipients and
  BCC, direction, delivery time, sanitized body, raw source, and attachments. Only hashes, reason
  codes, direction/completeness facts, opaque IDs, and bounded counters are retained.
- Focused verification passes **19 tests / 131 assertions**, including exact cap boundaries, frozen
  scope changes, access revocation, moved-account inspection, overlapping oversized evidence,
  aggregate byte budgets, inspection audit, and rollback guard. Seven routes are registered; Pint,
  PHP syntax, route inspection, and scoped whitespace checks pass. The final independent audit is
  **GO** with no unresolved slice blocker.
- No provider/network/AI operation, authoritative Mail/Ticket/user-state mutation, live migration,
  canonical merge, commit, or push was performed. Human review remains `HR-2026-08-16-004`.

## Out Of Scope

- Canonical-message merge, placement relinking, legacy-field retirement, read-path/API cutover,
  cross-account conversation grouping, provider mutation, search-index deduplication, or deletion.
  Those require `HR-2026-08-16-005` and the separately reviewed cutover migration.
