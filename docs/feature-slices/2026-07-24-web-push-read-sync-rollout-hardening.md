# Feature Slice 3: Web Push Read Sync And Rollout Hardening

Status: Done
Date: 2026-07-24
Parent: `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`
ADR: `docs/adr/2026-07-23-notification-owned-web-push-channel.md`
Prerequisite: `docs/feature-slices/2026-07-23-web-push-internal-email-alerts.md`
Owner: Svein / Codex

## Implementation Progress

Implemented on Dev on 2026-08-11. Notification now owns source read synchronization for inbound
Email notifications. The shared notification-open route redirects push and bell opens through the
canonical database notification, while Ticket and Email source views mark only the current user's
matching source notifications read after normal route authentication, permission checks, and record
resolution succeed.

The Ticket source path uses TicketMessage IDs rendered on the page plus source EmailMessage IDs
stored in those TicketMessage metadata records, so a notification created while an Email was still
in inbox also follows the Email after it is later linked to a Ticket. The Email source path uses
only the exact unlinked EmailMessage being shown. Ticket unread flags, TicketMessage `read_at`,
Email state, and Email workflow state remain unchanged. The shared service worker accepts a
validated `nexum-close-notifications` message to close still-visible notifications by safe Nexum
tags.

Human browser/device and end-to-end Email-to-push review is tracked separately in
`HR-2026-08-11-002`.

## Goal

Complete source-specific notification read synchronization and prove that the inbound Web Push
workflow is safe to enable in a production-like environment.

## User-Visible Behavior

If a technician ignores a customer-reply push and later opens the Ticket directly, Nexum marks that
technician's canonical notifications read for the customer-reply TicketMessage records rendered on
the page. Directly opening an unlinked Email message does the same for its exact EmailMessage source.

This synchronization:

- never clears `tickets.is_unread` or `ticket_messages.read_at`;
- never invokes the Ticket Mark as read workflow;
- never changes Email processing/workflow state;
- never changes another user's or an unrelated source notification;
- is idempotent on repeated page views.

When supported and the PWA is active, the page asks the shared service worker to close a still-visible
browser notification with the matching stable tag. Browser closure is best effort; the Laravel
database notification remains authoritative.

## Scope

- Add one Notification-owned action that marks current-user database notifications read by exact,
  allowlisted source type and source ID.
- Call it only after normal Ticket/Email authentication, work-context, policy, and record resolution
  succeeds.
- Pass only TicketMessage IDs actually rendered on the Ticket page and the EmailMessage IDs stored
  in those rendered messages' metadata.
- Sync the exact EmailMessage ID on an authorized Email detail view.
- Keep notification-bell and push-open behavior on the same action/contract.
- Post stable tags to the existing service worker for best-effort visible-notification closure.
- Preserve all existing Ticket and Email operational read-state actions.
- Add idempotency, cross-user, authorization, merged/deleted Ticket, and stale-notification tests.
- Verify the service-worker update does not regress install, navigation, fetch, or offline fallback.
- Verify external scheduler execution separately from `schedule:list`.
- Verify queue workers, failed-job visibility, VAPID readiness, HTTPS, and sanitized delivery logging.
- Run the supported browser/device matrix and an end-to-end IMAP-to-push latency check.
- Add/update the large-change human-review entry and deployment checklist.

## Out Of Scope

- Per-account/per-queue filter UI.
- Quiet-hour persistence or UI.
- Per-device event matrices or pause state.
- Portal/external customer-reply event sources.
- Customer Portal Web Push.
- Native apps, offline writes, or silent background work.

## Data Touched

- Existing Laravel database notification `read_at` only for exact current-user matches.
- Existing non-sensitive source IDs introduced in Feature Slice 2.
- Existing `public/sw.js` message/click handling.
- No new Ticket, TicketMessage, EmailMessage, preference, or subscription schema.

## Permissions

- A user can synchronize only their own notifications.
- Source-page authorization must succeed before synchronization.
- Source IDs are matched against an allowlist of supported notification types and model keys.
- A missing, merged, deleted, stale, or unauthorized source cannot mark unrelated notifications.
- Browser notification closure never grants record access or mutates server state.

## Tests

Automated:

- push/bell open uses the exact current-user notification;
- direct Ticket view marks only notifications for rendered TicketMessage IDs or their recorded
  source EmailMessage IDs;
- direct Email detail marks only its exact EmailMessage notification;
- multiple rendered replies mark their matching current-user notifications only;
- unrelated messages on the same Ticket remain unchanged;
- another user's notifications remain unchanged;
- unauthorized, signed-out, deleted, merged, and stale source cases do not over-mark;
- repeated opens/views are idempotent;
- Ticket, TicketMessage, and Email operational state never changes;
- service-worker tag closure message handling and invalid-tag rejection;
- existing service-worker fetch/offline regression coverage.

Manual/operational:

- ignored push followed by direct Ticket view;
- direct Email view and signed-out push click/login return;
- foreground/background click and best-effort browser-notification closure;
- Chrome/Edge, Firefox, Safari/macOS, and installed iOS/iPadOS PWA where available;
- two active devices and one revoked device;
- active external `schedule:run` runner, queue worker, failed-job visibility, HTTPS, and VAPID
  readiness;
- production-like linked and unlinked Email-to-push latency;
- service-worker update and offline fallback;
- sanitized logs and audit records.

## Documentation

- Notification read-state and device operations Knowledge documentation.
- Ticket and Email source-view read-sync behavior.
- Scheduler, queue, VAPID, service-worker, and troubleshooting runbook.
- Deployment/rollback checklist.
- `docs/TODO.md` status.
- `docs/human-review.md` review entry/checklist.
- Public nexumpsa.eu handoff only after implementation and named human verification.

## Done Criteria

- Feature Slices 1 and 2 are Done with no unresolved release-blocking defects.
- Direct source views synchronize only exact current-user canonical notifications.
- Operational Ticket/Email unread state remains independent.
- Automated service-worker regression checks pass; supported browser/device
  behavior remains a named human review check.
- External scheduler and queue execution are proven, not inferred from registered schedules.
- End-to-end linked and unlinked Email push checks remain required in a
  production-like human review environment.
- Deploy, rollback, VAPID, worker restart, and cache/service-worker steps are documented.
- A named human reviewer completes the required review before release is called ready.
