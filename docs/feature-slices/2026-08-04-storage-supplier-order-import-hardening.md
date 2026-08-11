# Feature Slice: Supplier Order Import Hardening And Release Readiness

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Harden the complete supplier-order automation for production operation with bounded retries,
retention, circuit breakers, safe supported attachments, notifications, additional profiles,
cross-module regression, runbooks, and human review.

## User-Visible Behavior

Admins can monitor profile and queue health, backlog age, retry state, circuit breakers, provider
denials, and the configured automation actor. Successful imports remain quiet by default; exceptions
are immediate and an optional daily digest summarizes outcomes.

Supported safe attachments can participate in the same canonical/evidence flow when body content is
insufficient. Unsupported or unsafe attachments remain visible exceptions and are never sent or
executed silently.

## Scope

- Verify/fix durable Email attachment metadata, checksums, safe paths, size/type limits, and
  permissioned access before any extractor uses attachments.
- Add isolated, bounded extraction for explicitly supported safe formats such as PDF after malware,
  timeout, memory, and parser-failure behavior is designed and tested.
- Keep raw attachments in Email; Storage stores only approved snapshot descriptors, fingerprints,
  normalized evidence, and authorized links.
- Add bounded exponential retry/backoff, permanent-versus-transient classification, dead-letter
  visibility, stale-backlog signals, and manual retry.
- Implement supplier-isolated circuit breakers, profile health, provider outage state, and recovery.
- Add immediate exception notifications and optional daily digest; default ordinary success is
  silent.
- Add retention/cleanup for source snapshots, attempts, fixtures, and optional local troubleshooting
  payloads without deleting required PO/import/audit history.
- Add queue/scheduler health checks and deployment verification.
- Add more supplier profiles only as declarative, fixture-tested packages without customer data.
- Complete accessibility, responsive UI, sorting/filtering, large-list performance, and operational
  reason wording.
- Run broad cross-module regression, security tests, controlled provider smoke, migration/rollback,
  Knowledge/BookStack sync, and human review.
- Record but do not expose the deferred generic Inbox **Analyze with AI** workflow.

## Out Of Scope

- Supplier checkout/order transmission.
- Carrier polling, invoice matching, accounts payable, or landed-cost allocation.
- Arbitrary attachment formats or macros.
- Generic Inbox AI button implementation.
- Automatic receiving or any email-triggered stock mutation.

## Data Touched

- Email attachment metadata/retention support where current persistence is incomplete.
- Storage import/profile health, retry, circuit-breaker, retention, source descriptors, and
  notification state.
- Notification templates/delivery audit for exceptions/digest.
- Integration telemetry/governance and queue/scheduler operations.
- Knowledge sources, permission catalogs, TODO, deployment notes, and human-review register.

## Permissions

- Existing Email attachment permission remains mandatory for source download.
- `storage.purchase_import_view` for health/import/source descriptors.
- `storage.purchase_import_execute` for bounded manual retry.
- `storage.purchase_import_policy_manage` for retention, notification, and circuit settings.
- Notification and Integration Admin permissions remain separate.
- No hard gate, audit, secret filter, or no-receipt rule is configurable off.

## Tests

- Supported/unsupported MIME, disguised file, malware outcome, oversize, timeout, parser crash,
  path traversal, duplicate filename, inline CID, checksum, and attachment permission.
- No attachment parser or AI context fetches remote URLs or executes active content.
- Transient retry/backoff, permanent failure, dead-letter visibility, manual retry, idempotency, and
  stale backlog.
- Database singleton/current guards, append-only attempt update/delete guards, restricted parent
  deletion, and separate start/completion attempt events.
- Concurrent profile activation/failure handling and delayed dispatch/job callbacks that must not
  overwrite a newer lifecycle or claim state.
- Circuit breaker trips/recovers per profile while other suppliers continue.
- Provider outage/denial, disabled actor, queue unavailable, scheduler missing, and digest behavior.
- Retention preserves required source fingerprint, imported PO provenance, immutable audit, and
  legal history while deleting only eligible payloads. Attempt metadata is minimized before insert;
  attempt start/completion rows are append-only and are never updated or deleted by retention.
- Notification permissions, quiet success, immediate exception, digest deduplication, and delivery
  failure.
- Multi-profile fixture regression, responsive/accessibility/browser checks, performance, migration,
  safe rollback preflight before append history exists, explicit refusal after it exists, and broad
  Laravel suite.
- Explicit end-to-end negative assertion that no order email posts a receipt or changes stock.

## Documentation

- Final Storage, Email, Signal, Integration, Notification, Documentation, and operations Knowledge.
- Profile-authoring and fixture-protection guide for AI and non-AI installations.
- Deployment, queue/scheduler, rollback, retention, provider outage, circuit-breaker, and incident
  runbook.
- Permission/API documentation for only implemented surfaces.
- BookStack sync, TODO status, website handoff if publicly appropriate, and stable human-review
  checklist.

## Done Criteria

- [x] Attachment metadata and approved snapshot descriptors are safe, bounded, permissioned, and optional; unsupported content is not executed or sent silently.
- [x] Bounded retry, circuit breaker, retention, operational health, alerts, and optional digest behavior are implemented.
- [x] AI/provider/worker failures cannot cause raw fallback, duplicate PO, or hidden backlog.
- [x] The declarative profile library and fixture engine support suppliers without source-code adapters; Itegra is seeded as the first synthetic protected profile.
- [x] Successful flow is quiet and exceptions are actionable.
- [x] The Dev Email runtime is forward-only by persisted UID namespace/baseline and does not treat historical unread messages as supplier-order work.
- [x] Complete final combined and broad automated verification after Dev deployment.
- [ ] Apply and verify migrations, seeders, cache clearing, queue worker, scheduler, rollback preflight, and Knowledge/BookStack sync.
- [ ] Add protected real Itegra fixtures and complete shadow rollout before active mode.
- [x] The stable human review remains Pending until a named reviewer explicitly completes it.
- [x] Generic Inbox AI bootstrap remains deferred and no unfinished control is visible.
