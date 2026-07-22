# ADR: CloudFactory Durable Manual Sync Progress

Status: Accepted
Date: 2026-07-20
Decision Makers: Svein Tore / Codex

## Context

CloudFactory synchronization can process thousands of Clients, catalogue products, prices, and
licences. Running manual synchronization inside the HTTP request would block the page and make
timeouts likely. Showing a fabricated percentage that jumps directly to completion would not tell an
administrator whether useful work is happening.

Nexum already owns durable CloudFactory sync-run records and queue jobs. The remaining decision is
how the browser observes real progress without coupling provider work to an open modal.

## Decision

Manual synchronization pre-creates a durable CloudFactory sync run in queued state and dispatches the
existing background job with that run identifier. The worker records category-specific processed
counts, known totals, outcomes, source-scan counts, status, and safe errors in the existing sync-run
metadata.

The administrator modal polls a same-origin, Admin-protected JSON endpoint. It displays record totals
when the provider supplies them. When the final licence total cannot be known before per-Client API
calls finish, it shows the real number of licences processed together with completed
Client/provider-source checks. Completed rows settle on an exact processed-of-total result.

Only one manual CloudFactory run may be queued, running, or retrying for an integration at a time.
Closing the modal or browser never cancels the queue job. Scheduled runs continue to use the same
authoritative synchronization services and lock.

## Rationale

Durable database progress survives page navigation, worker restarts, retries, and multiple web
processes. Polling works with the current Laravel and Bootstrap stack and does not require additional
broadcast or WebSocket infrastructure. Real counters remain honest even when CloudFactory cannot
publish a global total before records are read.

## Consequences

- Each processed item updates the sync-run progress record, adding bounded database writes during
  manual and scheduled synchronization.
- The browser can skip intermediate numbers between polls, but every displayed value is real.
- Licence progress uses source checks as the determinate bar until the exact final licence count is
  known.
- Administrators can close the modal safely and resume viewing the active run after returning.
- Queue workers remain required; a stopped worker leaves the run visibly queued rather than blocking
  the HTTP request.

## Alternatives Considered

- Synchronous HTTP execution: rejected because it blocks the page and risks request timeouts.
- Simulated percentages: rejected because they misrepresent provider progress.
- WebSockets or server-sent events: deferred because polling meets the operational need without new
  infrastructure.
- Calling every licence endpoint twice to count first: rejected because it doubles production API
  traffic.
- Persisting every provider item in a staging table: rejected as unnecessary for progress display.

## Follow-Up

Verify one large fictitious-Client synchronization with the real queue worker, confirm counters on a
slow run, and revisit event streaming only if polling creates measurable load.
