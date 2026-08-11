# RFC: Web Push And Inbound Email Alerts

Status: Approved
Date: 2026-07-23
Owner: Svein / Codex
Related Discussion: [GitHub Discussion #169](https://github.com/SveinT83/Nexum-PSA/discussions/169)
Parent Direction: `docs/rfc/2026-07-04-one-responsive-nexum-pwa.md`
Proposed ADR: `docs/adr/2026-07-23-notification-owned-web-push-channel.md`
Feature Slice 1: `docs/feature-slices/2026-07-24-web-push-channel-device-foundation.md`
Feature Slice 2: `docs/feature-slices/2026-07-23-web-push-internal-email-alerts.md`
Feature Slice 3: `docs/feature-slices/2026-07-24-web-push-read-sync-rollout-hardening.md`

## Context

Nexum already has an installable, online-first PWA foundation. Its manifest, existing service
worker, mobile shell, and offline fallback are implemented, but the approved PWA direction
intentionally deferred push notifications to a separate slice.

The Notification module currently supports Laravel database notifications, email delivery, and
Nextcloud Talk preferences. It does not own browser push subscriptions or a Web Push delivery
channel. The service worker handles installation, activation, navigation fallback, and static
asset caching, but it does not handle `push` or `notificationclick` events.

Email ingestion already polls active IMAP accounts through the Laravel scheduler, normally every
minute, and processes messages through Email rules before linking them to Tickets or leaving them
for inbox triage. Incoming email is currently linked to a Ticket by creating a TicketMessage
directly. That path does not emit the existing internal comment notification, so merely adding a
Web Push transport would not alert a technician when a customer reply arrives.

This is Level 3 work. It adds a package and database tables, extends the service worker, introduces
browser-facing subscription endpoints, changes notification preferences, and connects Email,
Ticket, Notification, System/PWA, queues, and deployment secrets.

## Goals

- Add standards-based Web Push as a reusable internal Notification channel.
- Keep one Nexum PWA and one service worker instead of creating a separate mobile application.
- Let an authenticated internal user explicitly register or remove the current browser/device.
- Let each user review and revoke all of their own registered push devices.
- Let an authorized administrator view a privacy-safe device inventory and revoke another user's
  subscription for lost-device and offboarding incidents.
- Keep Web Push disabled by default for every notification type.
- Deliver a useful first event when a non-spam inbound email has completed Email routing.
- Open the linked Ticket when one exists and otherwise open the Email inbox message.
- Synchronize the canonical notification read state when its push is opened or its exact source is
  viewed directly, without clearing Ticket or TicketMessage operational unread state.
- Give the linked Ticket owner a distinct customer-reply notification type whose Web Push preference
  does not depend on an all-inbound-email subscription.
- Default the two new high-frequency types to database/in-app only so Nexum does not automatically
  send an email, Talk message, SMS, or push about each inbound email.
- Notify explicitly subscribed internal inbox/triage recipients without broadcasting every message
  to every technician.
- Let the final product filter inbound-email subscriptions by Email account and Ticket queue while
  keeping the first user-facing slice intentionally simple.
- Let the final product support optional per-user Web Push quiet hours without delaying stale pushes
  until the quiet period ends.
- Keep lock-screen payloads privacy-safe by default and let each user opt into a limited
  sender-name/subject preview per notification type.
- Make dispatch idempotent so job retries do not create duplicate alerts for the same user and
  inbound message.
- Preserve scheduler, queue, and Email health visibility as prerequisites for timely push.

## Non-Goals

- Do not add offline writes, offline inbox data, background synchronization, or silent push.
- Do not build a native iOS or Android application.
- Do not enable Customer Portal push in the initial three-slice delivery.
- Do not move every existing Notification type to Web Push in the initial three-slice delivery.
- Do not add per-email-account or per-Ticket-queue filter controls in Feature Slice 2.
- Do not include email bodies, attachment names, client/customer identity, full sender addresses, or
  other private operational details in a lock-screen payload, even when limited previews are enabled.
- Do not mark a Ticket, TicketMessage, or Email workflow item as operationally read merely because a
  push or in-app notification was opened.
- Do not replace the in-app notification center, email delivery, Nextcloud Talk, or SMS.
- Do not claim instant delivery when the Email scheduler, queue worker, IMAP server, or browser push
  provider is unavailable.

## Current Behavior

- `routes/console.php` schedules `PollActiveEmailAccounts` every minute.
- Active accounts dispatch `FetchImapAccount`, which stores new messages and dispatches inbound
  rule processing.
- Email rules can create or link Tickets, archive messages, tag messages, and emit Signals.
- `LinkInboundEmailToTicket` creates a customer TicketMessage, marks the Ticket unread, and records
  a Ticket event, but it does not create an internal Laravel notification.
- Notification preferences expose email, database/in-app, and optionally Nextcloud Talk.
- `public/sw.js` has no Web Push event handling.
- Browser/device subscriptions and VAPID keys do not exist.

## Proposed Change

### 1. Notification-Owned Web Push Channel

Use the maintained `laravel-notification-channels/webpush` package on the Laravel 12 application.
Pin a compatible Composer constraint and commit its lockfile result. Notification owns:

- the Web Push channel configuration;
- subscription create/update/delete routes;
- current-user subscription authorization;
- per-notification-type `web_push_enabled` preference;
- reusable Web Push payload construction conventions;
- cleanup of expired subscriptions;
- channel-level tests and operational documentation.

The package uses VAPID and browser-native push services. Nexum must store the VAPID private key only
in environment/secret configuration. The public key may be exposed to authenticated JavaScript for
subscription creation. `VAPID_SUBJECT` must be a stable HTTPS or `mailto:` identity.

### 2. One Shared Service Worker

Extend the existing `public/sw.js`. Do not register a second service worker for Web Push.

The shared worker must:

- retain the current online-first fetch and offline fallback rules;
- show one visible notification for each accepted push event;
- use same-origin URLs supplied by Nexum;
- focus an existing Nexum window when possible;
- otherwise open a new Nexum window on notification click;
- fall back to a safe internal landing page when payload data is absent or invalid;
- never cache notification payloads or private response data.

### 3. Explicit Device And Preference Opt-In

Add a device section to the authenticated internal Notification Preferences page. The browser
permission request and `PushManager.subscribe()` call must occur only after the user clicks an
Enable button.

The UI must distinguish:

- unsupported browser or insecure context;
- permission not yet requested;
- permission denied in browser/operating-system settings;
- device subscribed;
- device not subscribed;
- subscription registration failure.

Users can review all of their own registered devices as privacy-safe summaries and revoke the
current or another owned device. The summary may show a generated device label, browser family,
platform family, registration time, and last-seen time. It must never expose the raw push endpoint,
public key, authentication token, or VAPID secrets.

Administrators with `notification.manage_channels` can view the same limited summary for internal
users and revoke a subscription. They cannot view raw subscription material. Administrative
revocation is recorded with actor, target user, action, safe device summary, and timestamp. User
registration/removal and lifecycle cleanup use the same secret-free audit event model.

Changing an internal User to Disabled automatically removes every push subscription owned by that
user. The cleanup must run from the canonical UserManagement status-change path and be idempotent.

Web Push remains disabled by default on each event type until the user explicitly enables it. On
iOS/iPadOS, the UI must explain that Nexum needs to be installed on the Home Screen before Web Push
is available.

The channel defaults for both `ticket_customer_reply_received` and `inbound_email_received` are:

- database/in-app: enabled;
- email: disabled;
- Nextcloud Talk: disabled;
- SMS: disabled;
- Web Push: disabled.

A user may explicitly enable any available extra channel independently for either notification type.

#### Final Quiet-Hour Behavior

A later Feature Slice must add optional per-user quiet hours for Web Push. Quiet hours are disabled
by default and apply to every active push device owned by that user.

The quiet-hour policy must:

- use the user's configured profile timezone, falling back to the application timezone;
- support windows that cross midnight and daylight-saving transitions;
- evaluate the policy before push delivery;
- suppress Web Push during the active window without creating a delayed/catch-up push;
- continue creating the canonical database/in-app notification;
- leave notification read state and Ticket/Email operational state unchanged;
- resume only with new eligible events after the quiet period ends.

The current inbound-email and customer-reply types have no critical bypass. A future critical-event
policy requires an explicit product decision rather than silently overriding quiet hours.

The initial three-slice delivery does not add quiet-hour fields or UI. The behavior belongs in a
later slice so the initial implementation does not expose an unfinished control.

### 4. Inbound Email Notification Event

Emit one post-routing inbound Email event after classification and routing have completed, not
immediately after raw IMAP storage. Resolve that event through two independent internal
notification preference types:

- `ticket_customer_reply_received` means Customer reply on my Tickets. It makes the assigned Ticket
  owner eligible without requiring an all-inbound-email subscription.
- `inbound_email_received` means New inbound Email for inbox/triage coverage. It is the global
  subscription in Feature Slice 2 and later accepts Email-account and Ticket-queue filters.

Both Web Push choices remain disabled by default. Feature Slice 2 emits
`ticket_customer_reply_received` only for customer replies arriving through inbound Email. Reusing
the same owner preference for customer replies from the portal or approved external integrations is
the final target, but wiring those additional sources is outside the initial delivery.

Default event policy:

- suppress messages classified as spam or archived by a hard-stop classifier/rule;
- include linked Tickets, newly created Tickets, and non-archived inbox/triage messages;
- link to the Ticket when the Email message has `ticket_id`;
- otherwise link to the authorized Email inbox message;
- make the linked Ticket owner eligible through `ticket_customer_reply_received` when an active,
  authorized owner exists;
- independently make active internal users eligible when they enable `inbound_email_received`;
- do not require the Ticket owner to enable `inbound_email_received`;
- merge recipients so the same user receives one inbound-email notification;
- exclude users who cannot access the target route or source record;
- persist a stable delivery identity so repeated Email/rule jobs do not duplicate the event for the
  same user.

Feature Slice 2 exposes both independent Web Push choices, while
`inbound_email_received` initially has one global subscription control. Its underlying subscription
scope model must already support `all`, selected Email accounts, and selected Ticket queues so later
filter controls do not require a replacement data model. No selected scope means all authorized
non-archived inbound Email for that preference type. Disabling either preference remains the
explicit way to receive none through that recipient path.

### 5. Safe Payload

The default lock-screen payload should contain:

- product identity and a generic new-email title;
- the Ticket key when the recipient can access a linked Ticket;
- a short action label that tells the user to open Nexum to read the message;
- a same-origin URL or relative route;
- a stable tag that lets the browser replace accidental duplicate delivery.

Each user's `web_push_preview_enabled` preference is stored per notification type and defaults to
`false`. When the user explicitly enables it for inbound Email, the payload may additionally
contain the sender's display name and a truncated subject. It must not expose a full sender address.

The payload must never contain the email body, attachment names, customer/client identity, or other
private operational context, regardless of the preview preference. Clicking the alert does not
grant access; normal session, route, permission, work-context, and record visibility checks still
apply.

### 6. Notification Read-State Synchronization

The Laravel database notification is the canonical read-state record for both the in-app alert and
its Web Push delivery. Each inbound notification must contain stable, non-sensitive source
references such as `ticket_id`, `ticket_message_id`, or `email_message_id`.

Read synchronization follows these rules:

- opening a push or the notification bell marks only that authenticated user's matching database
  notification as read after normal authentication, target authorization, and successful target
  resolution;
- opening a Ticket directly marks that user's unread `ticket_customer_reply_received` and linked
  `inbound_email_received` notifications as read only for TicketMessage source IDs rendered on the
  page, plus EmailMessage source IDs stored in those rendered TicketMessage metadata records;
- opening an unlinked Email inbox message directly applies the same rule to its exact
  `email_message_id`;
- unrelated notifications for the same Ticket, another message, or another user remain unchanged;
- repeated direct views and notification opens are idempotent;
- neither path changes `tickets.is_unread`, `ticket_messages.read_at`, the explicit Mark as read
  workflow, or any Email processing state.

When the PWA is active, the page should ask the shared service worker to close any still-visible
browser notification with the matching stable tag where the browser supports it. This is best
effort; the database notification remains the source of truth.

### 7. Queue And Operational Behavior

Web Push delivery is best effort and queued. A failed browser push must not roll back or lose the
stored Email message or Ticket link. Expired subscriptions should be removed automatically. Other
temporary failures should follow bounded queue retries and be logged without subscription secrets.

Operational defaults are:

- `WEBPUSH_ENABLED` is a global kill switch and defaults to `false`;
- missing or invalid VAPID configuration keeps registration and delivery unavailable while canonical
  database/in-app notifications continue;
- enabling an event fans it out to every active, non-expired subscription owned by the user;
- event preferences, preview choices, account/queue scopes, and future quiet hours are user-level,
  not per-device;
- active means the subscription row exists and has not been revoked or expired; there is no pause
  state;
- revoking is the supported way to stop one device, and the user may register it again later;
- signing out does not revoke a deliberately registered device;
- an administrator may revoke but cannot register a device or enable an event on another user's
  behalf;
- one device's success or failure does not stop delivery attempts to the user's other active devices;
- provider 404/410 responses remove and audit the expired subscription without retry;
- 429, 5xx, timeout, and temporary transport failures use at most three attempts with exponential
  backoff and jitter;
- accepted push events always produce a visible browser notification, including when a Nexum window
  is foregrounded;
- a generic, rate-limited self-test targets only the current owned subscription;
- endpoints, keys, auth tokens, and payload bodies are never written to normal logs.

Rollout remains globally disabled until migrations, stable VAPID secrets, HTTPS, the shared worker,
queue workers, and the external once-per-minute Laravel scheduler runner are verified. Dev self-test
and browser checks precede inbound-event enablement. Production enablement waits for all three
Feature Slices and named human review.

The end-to-end path remains:

1. Laravel scheduler starts the Email poll.
2. The queue fetches and stores the IMAP message.
3. Email classification/routing completes.
4. Nexum resolves authorized recipients idempotently.
5. Notification dispatch stores the canonical in-app record and queues Web Push for enabled users.
6. The browser push service wakes the existing Nexum service worker.
7. A click returns through normal Nexum authentication and authorization.

If production still requires the manual Check now action to discover Email, scheduler and queue
health must be fixed before Web Push can be considered operational.

## Impact Analysis

- **Notification:** channel, current-user and administrator subscription routes, safe device
  inventory, secret-free audit events, user preferences, notification class, tests, and Knowledge
  documentation.
- **System/PWA:** shared service worker push/click handling and device capability UI behavior.
- **Email:** post-routing event emission, spam/archive suppression, recipient resolution input, and
  idempotency.
- **Ticket:** owner recipient, Ticket URL, and authorization reuse; no Ticket workflow bypass.
- **Ticket/Email read surfaces:** authorized detail views call a Notification-owned, source-specific
  read-sync action without changing their own operational unread/workflow state.
- **UserManagement:** authenticated internal user owns subscriptions and personal preferences;
  disabling a user invokes Notification-owned subscription cleanup.
- **Database:** package push subscription table plus safe device metadata, Web Push preference
  fields, subscription audit events, and a narrowly scoped delivery identity or equivalent unique
  record.
- **Queue/scheduler:** queued outbound delivery depends on existing workers; Email polling remains
  scheduler-owned.
- **Deployment:** Composer install, migration, stable VAPID secrets, cache clear, worker restart, and
  service-worker cache/version review.
- **Security/privacy:** external browser push endpoints receive encrypted minimal payloads; lock
  screens may expose titles; user/admin routes require strict authorization; raw endpoints and key
  material never appear in inventory or audit responses.

## Data And Migration Plan

- Publish or reproduce the package-compatible `push_subscriptions` migration in the repository.
- Add type-specific NotificationSetting defaults for `ticket_customer_reply_received` and
  `inbound_email_received`: database/in-app `true`; email, Nextcloud Talk, SMS, and Web Push `false`.
  Do not change defaults for existing notification types.
- Do not add unused quiet-hour columns in the initial three-slice delivery. A later slice owns the
  persisted per-user timezone-aware schedule and delivery-decision audit needed by the final behavior.
- Add `web_push_enabled` to `notification_settings` with a default of `false`.
- Add `web_push_preview_enabled` to `notification_settings` with a default of `false`; the value is
  independent for each user and notification type.
- Add extension-ready subscription scope persistence under Notification ownership. Scope rows use a
  constrained scope type such as `email_account` or `ticket_queue` plus the referenced record ID.
  An enabled inbound-email setting with no scope rows means all authorized inbound Email; one or
  more rows restrict delivery to those accounts/queues.
- Add the minimum persisted unique delivery identity required to prevent duplicate inbound-email
  notifications per Email message and user.
- Store stable, non-sensitive `ticket_id`, `ticket_message_id`, and/or `email_message_id` references
  in the canonical notification data so direct source views can synchronize only exact matches.
- Tie subscriptions to the internal User model and use a unique endpoint.
- Store only the safe metadata needed for device inventory: generated label, browser family,
  platform family, registration timestamp, and last-seen timestamp. Detection is informational and
  must not be used as an authorization signal.
- Add Notification-owned `web_push_subscription_events` or an equivalently scoped audit table for
  registration, user removal, administrator revocation, expired-endpoint cleanup, and
  user-deactivation cleanup. Audit records contain no endpoint, public key, authentication token, or
  VAPID secret.
- Do not store VAPID private keys in the database.
- Existing users, notifications, service-worker installations, and preferences remain valid.
- The migration is additive. Rollback removes the new channel data without altering Email or Ticket
  records.
- VAPID keys must remain stable across deployments; rotating them requires users to subscribe again.

## Testing Plan

Automated:

- subscription routes require authentication and CSRF and cannot alter another user's subscription;
- unsupported or malformed subscription payloads are rejected;
- users can list and revoke all of their own devices without receiving raw endpoint or key material;
- ordinary users cannot list or revoke another user's devices;
- administrators with `notification.manage_channels` can list safe device summaries and revoke
  another user's subscription;
- administrative revocation records a secret-free audit event;
- administrators without `notification.manage_channels` are denied;
- changing a user to Disabled removes every owned subscription idempotently and records lifecycle
  cleanup without secrets;
- Web Push preferences default to disabled and persist independently per notification type;
- both new notification types default to database/in-app enabled and email, Nextcloud Talk, SMS, and
  Web Push disabled;
- type-specific defaults do not change any existing notification type;
- explicitly enabling one extra channel for one type does not enable another channel or type;
- the limited-preview preference defaults to disabled and persists independently per user and
  notification type;
- opening a push target marks only the authenticated user's matching database notification as read;
- a signed-out or unauthorized target attempt does not mark the notification before successful
  authentication and authorization;
- directly viewing a Ticket marks only notifications whose TicketMessage source IDs are rendered, or
  whose EmailMessage source IDs are stored in those rendered TicketMessage metadata records;
- directly viewing an unlinked Email message marks only notifications with its exact EmailMessage
  source ID;
- direct source viewing does not change `tickets.is_unread`, `ticket_messages.read_at`, or Email
  workflow state;
- unrelated notifications and other users' notifications remain unread;
- all read-sync paths are idempotent;
- the service-worker file retains existing offline behavior and contains push/click handlers;
- an authorized Ticket owner who enables `ticket_customer_reply_received` receives a linked
  customer-reply alert even when `inbound_email_received` is disabled;
- an owner with both recipient paths disabled does not receive Web Push;
- unassigned/triage Email notifies only explicit subscribers with Email access;
- empty subscription scope means all authorized inbound Email, while stored account/queue scopes
  restrict recipient resolution even though Feature Slice 2 exposes only the global choice;
- spam/archived Email does not notify;
- one user eligible through both preference types receives one alert;
- re-running inbound jobs does not duplicate the notification;
- an opted-in payload may include sender display name and a truncated subject;
- payloads always omit message bodies, attachment names, customer/client identity, full sender
  addresses, and other private operational details;
- disabled Web Push still preserves database/in-app behavior;
- channel failure does not roll back Email or Ticket persistence.

Future quiet-hours slice:

- quiet hours default to disabled;
- active cross-midnight and daylight-saving-aware windows suppress Web Push for every active device;
- suppression preserves the canonical database/in-app notification;
- suppressed pushes are not queued or replayed later;
- new eligible events resume after the quiet period ends;
- read state and Ticket/Email operational state remain unchanged.

Manual/browser:

- Chrome/Edge desktop subscription, receipt, click, disable, and resubscribe;
- Firefox desktop where available;
- Safari/macOS where available;
- installed iOS/iPadOS Home Screen PWA subscription and receipt;
- own-device inventory and remote revocation from a second signed-in device;
- authorized administrator inventory/revocation and automatic cleanup when a user is disabled;
- push click, notification-bell click, direct Ticket view, and direct Email-message view read-sync;
- direct Ticket view leaves the Ticket and customer reply operationally unread until explicitly
  marked read;
- permission-denied and unsupported-browser states;
- foreground and background notification click behavior;
- signed-out click redirects through login and returns only to an authorized record;
- service-worker update does not break the existing offline fallback;
- end-to-end production-like Email poll to push latency with active scheduler and worker.

## Documentation Plan

- Update `app/Modules/Notification/Docs/knowledge/notification-channels.md`.
- Update `app/Modules/System/Docs/knowledge/responsive-pwa-behavior.md`.
- Update the Email inbox/system documentation with the post-routing alert policy.
- Document VAPID generation, secret persistence, migration, worker restart, and browser support.
- Add a human-review entry before implementation handoff.
- Record public-safe PWA/push capability in the nexumpsa.eu handoff only after implementation and
  human verification.

## Implementation Sequence

1. Feature Slice 1 establishes global readiness, VAPID, the package, shared-worker handling, secure
   current-user subscriptions, safe device inventory, self-test, administrator revocation, audit,
   and disabled-user cleanup.
2. Feature Slice 2 adds the two inbound notification types, channel defaults, recipient resolution,
   extension-ready account/queue scopes, idempotent canonical records, privacy-safe payloads, and
   all-active-device fan-out.
3. Feature Slice 3 adds direct source-page read synchronization and completes browser, service-worker,
   scheduler, queue, deployment, and human-review hardening.

Do not enable production inbound Web Push between slices.

## Open Questions

No product question currently blocks RFC approval. On 2026-07-23 the product owner decided that the
final product must support per-Email-account and per-Ticket-queue subscription filters. The first
inbound-delivery slice exposes a global opt-in, but its persisted scope model must be extension-ready
for those filters.

The product owner also decided that lock-screen previews are optional per user and notification
type, disabled by default, and limited to sender display name plus a truncated subject. Message
content and other private operational details remain prohibited.

On 2026-07-24 the product owner decided that Customer reply on my Tickets is a separate notification
type. An assigned Ticket owner can therefore enable customer-reply Web Push without subscribing to
all inbound Email. The independent inbound-email preference covers
inbox/triage monitoring and later Email-account/Ticket-queue filters. A user eligible through both
paths still receives one alert.

On 2026-07-24 the product owner decided that users manage their own device inventory and authorized
administrators may revoke another user's subscriptions. Inventory and audit views expose only a safe
device label, browser/platform family, and timestamps, never raw endpoint or key material.
Administrative revocation is audited, and changing a user to Disabled automatically removes all of
their subscriptions.

On 2026-07-24 the product owner decided that opening a push marks only its canonical notification
record as read. Directly viewing the source Ticket must also mark matching notifications for the
customer replies rendered there, even when the technician ignored the push. The same source-specific
rule applies to direct unlinked Email-message views. These actions never clear the Ticket,
TicketMessage, or Email workflow's operational unread state; that remains an explicit technician
action.

On 2026-07-24 the product owner decided that the two new notification types default to
database/in-app enabled, while email, Nextcloud Talk, SMS, and Web Push default to disabled. Users
may explicitly enable each extra channel independently. Existing notification-type defaults remain
unchanged.

On 2026-07-24 the product owner decided that the final product supports optional personal Web Push
quiet hours, disabled by default and applied to all active devices. Push is suppressed rather than
delayed during the user's timezone-aware quiet period, while the canonical database/in-app
notification is still created. The initial three slices do not implement the schedule fields or UI.

On 2026-07-24 the product owner decided that every enabled event is delivered to all active
registered devices. Preferences and scopes remain user-level; no per-device event matrix or pause
state is added. A device stops receiving after revocation or expiry and can be registered again.

The product owner delegated all remaining design defaults to Codex on 2026-07-24. Codex selected a
default-off global kill switch, sanitized readiness reporting, current-device-only self-test,
logout retention, administrator revoke-only authority, three-attempt bounded temporary retries,
automatic 404/410 cleanup, visible foreground notifications, secret-free logging, staged rollout,
and three independently reviewable Feature Slices.

## Approval

The product owner approved creation and refinement of this RFC, its ADR, and the three ordered
Feature Slices on 2026-07-23 and 2026-07-24.

On 2026-07-24 the product owner explicitly approved the RFC for implementation, accepted the ADR,
requested that GitHub Discussion #169 move from In review to In progress, and authorized coding to
start. Implementation begins with Feature Slice 1 on the authoritative Dev working copy.

## Implementation

Implemented on Dev on 2026-08-11 across all three Feature Slices. The delivery uses one
Notification-owned Web Push channel, one shared service worker, explicit internal device
registration, canonical per-EmailMessage/user notification identities, inbound Email/customer-reply
recipient resolution, privacy-safe payloads, and source read synchronization from Ticket and Email
views without changing Ticket or Email operational read state.

Production enablement still requires the named browser/device and end-to-end checks in
`HR-2026-07-24-001` and `HR-2026-08-11-002`.
