# ADR: Private Email Live Invalidation Transport

Status: Superseded
Superseded By: `2026-08-16-email-live-invalidation-user-stream-fanout.md`
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Related ADRs:
- `2026-08-11-email-owned-mail-client-domain.md`
- `2026-08-11-email-mailbox-access-and-rule-authority.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-private-live-invalidation-polling-fallback.md`

## Context

Mail is a Livewire 3 workspace whose provider polling, local actions, per-user state, Taxonomy, and
Ticket links can change while another browser is open. The current application has no broadcasting
configuration, private-channel authorization, Echo client, or supervised WebSocket process. A push
transport must not become the source of truth, expose mailbox content, introduce a second Alpine
runtime, or leave Mail stale when the socket, worker, proxy, or browser connection fails.

The development installation already has Laravel 12, PHP 8.3, Redis on loopback, Apache TLS, and
Livewire's single Alpine runtime. It does not have a general Laravel scheduler, a Reverb service, or
an `email-live` worker. The existing content-security policy also permits broad `ws:` and `wss:`
origins and must be narrowed before production use.

## Decision

Use self-hosted first-party Laravel Reverb with Laravel Echo and `pusher-js`. Reverb is an opaque
Nexum-to-browser invalidation hint transport. Durable, monotonically versioned database projection
streams and visibility-aware Livewire catch-up remain the correctness mechanism. A browser enters an
automatic polling fallback when Reverb is unavailable and catches up on reconnect, visibility
resume, `online`, and `pageshow`.

Use `laravel/reverb:^1.10` and current compatible stable Echo/Pusher JS packages, with exact resolved
versions committed in Composer and npm lock files. Install and configure broadcasting explicitly;
do not run `install:broadcasting`, because domain channel authorization and the auth route belong to
the singular `Email` module rather than `routes/channels.php`.

### Durable Streams And Publication

Create one version stream per Email account and per user. Relevant domain actions append an
allowlisted, bounded change record inside the same database transaction as the projection mutation.
Calling the invalidator outside a transaction fails fast. After commit, a dedicated `email-live`
database-queue publisher coalesces contiguous rows and broadcasts one private event. A scheduled
sweeper recovers rows committed before a publisher could be dispatched. Duplicate publication is
valid because browser catch-up is version-idempotent; broadcast failure never rolls back Mail state.

The only event schema is `email.projection.invalidated.v1`. It may contain schema version, account or
user scope, decimal-string version range, coarse change types, bounded conversation/placement IDs,
and a truncation flag. It never contains subject, address, snippet, body, attachment filename,
Ticket detail, provider state, credential reference, unread count, canonical ID, reason, or error.

### Private Channels And Authorization

Use user-specific private channels:

- `private-email.user.{userId}`
- `private-email.account.{accountId}.user.{userId}`

Account changes are fanned out in bounded chunks only to candidate users derived from current owner,
grant, delegation, and break-glass records. Publication and subscription re-evaluate current
`CONTENT_VIEW` authority. Channel authentication is side-effect-free and does not count as mailbox
content use. The module owns a CSRF-, session-, 2FA-, and throttle-protected auth endpoint and exact
route-permission exemption.

Revocation, account disablement, delegation/break-glass start or expiry, and access changes increment
the affected user stream. A stale socket may briefly receive an opaque hint, but every Livewire query
reauthorizes before returning content or counts, and the browser leaves channels removed by catch-up.

### Browser Correctness And Fallback

The dedicated Mail live client imports Echo/Pusher but never imports or starts Alpine. It treats push
as a refresh hint, calls a bounded Livewire catch-up method, and refreshes only the current authorized
page, counts, selected thread, and affected records. Gaps, pruned history, truncation, or more than the
bounded catch-up window cause one current-page refresh, never a full-mailbox load.

After five seconds of connection/auth failure, the client polls every 15 seconds while visible with
bounded jittered backoff. Hidden/offline tabs stop periodic polling and catch up immediately on
resume. A connected visible tab also performs a 120-second safety check. `EMAIL_LIVE_MODE` supports
`reverb`, `poll`, and emergency `off`; `off` is not an accepted finished production state.

The list remains stable while scrolled: unauthorized/deleted rows disappear immediately, the
selected conversation may refresh, counts and a permission-filtered new-mail indicator update, and
new rows do not move under the pointer until the user returns to the top.

### Operations And Security

Reverb binds only to loopback. Apache terminates TLS and proxies the explicit `/app` WebSocket and
`/apps` API paths with exact allowed origins. Production uses WSS. The CSP permits only the approved
socket origin, not broad `ws:`/`wss:`. Reverb application credentials are a distinct secret domain;
only the public app key/host/port/scheme enter the Vite bundle. Environment files containing the
secret must be `0640` or tighter with the correct runtime group.

Run Reverb and the `email-live` queue worker as supervised systemd services. Install one shared
Laravel scheduler runner for Mail reconciliation, access-boundary expiry, outbox recovery, and
retention. Health checks include service restarts, loopback and public TLS handshakes, authenticated
private subscription, scheduler/worker heartbeat, oldest pending change, retries, failed jobs, and a
forced polling-fallback browser check.

## Rationale

- First-party self-hosting avoids a new external mailbox-activity processor.
- Durable versions make transport loss, duplication, reordering, and reconnect deterministic.
- Private opaque events minimize content and authorization exposure.
- Automatic polling preserves correctness without making WebSockets an availability dependency.
- A dedicated JS module preserves Livewire's single Alpine runtime.

## Alternatives Considered

- **Hosted Pusher or Ably.** Rejected because even opaque channel activity creates a new external
  metadata processor and operating dependency.
- **Custom WebSocket or SSE server.** Rejected because it duplicates Laravel authentication,
  reconnect, backpressure, and process supervision.
- **Polling only.** Retained as correctness fallback, but rejected as the finished primary
  experience because it adds avoidable latency and load.
- **Broadcast domain events containing changed models.** Rejected because serialization can expose
  content, stale authorization, provider evidence, or credentials.

## Consequences

Positive:

- Authorized Mail views update promptly without manual refresh and recover deterministically after
  transport loss.
- Revocation and reconnect behavior are explicit and testable.
- Push payloads remain small, opaque, private, and idempotent.

Negative:

- Dev and production require packages, built assets, Apache proxying, two supervised processes, a
  scheduler, secret handling, and transport-specific health checks.
- Projection-changing actions must call the explicit invalidator inside their transactions.
- List stability and targeted refresh add Livewire/client state that must be browser-tested.

## Follow-Up

- Implement the related Feature Slice only after canonical cutover, provider credentials, and
  provider-originated reconciliation are stable.
- Presence and shared-draft coordination use a separate ephemeral namespace in the next slice; they
  do not enter this durable projection outbox.
- Keep `HR-2026-08-16-008` Pending until real Reverb, outage/fallback, revocation, CSP, scheduler,
  worker, mobile, and browser behavior is reviewed by a named human.
