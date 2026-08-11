# ADR: Notification-Owned Standards-Based Web Push Channel

Status: Accepted
Date: 2026-07-23
Decision Makers: Svein / Codex
Related: `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`

## Context

Nexum has one PWA service worker and an existing Laravel Notification domain, but no browser push
subscriptions or Web Push channel. The first approved use case is an internal alert after an inbound
Email message has completed routing. The choice affects package ownership, database structure,
service-worker behavior, deployment secrets, browser support, privacy, and later notification types.

A custom channel could be built directly on a lower-level Web Push library, a hosted provider such
as Firebase/OneSignal could be introduced, or Nexum could use the maintained Laravel Notification
channel package that already supports Laravel 12, VAPID, subscription persistence, and expired
subscription cleanup.

## Decision

Use `laravel-notification-channels/webpush` with a compatible pinned Composer constraint as the Web
Push transport for internal Laravel notifications.

Notification owns channel policy, per-user preferences, browser subscription endpoints, and the
notification payload contract. Email owns the post-routing inbound-email event. Ticket supplies the
linked owner, authorized target URL, and existing record access policy. System/PWA owns the single
shared service-worker browser surface. UserManagement's internal User remains the notifiable and
subscription owner.

Use VAPID credentials stored as deployment secrets. Keep one existing `public/sw.js` and extend it
with standards-based `push` and `notificationclick` handlers. Do not introduce a second worker,
Firebase application identity, or a hosted notification control plane.

Web Push is explicit per-device and per-event opt-in. The private lock-screen payload is minimal,
encrypted by the Web Push protocol, and always produces a visible notification. A click uses a
same-origin URL and normal Nexum authentication, authorization, work-context, and record visibility.

Notification owns the complete device inventory and revocation boundary. Users can list and revoke
their own devices. Administrators require `notification.manage_channels` to view another user's
privacy-safe device summaries or revoke a subscription. Neither surface exposes endpoint URLs,
public keys, authentication tokens, or VAPID secrets.

Notification records secret-free device lifecycle audit events. UserManagement calls the
Notification-owned cleanup action whenever an internal User changes to Disabled; that action removes
all of the user's subscriptions idempotently. Device labels, browser/platform family, and timestamps
are display/audit metadata only and never grant authority.

Notification owns read-state synchronization for its canonical database records. Inbound
notifications store exact, non-sensitive TicketMessage or EmailMessage source references. An
authenticated and authorized notification open marks that user's matching database notification
read. An authorized direct Ticket or Email-message view calls the same Notification-owned action for
the exact source IDs rendered by that page.

Notification read state remains separate from operational work state. Read synchronization never
changes `tickets.is_unread`, `ticket_messages.read_at`, the Ticket Mark as read action, or Email
workflow state. Unrelated source IDs and other users' notifications remain untouched. Browser
notification closure is best effort through the shared worker; the database record is authoritative.

Notification also owns a per-user, per-event limited-preview preference. It defaults to disabled.
When enabled for inbound Email, the payload may include only the sender display name and a
truncated subject in addition to the default minimal payload. It never includes the message body,
attachment names, customer/client identity, full sender address, or other private operational
details.

For `ticket_customer_reply_received` and `inbound_email_received`, database/in-app delivery is
enabled by default because it is the canonical notification record. Email, Nextcloud Talk, SMS, and
Web Push are disabled by default for both high-frequency types. A user may explicitly enable each
available extra channel independently. Existing notification types retain their current defaults.

The final channel policy includes optional per-user Web Push quiet hours, disabled by default. The
schedule uses the user's timezone with application-timezone fallback and applies to all active
devices. An active window suppresses push without delaying or replaying it; the canonical
database/in-app notification is still stored.

Quiet-hour persistence, UI, delivery-decision audit, and timezone edge-case tests belong to a later
Feature Slice. The initial three slices do not add dormant fields or controls. The inbound-email
types do not bypass quiet hours as critical events.

The implementation is split into three ordered Feature Slices: channel/device foundation with a
current-device self-test; inbound Email event delivery; and source read-sync plus rollout hardening.
Do not enable production inbound delivery between slices.

Every enabled event is delivered to all active subscriptions owned by the user. Preferences and
scopes are user-level. There is no per-device event matrix or pause state; revocation/expiry removes
a device from delivery and registration may add it again. Logout retains a subscription, while user
deactivation removes all.

A default-off `WEBPUSH_ENABLED` kill switch and complete VAPID readiness guard registration and
delivery. Administrators may revoke but cannot subscribe or enable preferences for another user.
Provider 404/410 responses remove expired subscriptions. Temporary 429/5xx/timeout failures receive
at most three attempts with exponential backoff and jitter. One device's outcome does not suppress
the user's other devices.

Accepted push events always show a visible notification, even with a foreground Nexum window.
Self-test is generic, rate-limited, and current-device-only. Normal logs and audits never contain
endpoint, key, auth-token, or message-body secrets.

The initial delivery adopts the channel only for internal inbound Email alerts. Other internal
notification types and Customer Portal push require separate slices after the channel is proven.

An assigned Ticket owner becomes eligible through the independently configurable
`ticket_customer_reply_received` preference. The owner does not need to subscribe to all inbound
Email. Active internal inbox/triage recipients become eligible through `inbound_email_received`.
Both Web Push preferences default to disabled, and a user eligible through both paths receives one
alert for the same inbound message.

Notification also maps the one post-routing event to these two preference types.

Notification also owns an extension-ready subscription scope model. An enabled inbound-email
triage setting without scope rows means all authorized inbound Email. Constrained scope rows can
later limit delivery to selected Email accounts or Ticket queues without changing the browser
subscription or channel model. These filters do not change owner eligibility through the separate
customer-reply preference. Feature Slice 2 exposes only the global triage choice.

## Rationale

The package reuses Laravel's established Notification model and the project's current user
preferences instead of creating a parallel delivery system. Its current release line supports PHP
8.2 and Laravel 12, VAPID, major Push API browsers, subscription lifecycle helpers, and cleanup of
expired endpoints.

A direct standards-based implementation avoids a vendor-specific Firebase/OneSignal account and
keeps notification routing in Nexum. Keeping one service worker preserves the approved PWA
architecture and prevents competing scopes or update behavior.

Minimal payloads reduce lock-screen disclosure and the amount of operational metadata that passes
through browser-vendor push infrastructure. Explicit opt-in follows browser requirements and avoids
surprise prompts.

## Consequences

- Composer gains a maintained third-party channel and its lower-level Web Push dependency.
- The database gains user-owned push subscriptions and a default-off Web Push preference.
- Users gain a self-service inventory for all owned devices.
- Administrators with `notification.manage_channels` gain a limited, audited revocation capability
  for other internal users.
- User deactivation gains an idempotent cross-module cleanup call into Notification.
- The database gains safe device metadata and durable, secret-free subscription lifecycle events.
- Ticket and Email detail surfaces gain a narrow cross-module call into Notification after their
  normal authorization succeeds.
- Canonical notification read state and operational Ticket/Email unread state remain independent.
- Notification preferences distinguish customer replies on owned Tickets from broader inbound Email
  triage coverage.
- Recipient union and delivery idempotency must prevent duplicates across both preference paths.
- Type-specific defaults prevent automatic email-about-email delivery while preserving a canonical
  in-app record.
- Extra-channel opt-in is independent by user, notification type, and channel.
- Future quiet-hour suppression will affect Web Push only and will never create a stale catch-up
  burst.
- One user event fans out to every active device without duplicating the canonical notification.
- The global kill switch and readiness checks can stop Web Push without stopping database/in-app
  notification delivery.
- Users revoke rather than pause devices; per-device preference complexity is intentionally avoided.
- Logout does not revoke a device, so the safe default payload and explicit device inventory remain
  important.
- Temporary delivery failures are bounded while permanent expiry removes stale subscriptions.
- The work is implemented in three reviewable slices and production remains off until all are done.
- The database also gains a default-off, per-event limited-preview preference so lock-screen
  disclosure remains an explicit user choice.
- Production gains stable `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, and `VAPID_SUBJECT` secrets.
- Losing or rotating VAPID keys invalidates existing subscriptions and requires resubscription.
- Subscription endpoints and encrypted payloads still pass through browser-vendor push services.
- HTTPS, a supported browser, a service worker, and an explicit user gesture are required.
- iOS/iPadOS users must install Nexum on the Home Screen before enabling Web Push.
- The service worker must always show a visible notification for accepted pushes; silent background
  work is not allowed.
- Push is best effort and cannot replace the canonical database notification or Email/Ticket state.
- Queue and scheduler monitoring remain operational prerequisites.
- Package updates require compatibility and security review because this is a sensitive external
  delivery boundary.

## Alternatives Considered

- **Build a custom Laravel channel on `minishlink/web-push`:** rejected for the first implementation
  because Nexum would duplicate subscription models, VAPID integration, delivery handling, and
  expired-endpoint cleanup already maintained by the Laravel channel package.
- **Firebase Cloud Messaging SDK:** rejected because Nexum does not need a Firebase application or
  vendor-specific client API to use standards-based Web Push.
- **OneSignal or another hosted notification platform:** rejected because it adds a provider control
  plane and more operational/customer metadata exposure without a current multi-channel need.
- **A second push-only service worker:** rejected because overlapping service-worker scope would
  conflict with the existing PWA lifecycle and cache policy.
- **Declarative Web Push only:** deferred because its browser support and specification are still
  evolving; the normal service-worker event model provides the required cross-browser baseline.
- **Web Push owned by Email:** rejected because inbound Email is only the first event and channel
  policy belongs in Notification.

## Follow-Up

- This ADR was accepted with the parent RFC by the product owner on 2026-07-24.
- Implement the three Feature Slices in order.
- Pin and review the selected package version and lockfile on Dev.
- Keep `WEBPUSH_ENABLED` false until each environment passes its readiness checks.
- Complete channel/device foundation and self-test before inbound event delivery.
- Complete inbound delivery before source-page read synchronization and release hardening.
- Add per-user Web Push quiet hours through a separate Feature Slice after the channel is proven.
- Add/update human-review entries for each large slice and the final browser/release matrix.
- Revisit per-account/queue subscription controls, other internal events, richer preview policy,
  owner alerts from portal/external customer replies, user-editable device nicknames, audit
  retention, and Customer Portal push through separate slices.
