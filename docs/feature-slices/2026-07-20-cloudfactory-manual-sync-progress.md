# Feature Slice: CloudFactory Queued Manual Sync Progress

Status: Done
Date: 2026-07-20
Parent: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`
Owner: Codex

## Goal

Make every manual CloudFactory synchronization an observable background queue job with honest,
category-specific live progress.

## User-Visible Behavior

Selecting Everything, Clients, Catalogue and prices, or Licences opens a Bootstrap modal immediately.
The modal shows queued, running, retrying, completed, or failed state and one progress row for every
selected category. Each row shows processed item counts, known totals, created, updated, and conflict
counts. Licence synchronization also shows completed Client/provider checks while the final licence
total is still being discovered.

The synchronization continues when the modal or page is closed. Returning to the page while a manual
run is active offers a View progress action instead of starting an overlapping manual run.

## Scope

Durable progress metadata on CloudFactory sync runs, pre-created manual run records, queued job
correlation, category and source counters, same-origin status polling, duplicate manual-run
protection, retry and failure states, the progress modal, recent-run status display, and
administrator documentation.

## Out Of Scope

WebSocket infrastructure, cancelling an in-flight provider synchronization, parallel category
execution, provider writes, and changing the scheduled synchronization intervals.

## Data Touched

Existing `cloudfactory_sync_runs.metadata` and counters, Integration queue jobs, Integration routes,
the CloudFactory administrator controller and view, sync services, tests, Knowledge documentation,
and the human-review register. No database migration is required.

## Permissions

The existing Admin integration middleware protects both starting a manual run and reading its
progress. A progress response is limited to the active CloudFactory integration and never exposes
tokens or unsanitized provider payloads.

## Tests

Feature tests cover queued dispatch, persistent category metadata, status authorization and
sanitization, duplicate-run reuse, per-item and per-source progress, completion, failure, and Blade
rendering. Dev verification also covers the Integration suite, queue execution, and authenticated
HTTP rendering.

## Dev Runtime Validation

A real queued Everything run completed on Dev with 26 of 26 Clients, 10,898 of 10,898 catalogue
products, and 0 of 0 licences. The categories advanced independently in durable metadata, no
conflicts were recorded, and the queue reported no failed jobs. A job serialized before manual-run
correlation was introduced also completed after explicit legacy defaults were added and tested.

The Dev worker had been switched off. After it was enabled, the queued synchronization completed
normally. Production already uses the managed worker system; automatic synchronization depends on
the relevant workers and scheduler remaining enabled in each environment.

## Documentation

Update the CloudFactory Knowledge article, production-validation Feature Slice, relevant ADR, TODO
status, and `docs/human-review.md`.

## Done Criteria

- [x] Manual controls return immediately after creating a queued sync run.
- [x] The worker updates durable progress for each selected category.
- [x] The modal polls and renders honest counters without simulated percentage jumps.
- [x] Closing the modal does not stop the queue job.
- [x] Overlapping manual runs are not started accidentally.
- [x] Completion and failure are visible and tested.
- [x] Knowledge and human-review documentation are updated.
