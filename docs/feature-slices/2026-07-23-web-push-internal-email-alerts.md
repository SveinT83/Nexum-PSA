# Feature Slice 2: Inbound Email Web Push Delivery

Status: Draft
Date: 2026-07-23
Parent: `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`
ADR: `docs/adr/2026-07-23-notification-owned-web-push-channel.md`
Prerequisite: `docs/feature-slices/2026-07-24-web-push-channel-device-foundation.md`
Owner: Svein / Codex

## Goal

Connect the proven Web Push channel to one idempotent post-routing inbound Email event and deliver
secure alerts to the correct Ticket owner and explicit inbox/triage subscribers.

## User-Visible Behavior

Notification Preferences gains two independent internal rows:

- Customer reply on my Tickets;
- New inbound Email for inbox/triage coverage.

Both rows default to database/in-app enabled and email, Nextcloud Talk, SMS, and Web Push disabled.
Each available extra channel can be enabled independently. Enabling Web Push sends the supported
event to every active registered device owned by the user; there is no per-device event matrix.

When a non-spam inbound Email finishes classification and routing:

- an assigned, authorized Ticket owner is eligible through Customer reply on my Tickets without
  enabling all inbound Email;
- active, authorized users who enable New inbound Email are independently eligible for non-archived
  inbox/triage coverage;
- the same user receives one canonical notification even when eligible through both paths;
- every active device for an enabled recipient receives the push;
- a linked alert opens the Ticket and an unlinked alert opens the Email message;
- opening the alert marks only its matching canonical notification read after authentication and
  authorization;
- Ticket, TicketMessage, and Email operational unread/workflow state remains unchanged;
- the default lock-screen payload is generic, while a separate default-off preview may add sender
  display name and a truncated subject.

## Scope

- Require Feature Slice 1 channel readiness before enabling inbound delivery.
- Add `ticket_customer_reply_received` and `inbound_email_received` notification types.
- Add type-specific defaults: database/in-app on; email, Nextcloud Talk, SMS, and Web Push off.
- Keep all existing notification-type defaults unchanged.
- Add the default-off, per-user/per-type limited-preview preference behavior.
- Add Notification-owned scope persistence supporting `all`, selected Email accounts, and selected
  Ticket queues. Feature Slice 2 exposes only the global/all inbox choice.
- Emit one domain event only after Email classification and routing complete.
- Suppress spam and hard-stop archived messages.
- Include linked/new Tickets and non-archived unlinked inbox/triage messages.
- Resolve owner and inbox/triage recipient paths independently, require active users and existing
  source authorization, then union recipients.
- Store one canonical database notification per EmailMessage and user using a stable unique delivery
  identity.
- Store non-sensitive `ticket_id`, `ticket_message_id`, and/or `email_message_id` source references.
- Queue Web Push only when the user's event preference is enabled and the global channel is ready.
- Fan one eligible user notification out to every active subscription owned by that user.
- Do not stop delivery after the first successful device and do not create duplicate canonical
  notifications per device.
- Use a generic default payload with product identity, optional Ticket key, safe action label,
  same-origin target, notification ID, source IDs, and stable tag.
- When preview is enabled, add only sender display name and truncated subject.
- Never include body, attachment names, client/customer identity, full sender address, or private
  operational context.
- Mark the matching canonical notification read on an authenticated and authorized push/bell open.
- Remove 404/410 expired subscriptions and audit cleanup; use bounded retries for 429/5xx/temporary
  transport failures.
- Never roll back Email/Ticket persistence because push delivery fails.
- Record sanitized delivery outcomes sufficient for operations without endpoints or key material.
- Add focused Email, Ticket, Notification, queue, and permission tests.

## Out Of Scope

- Direct Ticket/Email source-page read synchronization when no notification link was used; this is
  Feature Slice 3.
- User-facing selectors for Email-account or Ticket-queue filters.
- Per-device event preferences, pause state, or device-specific scope.
- Owner alerts for portal/external customer replies.
- Quiet-hour persistence or UI.
- Customer Portal Web Push.
- Rich previews beyond sender display name and truncated subject.
- Offline data, silent push, native applications, or provider delivery guarantees.

## Data Touched

- Two new NotificationSetting type definitions and type-specific channel defaults.
- Additive Notification-owned Email-account/Ticket-queue scope persistence.
- Canonical Laravel database notifications with exact non-sensitive source references.
- Minimum unique delivery identity for EmailMessage/user idempotency.
- Sanitized Web Push delivery outcome/audit data.
- No changes to Email bodies, TicketMessage bodies, existing permissions, or existing notification
  type defaults.

## Permissions

- Recipient resolution requires existing access to the exact Ticket or Email message.
- Event preferences and account/queue scopes never grant domain access.
- A notification open can mark only the authenticated user's own canonical notification.
- A click never bypasses login, work-context scope, route middleware, policy, or record visibility.
- Unauthorized or signed-out attempts do not mark a notification before successful target
  authorization.

## Tests

Automated:

- both new types default database/in-app on and all extra channels off;
- existing notification-type defaults remain unchanged;
- per-user/per-type/per-channel preference independence;
- owner delivery works with inbox/triage preference disabled;
- owner Web Push is suppressed when its Web Push preference is disabled;
- unassigned/triage delivery reaches only explicit active subscribers with Email access;
- no-scope means all authorized inbound Email while stored account/queue scopes restrict only the
  inbox/triage recipient path;
- spam/hard-archived suppression;
- owner/subscriber recipient union de-duplication;
- one canonical notification per EmailMessage/user across retries;
- fan-out to every active owned device without one canonical row per device;
- revoked/expired devices do not receive delivery;
- generic and opted-in preview payload privacy;
- invalid/cross-origin target fallback;
- authenticated notification open marks only the matching user's notification read;
- notification open leaves Ticket/TicketMessage/Email operational state unchanged;
- 404/410 cleanup, bounded temporary retries, and channel failure isolation;
- disabled/global-not-ready Web Push preserves canonical database/in-app delivery.

Manual:

- real routed linked and unlinked Email through active scheduler and queue;
- owner-only, triage-only, and overlapping-recipient cases;
- two active devices receive one user event;
- revoked device stops while remaining device continues;
- foreground/background and signed-out click behavior;
- generic versus opted-in preview;
- provider failure simulation without Email/Ticket rollback.

## Documentation

- Notification event/channel Knowledge documentation.
- Email inbox/system post-routing alert policy.
- Ticket customer-reply notification behavior.
- Queue/retry/expired-subscription operational runbook.
- `docs/TODO.md` status.
- Slice-specific `docs/human-review.md` entry before handoff.

## Done Criteria

- Feature Slice 1 is Done and its open defects are resolved or explicitly deferred.
- Both new types create canonical in-app notifications without automatic email/Talk/SMS/push.
- An opted-in owner receives linked customer replies without subscribing to all inbound Email.
- Explicit inbox/triage subscribers receive only authorized, non-archived messages.
- One user event reaches every active owned device and no revoked device.
- Each EmailMessage/user produces at most one canonical notification.
- Payloads and logs contain no prohibited private content or subscription secrets.
- Push failures do not duplicate or roll back Email/Ticket state.
- Focused Dev tests and end-to-end Email-to-push checks pass.
- Human review remains Pending until a named reviewer completes the slice checks.
