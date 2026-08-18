Notification channels define how Nexum delivers system notifications outside the in-app notification bell.

## Web Push

Web Push uses the existing Nexum PWA and shared service worker to show visible browser
notifications. Internal users can register devices explicitly, send a generic current-device test,
and enable Web Push only for event types that have implemented browser-safe payloads.

### Register a device

1. Open Profile > Notifications.
2. Find Web Push devices.
3. Select Enable on this device.
4. Accept the browser or operating-system notification prompt.
5. Use Send test to this device to verify the current device.

Nexum requests permission only after the user selects Enable. If permission is denied, change the
site notification permission in the browser or operating-system settings before trying again.

On iPhone and iPad, first add Nexum to the Home Screen, open the installed app, and then enable Web
Push inside that installed app.

Registering a device does not automatically enable Web Push for ticket, email, or another business
event. Event preferences remain user-owned and default to off for Web Push.

### Manage devices

Profile > Notifications lists the signed-in user's registered devices. The list contains only a
generated device label, browser/platform family, registration time, and last-seen time. A user may
revoke the current device or another device they own. Revoking the current device also asks the
browser to unsubscribe it locally.

Signing out does not revoke a registered device. This allows a deliberate registration to continue
after the next sign-in. Disabling the Nexum user removes every registered Web Push device for that
user.

Administrators with `notification.manage_channels` can open Admin > Notification channels > Web
Push devices to search the privacy-safe inventory and revoke a lost or obsolete device.
Administrators cannot register a device or enable an event for another user.

Nexum does not display push endpoints, encryption keys, authentication tokens, or VAPID secrets in
user/admin inventories or lifecycle audits.

### Availability and troubleshooting

Web Push registration requires:

- the environment-wide Web Push switch to be enabled;
- complete VAPID configuration;
- HTTPS and the shared Nexum service worker;
- a browser that supports Service Worker, Push Manager, and Notifications;
- a running Laravel queue worker for test delivery.

The status panel distinguishes an environment that is unavailable, an insecure or unsupported
browser, denied permission, an unregistered browser, and a registered current device.

If a generic test is queued but not received:

1. Confirm the device still appears in Profile > Notifications.
2. Confirm site notifications are allowed in both browser and operating-system settings.
3. Confirm the queue worker is running and Web Push is ready in Admin > Notification channels.
4. Revoke and register the current device again if the browser subscription was reset.

Expired provider endpoints are removed automatically. Temporary provider failures use bounded
queue retries. All accepted pushes remain visible, and clicking one focuses an existing Nexum
window when possible or opens Nexum in a new window.

### Inbound Email and Ticket replies

The finished inbound Email Web Push workflow supports two internal notification types:

- Customer reply on my Tickets.
- New inbound Email.

Both types default to in-app/database delivery. Email, Nextcloud Talk, and Web Push stay disabled
until the user enables each channel for the event type.

When a non-spam inbound Email completes routing, Notification creates at most one canonical
database notification for each eligible user and Email message. Retries and repeated rule execution
reuse the same delivery identity instead of creating duplicate alerts.

Recipient behavior:

- The assigned, active Ticket owner can receive Customer reply on my Tickets when an inbound Email
  becomes a customer Ticket reply and the owner has Ticket access.
- Explicit New inbound Email subscribers can receive inbox/triage alerts when they are active and
  authorized for the target Email inbox or linked Ticket.
- Emergency break-glass content access does not by itself authorize background inbox subscriptions,
  canonical alerts, or external previews. A user must have ordinary current mailbox View authority;
  break-glass disclosure remains an interactive, separately audited action.
- If the same user is eligible through both paths, Nexum keeps one notification for that Email.
- Stored account and queue scopes can restrict the inbox subscriber path. No stored scope means all
  authorized inbound Email for that subscriber.

Privacy behavior:

- Default lock-screen payloads are generic.
- The optional preview toggle may include sender display name and a truncated subject only.
- Email bodies, attachment names, client/customer identity, full sender addresses, push endpoints,
  encryption keys, auth tokens, and VAPID secrets are not exposed in Web Push payloads or device
  inventories.

Read behavior:

- Opening a push notification or bell item goes through a Notification-owned open route before
  redirecting to the source Ticket or Email.
- Directly opening the linked Ticket marks only the current user's matching canonical notifications
  read for TicketMessage records rendered on the page.
- Directly opening an unlinked Email message marks only the current user's matching canonical
  notification read for that EmailMessage.
- Ticket unread flags, TicketMessage `read_at`, Email state, and Email workflow state are not
  changed by notification read synchronization.

Web Push remains best effort. Failed browser delivery does not roll back Email, Ticket, or canonical
database notification persistence.

#### Durable external delivery and current recipient checks

For a newly accepted inbound Email event, Notification commits the canonical database notification
and a payload-free `notification_inbound_external_deliveries` outbox row in the same transaction
when Email, Web Push, or Nextcloud Talk was requested. Retries reuse that identity. Existing legacy
canonical notifications are not retrofitted into the outbox because their original recipient,
preference, mailbox, Ticket, and provider-binding evidence is no longer current enough to authorize
an external send.

The outbox stores only requested channels and safe notification, user, Email source, account, mail
scope, and provider-binding references. The canonical notification remains the source of the
content. Immediately before external delivery, the worker rechecks all of the following:

- the user is active, non-system, and still an exact current recipient for the Email or Ticket;
- the canonical source, notification type, and Email account remain coherent;
- the user's current preference still enables each originally requested channel;
- mailbox or Ticket authority still allows the source; and
- Email delivery still resolves the exact frozen Notification mail scope, account, and provider
  binding generation.

Delivery uses only the intersection of channels requested when the event was committed and channels
still enabled now. A revoked, disabled, rebound, or no longer eligible target becomes terminal
`suppressed`; it does not fall back to another recipient, account, binding, or system mailer. Each
row has one claimed external attempt. A provider-uncertain exception or abandoned worker claim is
terminal `unresolved` because one channel may already have accepted the message; Nexum never blindly
replays it. Canonical in-app notification availability and read state remain independent of that
external outcome.

The scheduler dispatches at most 100 pending or safely recoverable pre-attempt rows each minute.
`DispatchPendingInboundEmailExternalNotifications` and
`DeliverInboundEmailExternalNotification` run on the `notifications` queue, so installations must
supervise that worker after the `118200` migration. Operators should investigate `suppressed` and
`unresolved` safe reason codes, current user preferences/authority, the exact Notification Email
binding, the `notifications` queue backlog, and failed jobs. Do not manually replay an unresolved
row or copy canonical content into the outbox.

This durable delivery boundary is implemented and automatically verified as part of Mail Order 7,
but `HR-2026-08-16-007` remains Pending. No automated result completes its mailbox, provider,
scheduler, worker, queue/backlog, privacy, or delivery review checks.

### Account-backed email delivery

Notification email and password resets use the Notification-owned Email account channel. They do
not use Laravel's ambient system mailer. The channel selects an active shared/system Email account
through the configured default scope:

- Ticket notifications and Ticket-linked inbound alerts use `tickets`.
- Asset and supplier-order operational alerts use `alerts`.
- Booking, customer portal, password reset, and non-Ticket inbound alerts use `system`.

The selected account ID and provider-binding version are frozen before Laravel clones a synchronous
notification or serializes a queued notification. Delivery rechecks the same default scope, account,
readiness, and binding, renders the supported Markdown message inside Notification, and submits it
through the Integration-backed Email SMTP boundary. Queue payloads contain the safe account ID,
scope, and binding generation, never provider hosts, usernames, or credentials.

A missing, paused, revoked, rebound, or newly selected provider blocks delivery without falling back
to another account, legacy credentials, or the system mailer. Synchronous callers receive a safe
failure code. Queued delivery records a safe blocked result. If SMTP may already have accepted a
message but the response is uncertain, the result is `smtp_send_outcome_unresolved` and is never
automatically replayed. Unsupported custom mailers, views, sender overrides, Bcc, attachments, or
callbacks fail closed until the account-backed channel explicitly supports and tests them.

After deploying a channel or provider-binding change, restart long-lived queue workers so queued
notifications load the current snapshot and delivery contract.

## Transactional SMS

SMS is an approved transactional notification channel, but the first implementation uses the
`dry_run` provider only. Dry-run records the exact SMS attempt in Nexum without sending anything to
Telia, Twilio, or another external provider.

The SMS channel is configured under Admin > Notification channels > SMS.

First-slice SMS behavior:

- Provider is `dry_run`.
- Admins can enable or disable the channel.
- Admins can set sender name and default country code.
- Admins can send a manual dry-run test to a Contact with a phone number.
- Each allowed or blocked attempt creates a `notification_sms_messages` audit record.
- No Booking reminder, quote follow-up, invoice reminder, on-my-way message, or other workflow sends
  SMS until that workflow adopts SMS in a later approved slice.

SMS consent and blocking:

- The selected Contact phone must have `sms_allowed=true`.
- A Contact with `do_not_call=true` is blocked from transactional SMS.
- Missing or invalid phone numbers are blocked.
- Marketing consent does not grant transactional SMS permission, and transactional SMS does not
  create Marketing campaign membership.

Dry-run logs store the rendered SMS body, selected provider, status, normalized recipient phone,
source reference, actor, and block reason when applicable. Production provider credentials and
delivery callbacks are intentionally outside this first slice.

## Customer Portal Notifications

Customer portal notifications use the same Laravel database notification table and user preference
model as internal notifications, but the Notification module sends them with portal-specific event
types and CustomerPortal-safe URLs.

Portal users read notifications from `/portal/notifications`. The portal notification center shows
only `CustomerPortalNotification` records owned by the authenticated portal user. Opening a
notification marks it read and redirects only to `/portal` URLs.

Current portal notification types cover implemented portal workflows:

- Portal ticket created, reply, and status changed.
- Portal document published and updated.
- Portal client-wide Knowledge article published and updated.
- Portal quote sent and accepted.
- Portal contract sent and accepted.
- Portal order published and status changed.

Customer portal notifications support email and in-app delivery preferences. Nextcloud Talk, SMS,
web push, and native app delivery are not part of this portal slice.

## Nextcloud Talk

Nextcloud Talk notifications support two delivery modes:

- Talk Bot API through the active Nextcloud connection.
- Legacy incoming webhook URL through Notification channel settings.

The Talk Bot API is preferred when the selected Nextcloud connection has `talk_bot_id` and
`talk_bot_secret` configured. Webhook delivery remains available for installations that do not yet
use the bot API.

The Notification module owns channel enablement, fallback webhook URL, and per-user notification
preferences. The Nextcloud module owns the Nextcloud integration itself, including base URL,
credentials, sync settings, users, groups, calendars, folder mappings, Talk bot id, Talk bot shared
secret, default conversation token, and Talk bot features.

Before the Nextcloud Talk channel can be enabled, at least one active Nextcloud integration must exist under Admin > Nextcloud. If no active integration exists, the channel remains disabled even if a webhook URL is saved.

## Configuration

Use Admin > Notification channels > Nextcloud Talk to choose the active Nextcloud integration and
configure fallback delivery.

The page defaults to the active global default Nextcloud integration. Admins can select another active Nextcloud integration for this notification channel when needed.

The page does not ask for a Nextcloud base URL, API token, bot id, or bot secret. Those values belong to the Nextcloud integration settings.

Users can still configure personal Nextcloud Talk webhook URLs in their notification preferences.
When Bot API delivery is active, Nexum extracts the conversation token from a personal webhook URL
when possible and uses that as the user's conversation override. When webhook delivery is used,
personal webhook URLs take priority over the system default webhook URL.

## Bot API Delivery

Bot API delivery is automatic when the selected active Nextcloud connection has bot settings saved.

The delivery flow is:

1. The notification class provides `toNextcloudTalk`.
2. The channel resolves the active Nextcloud connection.
3. If the connection has a Talk bot id and secret, `NextcloudTalkClient` sends an HMAC-signed message.
4. If no bot is configured, the channel falls back to webhook delivery.

Bot API messages support richer Markdown formatting, details, links, reference ids, silent messages,
and future inbound command processing.

## Webhook Fallback

Webhook delivery posts `{ "message": "..." }` to the configured URL. This mode is simpler and has
less context than Bot API delivery.

Use webhook fallback when the Nextcloud server does not support Talk Bot API or when the bot has not
been installed yet.

## Testing

Use Test Connection from the channel settings page after saving fallback settings. Use Test Bot
Message from the Nextcloud connection detail page after saving Talk bot settings.

The channel test requires:

- An active Nextcloud integration.
- A valid default webhook URL when testing fallback delivery.

If required configuration is missing, the test reports the configuration issue instead of attempting
delivery.
