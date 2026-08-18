# Feature Slice: Email Canonical Message And Placement Cutover

Status: Done / Human Review Pending
Date: 2026-08-16
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADRs:
- `docs/adr/2026-08-11-email-canonical-message-mailbox-placement.md`
- `docs/adr/2026-08-11-email-owned-mail-client-domain.md`
Predecessor: `docs/feature-slices/2026-08-16-email-mail-canonical-message-shadow-correlation.md`
Owner: Svein / Codex
Human review: `HR-2026-08-16-005`

## Goal

Introduce the reversible canonical-content expansion and the placement pointer required by the
accepted Email architecture. Existing `email_messages` rows remain immutable source occurrences and
keep every account, Ticket, rule, unread, provider-operation, route, and external API identity. The
cutover changes only where an authorized content read may obtain equivalent common content.

## User-Visible Behavior

- Mail continues to show and mutate the exact authorized provider placement and source-message ID.
- An account may remain in `legacy`, enter comparison-only `verify`, or use `canonical` content reads.
- `verify` always returns legacy source content and reports parity without changing a mailbox.
- `canonical` projects common content through the placement's canonical pointer only while the exact
  current source, mapping, canonical projection, and placement pointer agree. Any drift fails safely
  back to the authorized source occurrence until an audited dissolution is applied.
- Admin maintenance can preview, apply, and roll back bounded self-map backfills, reviewed duplicate
  components, drift repairs, and account-mode changes. No provider action is part of this workflow.
- Accounts above the 500-placement direct-preview cap use a durable whole-account parity
  attestation. One operator request verifies at most 100 placements; a later currently authorized
  operator may continue the frozen cursor. Only a completed, current fingerprint may be bound into
  the separately previewed mode change.
- A currently authorized operator may inspect, apply, or roll back an accessible durable run even
  when its original requester has since been disabled. `requested_by` remains audit evidence, not
  operational ownership.

## Scope

- Add canonical content projections, canonical attachment projections, one unique source-to-canonical
  mapping per `email_messages` row, and a nullable canonical pointer on mailbox placements.
- Add durable preview/apply/rollback runs and per-source items with opaque fingerprints, old/new IDs,
  actor, status, and timestamps. Audit rows contain no subject, address, header, filename, body, raw
  bytes, or attachment bytes.
- Add durable paginated account-parity attestations/items. They freeze the complete active-placement
  count, maximum placement ID, aggregate database scope, rolling per-placement evidence, verified
  byte count, requester/completer, and final fingerprint. A page hashes at most 100 placements and
  materializes one source/projection at a time; the complete attestation remains resumable for an
  arbitrarily large account without one unbounded application query.
- Backfill existing sources as one canonical self-map each before any reviewed consolidation.
- Dual-write new source occurrences to a self-map when the canonical schema is available. A later
  attachment/body completion refreshes only a one-source projection; it never silently rewrites a
  shared canonical component.
- Permit a multi-source canonical projection only from a completed shadow run where every selected
  edge is `strong_candidate`, immutably `confirmed_candidate`, and has an exact same-evidence audited
  inspection by its reviewer.
- Recompute `canonical-cutover-v1` evidence at preview and apply. Eligibility requires exact equality
  for every canonical field and the bytes of the real local raw and attachment files. Stored hashes,
  Message-ID, subject, or shadow classification alone are never sufficient.
- Bound evidence while materializing it: individual body/structured fields are capped, JSON depth,
  node, and entry counts stop pathological historical headers before recursive normalization can
  exhaust memory, actual files are capped per message, and a run stops above 256 MiB total evidence.
- Treat each selected connected component as one locked complete clique. Every pair must have eligible
  evidence, every currently mapped source in an affected canonical component must be included, and a
  reviewed `keep_separate` pair from any retained run blocks consolidation.
- Preserve root and source records. Applying changes only source mappings, the nullable placement
  pointer, canonical projection state, and cutover audit. Superseded projections are retained for
  rollback.
- Audit pointer/evidence drift. A drifted multi-source component dissolves as one transaction into
  independent source projections; partial splitting is forbidden. Rollback restores the complete
  prior component only if no later mapping or pointer change has occurred.
- Add a content resolver that keeps source identity but can overlay equivalent canonical common
  fields for authorized placement reads. Raw and attachment decisions remain bound to that source's
  active placement and ordinary current mailbox access.
- Treat an absent or partially deployed canonical schema as `legacy` without querying missing
  tables. Attachment download verifies full canonical parity but always serves the exact route-bound
  source part, so duplicate filenames/metadata can never select another part.
- Protect sources retained by a canonical projection, mapping, durable cutover item, or non-legacy
  account mode from the existing retention purge with a stable reason. Canonical-aware physical
  deletion remains a separately reviewed future lifecycle change.
- Require a strict completed parity attestation for `canonical` mode when an account is too large for
  the direct bounded pass. The attestation rehashes real files page by page, is valid for 15 minutes,
  is rechecked against the complete current database scope and durable item set at preview/apply,
  and becomes unusable after placement/mapping/projection drift or expiry. The read resolver still
  performs per-source live drift checks and falls back independently.

## Conservative Evidence Contract

`canonical-cutover-v1` is stricter than shadow correlation:

- normalized Message-ID, exact subject, sender name/address, recipients, CC, complete stored headers,
  In-Reply-To, References, direction, received instant, size, oversize state, normalized text,
  sanitized HTML, and stored content checksum must agree;
- JSON objects are key-normalized but list order remains significant;
- structured fields stop at depth 24, 10,000 visited nodes, or 5,000 entries and become ineligible
  before canonical serialization when any structural bound is exceeded;
- raw paths must resolve to ordinary non-symlink files below private `email/raw/`, within the bounded
  local-file limit, and the actual SHA-256 bytes must agree;
- the declared attachment count must equal the rows; every row must use the private local disk,
  resolve below `email/attachments/` without traversal or symlinks, match its declared size and SHA-1,
  and have equal metadata plus actual SHA-256 bytes;
- missing, unreadable, oversized, inconsistent, or mutable evidence is ineligible for consolidation;
- a self-map may retain an incomplete projection because it cannot widen content access, but it can
  never join another source until all exact evidence is complete.

## Out Of Scope

- Provider reads or writes, IMAP flag/folder/UID changes, historical import, rule replay, AI, send,
  Ticket mutation, unread/opened-state mutation, workflow-state consolidation, content deletion, and
  physical attachment/raw deduplication.
- Cross-account conversation, Taxonomy, Ticket, classification, search-result, unread, or provider
  operation merging. Those remain source/account scoped.
- Destructive removal of legacy content or placement columns. The word `retirement` in the program
  queue means a reversible logical read retirement in this slice. Physical schema removal requires a
  later forward migration after canonical parity, rollback-window expiry, and named human review.
- Break-glass or system-actor cutover authority. Break-glass remains limited to its separately audited
  content operations and never qualifies an actor to correlate or remap content.

## Data Touched

- New `email_canonical_messages`, `email_canonical_message_attachments`, and
  `email_canonical_message_sources` tables.
- New `email_canonical_cutover_runs`, `email_canonical_cutover_items`, and
  `email_canonical_read_modes` tables.
- New `email_canonical_parity_attestations` and
  `email_canonical_parity_attestation_items` tables.
- Nullable `email_mailbox_placements.canonical_email_message_id`.
- Existing Email content, account, placement, attachment, correlation, and inspection rows are read
  under bounds and locks; authoritative source rows are not rewritten.
- Private local raw/attachment files are read and hashed within hard bounds; no file is written,
  moved, copied, deduplicated, or deleted.

## Permissions

- Preview, apply, rollback, audit, and mode changes require an active non-system actor with both
  `email.canonical_cutover_manage` and `email.mailbox_sync_manage`.
- The actor must independently have ordinary current `View` access to every account in the exact
  operation. Owner, active delegation, or shared/system account grant may qualify. Break-glass never
  qualifies.
- A content read continues to authorize the source placement/account through the existing Mail
  policy. A canonical pointer never grants access or reveals another account occurrence.

## Tests

- Migration expansion and rollback guards; one-to-one self maps; nullable placement pointers; no
  destructive backfill in the migration.
- Strict full-field, raw-file, attachment-file, incomplete-evidence, changed-evidence, and symlink/path
  failures.
- Completed/failed shadow runs; strong/weak/ambiguous/oversized classes; confirmed/unreviewed/separate
  decisions; exact inspections; complete and incomplete cliques; retained exclusion decisions.
- Preview idempotency, apply-time reauthorization, frozen fingerprints, account/component locking,
  concurrent/idempotent apply, pointer parity, rollback ordering, and drift-component dissolution.
- More than 500 active placements complete through durable 100-row pages, bind the exact attestation
  fingerprint into preview/apply, survive requester offboarding under replacement authority, reject
  changed scope and 15-minute expiry, and then enter canonical mode without a permanent cap.
- `legacy`, `verify`, and `canonical` resolution with source identity preserved and safe source fallback.
- No Email source, Ticket, conversation, classification, unread/opened, rule, provider-operation,
  provider state, or private-file mutation.

Implemented verification on authoritative Dev:

- focused `EmailCanonicalPlacementCutoverTest`: **18 tests / 702 assertions**;
- adjacent retention, shadow correlation, historical maintenance, workspace/access, attachment, and
  per-user unread regression: **91 tests / 843 assertions**;
- the shadow-correlation plus historical-maintenance subset remains **34 / 275**;
- route registration, PHP syntax, targeted formatting/diff checks, and group-writable Blade cache
  pass. No live migration, cutover run, provider operation, or private-file mutation was executed.

## Documentation

- Update this Feature Slice, the Mail completion index, Email README, Email Knowledge, TODO, and
  `docs/human-review.md` when implementation and automated verification are complete.
- Human review `HR-2026-08-16-005` must retain exact migration, bounded preview, browser/API/raw/
  attachment parity, rollback, worker/cache, and no-provider-mutation checks as Pending.

## Deploy And Rollback

- Deploy additive schema and code with every account effectively in `legacy`. The migration performs
  no correlation, provider operation, content deletion, mode switch, or source-row rewrite.
- Run a bounded self-map preview and apply, then `verify`, compare exact parity, and only then preview
  an account-scoped `canonical` mode change. For an account above the direct cap, first complete the
  resumable whole-account parity pages and bind that current fingerprint into the mode preview.
  Every step remains separately auditable.
- Stop new cutover applies and return affected accounts to `legacy` before rollback. Roll back applied
  mapping runs newest-first. A rollback fails closed if a later run changed a mapping or pointer.
- Migration down fails closed while any canonical projection, attachment projection, mapping,
  placement pointer, read-mode row, preview/run item, parity attestation, or parity item remains.
  Durable evidence must be preserved or carried forward; physical legacy-field removal is not a
  rollback mechanism.

## Done Criteria

- [x] Additive migration, models, strict evidence, bounded workflow, source-preserving resolver, and
  dual-write path are implemented without provider or authoritative-workflow mutation.
- [x] Self-map backfill, strong reviewed clique consolidation, drift dissolution, mode changes, and
  newest-first rollback are deterministic, idempotent, account-authorized, and tested.
- [x] Mail UI, list/show API, raw source, and attachment reads share the same account mode and
  source-preserving content-resolution boundary before any account may enter `canonical`.
- [x] Focused and affected Email regressions pass on authoritative Dev; migration and live provider
  operations remain unexecuted until the documented human gate.
- [x] README, Knowledge, TODO, index, and `HR-2026-08-16-005` are updated; human review stays Pending.
- [x] A final independent read-only audit accepts the completed arbitrary-account attestation and
  durable schema-rollback guard before this slice changes from In Progress.
