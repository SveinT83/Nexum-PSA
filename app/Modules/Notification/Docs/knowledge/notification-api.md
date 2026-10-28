Verified on Dev, 2026-10-27. This guide describes the original 30 Notification API operations
and related Workday context. Production deployment is separate. Swagger or handbook
availability alone does not prove that these operations are deployed in production.

## Prerequisites and access

Use a personal scoped key from **Admin > Integrations > API Management**. Current active
user rights and record ownership apply in addition to token abilities. Channel/admin
device actions also require notification.manage_channels. Portal APIs require a current
active linked contact, account and membership. Swagger at /api/documentation documents
exact typed requests and responses. Never put device endpoints/keys, webhook URLs or
credentials into assistant conversations; use the direct authorized browser/form.

## Own notifications and the bell

1. GET /api/v1/notifications lists your own stored copies. Optional unread, page and
   per_page (1-100) filters are validated.
2. GET /api/v1/notifications/summary returns the complete unread total and at most ten
   recent unread copies. The bell uses that same total with a bounded dropdown.
3. GET /api/v1/notifications/{notification} reads a representation without marking read.
4. POST .../{notification}/read acknowledges one; POST .../read-all acknowledges all
   currently eligible own copies. Read-back returns persisted state/the updated count.
5. POST .../{notification}/open returns target_url, acknowledged and source_read_required.
   Inbound Email opening alone leaves source acknowledgement to its authorized source
   page. Notification state never changes Ticket unread/workflow or Email provider state.

Use notifications.read/update. Existing URLs remain compatible. Current Workday
eligibility filters obsolete reminders. Legacy own-copy APIs may include your own
portal copies; use the portal-specific APIs for a narrowly restricted customer client.

## Profile preferences

1. Open **Profile > Notifications**, or GET .../notifications/context or /preferences.
2. Inspect registry groups/descriptions, supported channels, defaults, preferences and
   safe readiness before selecting channels.
3. PUT .../notifications/preferences with a settings array of distinct notification_type
   rows and channel booleans. All submitted rows validate and commit as one transaction.
4. For submitted types, omitted booleans are false. Omitted types remain unchanged.
   Inspect returned context to verify saved choices.

Use notifications.preferences.read/write. Unsupported fields/types are rejected and
registry-excluded channels cannot be enabled. The personal webhook URL is write-only;
nextcloud_talk_webhook_configured is returned instead. Omit it to retain, send null/empty
to clear, or provide a valid HTTPS replacement through the direct authorized form.
Disabling Talk clears the URL. Preferences do not send notifications or activate channels.
Workday data additionally requires its own eligibility and reminder scopes.

## Own Web Push devices and generic test

1. On trusted HTTPS, use **Profile > Notifications > Enable on this device** and explicitly
   grant browser permission. Direct registration supplies endpoint, keys.p256dh, keys.auth
   and optional supported content_encoding. API support cannot grant browser permission.
2. GET .../notifications/web-push/devices returns public UUID, generated label,
   browser/platform and timestamps only. Transport endpoint/key material is not returned.
3. POST .../web-push/devices/current accepts a direct endpoint and updates last_seen_at.
   A foreign endpoint returns no device.
4. DELETE .../web-push/devices/{subscription} revokes an own public UUID and records audit.
   Another account's endpoint cannot be transferred.
5. POST .../web-push/test with a command key requests one generic own-device test.
   At most three distinct requests per ten minutes are admitted. HTTP 202 and queued
   prove queue admission only; receipt must be verified on the actual device.

Use notifications.devices.read/write and notifications.tests.execute. Existing global
readiness/device consent remains mandatory. Real API test execution additionally defaults
off through NOTIFICATION_API_EXTERNAL_TESTS_ENABLED. Availability never generates/rotates
VAPID keys, changes device permission or enables a global channel.

## Admin channel and device management

1. Open **Admin > Notification channels**, or GET .../notifications/channels.
2. GET .../channels/{channel} and .../{channel}/context expose safe settings, an etag,
   active Nextcloud options and bounded existing SMS template/Contact choices.
3. PUT .../channels/{channel} with Idempotency-Key and that current ETag in If-Match.
   Use driver-supported fields only. Secret inputs are write-only; absent API credential
   values retain existing configuration. Read back the exact resulting settings.
4. POST .../channels/{channel}/test with a command key runs an explicit test.
   SMS supports dry_run only, selected active template and native phone/SMS-consent rules.
   A dry-run log is not an SMS delivery.
5. A legacy Talk webhook HTTP 2xx returns provider_accepted, delivery_confirmed=false.
   It does not prove a message exists in a Talk conversation. A connection error returns
   unknown; reconcile before sending again. Raw provider errors are not exported.
6. GET .../admin/web-push/devices supports search/page/per_page. DELETE the selected
   public UUID with a command key to revoke it with the native lifecycle audit.

Use notifications.channels.read/write/test or notifications.devices.manage, plus native
notification.manage_channels. SMS dry-run needs no external-provider activation. Talk conversation selection (#205) is available in the separately verified Nextcloud API.
Managed Web Push setup (#258) adds the six operations documented below. Runtime reconciliation
(#262) retains independent System ownership and queue scope.

## Retry and exact operation outcome

1. Generate an 8-200 character Idempotency-Key for a new guarded admin action/test.
   Reuse it only with the same method, path, body and If-Match.
2. Retain operation_id/X-Operation-Id. Identical retries return recorded results with
   replayed=true without another send or audit event.
3. With notifications.operations.read, GET .../operations/{operation}. The original actor,
   credential, original scope and current native rights are also required.
4. Claimed/unknown outcomes require reconciliation. Do not invent a new key to bypass
   an uncertain send. Revoked rights deny historical receipts.

Local changes/results commit together; external effects have a durable claim first.
Receipts contain only encrypted sanitized results and input fingerprints. Provider
acceptance, queuing and confirmed delivery are distinct. No backlog is released.

## Customer Portal

Use /api/v1/notifications/portal for list; /summary and /{notification} for reads;
/{notification}/open, /{notification}/read and /read-all for explicit actions; and
/preferences GET/PUT for portal choices. Scopes are notifications.portal.read/update
and notifications.portal.preferences.write. An optional own active membership_id is
validated without fallback from a bad selection. Session and bearer clients reuse
native current account/contact/membership. Internal copies/preferences are excluded.
Portal preferences accept only portal types and Email/in-app choices. Opening resolves
a currently visible same-origin source in the selected customer/site scope.
**Customer Portal > Notifications** uses the same shared actions.

## Workday integration

Keep the existing /api/v1/workday-reminders read, /workday-reminder-preferences GET/PUT,
and /workday-reminders/{id}/snooze with generation-based retries. New
GET /api/v1/workday-reminders/{id} returns current own reminder/Workday-link context
without acknowledging it. Explicit open/read operates only currently eligible copies.
Workday own permissions, workday-reminders.read/write, feature/readiness switches and
coordinator denial remain additional requirements. Generic Notification scopes do not
grant employee time/reminder access.

## Troubleshooting

- 401: use a current, unexpired credential.
- 403/404: check native rights, ownership, current portal membership and Workday eligibility.
- 409/412: inspect key reuse/current state, then reload before changing the request.
- 422: correct the complete validated batch using registry/context.
- 428: supply the required command key and current channel If-Match.
- 429: wait for the documented test window.
- 503: inspect safe readiness and operator-controlled API test configuration.
- queued/provider_accepted/unknown: verify independently; do not claim delivery or
  blindly retry an unknown send.

## Complete HTTP inventory

All paths below use /api/v1/. Swagger includes typed success/request/failure contracts.

| Method | Path | Required token ability |
| --- | --- | --- |
| GET | notifications | notifications.read |
| GET | notifications/summary | notifications.read |
| GET | notifications/context | notifications.preferences.read |
| GET | notifications/preferences | notifications.preferences.read |
| PUT | notifications/preferences | notifications.preferences.write |
| GET | notifications/web-push/devices | notifications.devices.read |
| POST | notifications/web-push/devices | notifications.devices.write |
| POST | notifications/web-push/devices/current | notifications.devices.write |
| DELETE | notifications/web-push/devices/{subscription} | notifications.devices.write |
| POST | notifications/web-push/test | notifications.tests.execute |
| GET | notifications/channels | notifications.channels.read |
| GET | notifications/channels/{channel}/context | notifications.channels.read |
| GET | notifications/channels/{channel} | notifications.channels.read |
| PUT | notifications/channels/{channel} | notifications.channels.write |
| POST | notifications/channels/{channel}/test | notifications.channels.test |
| GET | notifications/admin/web-push/devices | notifications.devices.manage |
| DELETE | notifications/admin/web-push/devices/{subscription} | notifications.devices.manage |
| GET | notifications/operations/{operation} | notifications.operations.read |
| POST | notifications/read-all | notifications.update |
| GET | notifications/{notification} | notifications.read |
| POST | notifications/{notification}/read | notifications.update |
| POST | notifications/{notification}/open | notifications.update |
| GET | notifications/portal | notifications.portal.read |
| GET | notifications/portal/summary | notifications.portal.read |
| GET | notifications/portal/preferences | notifications.portal.read |
| PUT | notifications/portal/preferences | notifications.portal.preferences.write |
| POST | notifications/portal/read-all | notifications.portal.update |
| GET | notifications/portal/{notification} | notifications.portal.read |
| POST | notifications/portal/{notification}/read | notifications.portal.update |
| POST | notifications/portal/{notification}/open | notifications.portal.update |

## Complete Workday reminder handbook reference

Choose personal reminder channels, review a planned workday and snooze without interrupting other work.

## Choose your reminders

When Workday is enabled and you have permission to view and register your own time,
open **Profile > Notifications > Workday**. In-App is enabled by default. Email and Web Push
are off until you choose them. Turn all three channels off to disable Workday reminders.

Email requires the configured system Email account. Web Push requires an available service
and a device you registered in the existing Notifications page. A missing channel is explained
beside its control. You can still disable a previously selected channel if it becomes unavailable.
Workday does not offer message previews or Nextcloud Talk.

## Timing and absence

A reminder is due 15 minutes before the last remaining planned work interval ends, or at the
start of that interval if it is shorter than 15 minutes. Your personal plan, its dated/recurring
exceptions and timezone are used. A night shift belongs to the local date on which it started.

Unknown plans and off-days do not invent working hours. Full absence suppresses the reminder;
partial absence keeps the remaining work interval. Education blocks are planned work, not
absence. Ordinary busy meetings do not change your work plan. Already recorded or legacy confirmed days are skipped,
including when you save through the employee API or receive a validated import. A later draft correction does
not undo the previous confirmation for reminder purposes.

## Open or snooze

The header bell updates quietly. It does not open a dialog, move keyboard focus, navigate away
or submit an unsaved form. An unread reminder remains available on your next visit.

Select the reminder to open a personal page with **Open workday** and **Snooze 30 minutes**.
Snooze is also available directly in the bell. Repeated clicks from another tab do not extend
the same snooze. Each explicit new snooze starts one new reminder generation.

Opening a reminder never registers or confirms time. All actual time still requires your
explicit review and confirmation.

## Privacy and delivery

Messages contain only a generic invitation to review your own day. They contain no work
description, absence category, customer data or manager escalation. The target checks your
current access again. A queued job rechecks your status, permission, plan, absence, preferences
and confirmation before delivery.

A single daily reminder is created across scheduler/job retries. New channel preferences apply
to future reminder generations; disabling a channel also suppresses pending delivery.
External reminders more than twelve hours after planned end are suppressed; the in-app reminder
can remain for your next visit. Discovery covers today and yesterday, including overnight work.
It does not retrospectively generate a backlog for a long scheduler outage.

External submission cannot prove inbox/device receipt. An uncertain external attempt is not
automatically resent. You can still review your workday directly.

## Employee API

Use an attributable personal employee token, not an automation/coordinator identity.

| Operation | Scope |
| --- | --- |
| GET /api/v1/workday-reminders | workday-reminders.read |
| GET /api/v1/workday-reminder-preferences | workday-reminders.read |
| PUT /api/v1/workday-reminder-preferences | workday-reminders.write |
| POST /api/v1/workday-reminders/{id}/snooze | workday-reminders.write |

Preference PUT replaces database_enabled, mail_enabled and web_push_enabled as booleans.
All false disables reminders. Repeating the same PUT is safe. Unsupported channels or enabling
an unconfigured channel returns 422. GET returns persisted preferences and current readiness.

Pending reads return up to ten eligible unread in-app reminders among the fifty most recent
retained unread receipts. Snooze accepts the current generation number. An immediate previous
generation retry returns current state without extending time; other stale versions return 409.
Read back after writing. The generated API specification describes these contracts.
All operations require explicit own Workday permissions and obey the default-off runtime switches.

## Availability

Slice 06 is implemented on Dev with the whole Workday pilot still disabled. Real email/device
receipt and manual browser review remain part of HR-2026-10-01-WORKDAY when the complete pilot
is ready. Production activation is separate. Tripletex synchronization remains future scope.

## Notification API integration (2026-10-27)

Verified on Dev; production rollout is separate. The Notification API/Profile share the
same guarded personal reminder row. Workday own permissions, reminder scopes and
coordinator denial remain mandatory. GET /api/v1/workday-reminders/{id} returns a current
own reminder and Workday navigation link without acknowledging it. Existing versioned
snooze preserves generation-based retry. Explicit open/acknowledgement accepts only a
currently eligible own copy. The bell shows the total unread count and ten recent copies.


## Managed Web Push setup API (Issue #258, 2026-10-28)

The six managed-setup operations extend the existing Notification inventory from 30 to 36.
Implementation is being verified on Dev; production deployment/installation attestation is separate.

| Method | Path under /api/v1/ | Token ability |
| --- | --- | --- |
| GET | notifications/web-push/setup | notifications.channels.read |
| POST | notifications/web-push/setup/initialize | notifications.channels.write |
| POST | notifications/web-push/setup/check | notifications.channels.test |
| PATCH | notifications/web-push/setup/activation | notifications.channels.write |
| POST | notifications/web-push/setup/rotate | notifications.channels.write |
| POST | notifications/web-push/setup/rollback | notifications.channels.write |

Every operation also checks current active native notification.manage_channels authority. GET returns
a safe context and exact ETag. Writes require that quoted If-Match, revision, Idempotency-Key and
confirm=true. Initialize accepts a stable subject; activation accepts enabled. Rotation requires
ROTATE WEB PUSH; rollback requires ROLL BACK WEB PUSH and archive_revision. No private key, path,
command, arbitrary probe URL or push-message body is accepted or returned. Typed Swagger documents
the exact request/result schemas and durable command outcomes.

Managed API mutations additionally default off through
NOTIFICATION_API_MANAGED_WEBPUSH_EXECUTION_ENABLED. The explicit check does not send a message;
it validates trusted same-installation HTTPS/service-worker bytes and a bounded worker canary on
the existing approved healthy default queue. It never starts a worker or enables a blocked queue.
Read current context after the worker runs; a pending/unknown check cannot enable delivery.
Missing/stale If-Match, revoked scope/native rights, unsupported private storage and incomplete
readiness fail safely. Reuse an exact command key only with the identical request; reconcile an
unknown claim before trying another operation.

Browser registration on a managed installation also supplies application_server_key from the actual
browser PushSubscription.options.applicationServerKey. It must match the current public identity.
Registration and rotation cleanup share a database mutex; old browser subscriptions cannot silently
survive identity replacement. Browser permission and business-event preferences remain separate.
The deployment runbook and HR-2026-10-28-WEBPUSH-SETUP cover operator provisioning, secret recovery,
whole-worker attestation and real-device verification.
