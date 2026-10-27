# Notification Browser/API Coverage Matrix

Date: 2026-10-27
Status: Verified On Dev - 30 Notification operations / 39 schemas, plus related Workday detail.
Parent: ../rfc/2026-10-27-notification-full-api-parity.md (Draft).
Source: authoritative Dev runtime route:list --json plus inspected controllers,
Livewire bell/view, models, requests, native middleware and existing tests.

14 Notification browser rows + 5 CustomerPortal browser rows; currently only
3 Notification API HTTP operations. HEAD is excluded. Workday already has four
reminder/preference API operations; verify and reuse them rather than duplicating.
The JSON companion retains exact runtime methods, paths, actions and middleware.
The original baseline/design below is retained for traceability. Exact implemented paths, scopes, schemas and test evidence are in the JSON companion and verification report.

## Exact Browser Route Mapping

| Method | Runtime path | Owner action | Coverage |
| --- | --- | --- | --- |
| GET|HEAD | tech/admin/notification-channels | NotificationChannelController@index | B12 |
| GET|HEAD | tech/admin/notification-channels/web-push/devices | WebPushDeviceController@index | B16 |
| DELETE | tech/admin/notification-channels/web-push/devices/{subscription} | WebPushDeviceController@destroy | B17 |
| PUT | tech/admin/notification-channels/{channel} | NotificationChannelController@update | B14 |
| GET|HEAD | tech/admin/notification-channels/{channel}/edit | NotificationChannelController@edit | B13 |
| POST | tech/admin/notification-channels/{channel}/test | NotificationChannelController@test | B15 |
| GET|HEAD | tech/profile/notifications | NotificationSettingsController@show | B05, W02 |
| POST | tech/profile/notifications | NotificationSettingsController@update | B06, W02 |
| GET|HEAD | tech/profile/notifications/open/{notification} | NotificationOpenController | B04, W04 |
| GET|HEAD | tech/profile/notifications/web-push/devices | WebPushSubscriptionController@index | B07 |
| POST | tech/profile/notifications/web-push/devices | WebPushSubscriptionController@store | B08 |
| POST | tech/profile/notifications/web-push/devices/current | WebPushSubscriptionController@current | B09 |
| DELETE | tech/profile/notifications/web-push/devices/{subscription} | WebPushSubscriptionController@destroy | B10 |
| POST | tech/profile/notifications/web-push/test | WebPushSelfTestController@store | B11 |
| GET|HEAD | portal/notifications | CustomerPortalNotificationController@index | B18, B19 |
| POST | portal/notifications/preferences | CustomerPortalNotificationController@updatePreferences | B20 |
| POST | portal/notifications/read-all | CustomerPortalNotificationController@markAllRead | B21 |
| POST | portal/notifications/{notification}/open | CustomerPortalNotificationController@open | B22 |
| POST | portal/notifications/{notification}/read | CustomerPortalNotificationController@markRead | B21 |

## Complete Capability Inventory

| ID | Workflow | Kind | Baseline | Planned API / required scope | Acceptance |
| --- | --- | --- | --- | --- | --- |
| B01 | Own list and recent bell | read | Partial | GET /api/v1/notifications; notifications.read | Shared visibility, bounded pagination, complete unread total and typed response |
| B02 | Own detail and target context | read | Missing | GET /api/v1/notifications/{notification}; notifications.read | No read side effect or additional restricted source data |
| B03 | Mark one/all read | write | Partial | POST /api/v1/notifications/{notification}/read; POST /api/v1/notifications/read-all; notifications.update | Current visibility and exact persisted results; source work state unchanged |
| B04 | Open own notification | action | Missing | POST /api/v1/notifications/{notification}/open; notifications.update | Safe current target; inbound source acknowledgement stays separate |
| B05 | Internal preference/context form | read | Missing | GET /api/v1/notifications/preferences; GET /api/v1/notifications/context; notifications.preferences.read | Groups/defaults/support/readiness; Workday separately gated; webhook configured only |
| B06 | Update own internal preferences | write | Missing | PUT /api/v1/notifications/preferences; notifications.preferences.write | Transactional validated batch; write-only credential URLs; no Workday bypass |
| B07 | List own Web Push devices | read | Missing | GET /api/v1/notifications/web-push/devices; notifications.devices.read | public_id and safeSummary only |
| B08 | Register own browser subscription | write | Missing | POST /api/v1/notifications/web-push/devices; notifications.devices.write | Direct consent workflow; secret input never echoed; unique ownership |
| B09 | Resolve current device and touch last seen | action | Missing | POST /api/v1/notifications/web-push/devices/current; notifications.devices.write | POST retained because last_seen_at changes |
| B10 | Revoke own device | write | Missing | DELETE /api/v1/notifications/web-push/devices/{subscription}; notifications.devices.write | Own public ID; exact removal and audit read-back |
| B11 | Generic own-device self-test | action | Missing | POST /api/v1/notifications/web-push/test; notifications.tests.execute | Readiness, 3/10 throttle, command claim, 202 means queued |
| B12 | Admin channel inventory | read | Missing | GET /api/v1/notifications/channels; notifications.channels.read | Also native notification.manage_channels; safe readiness/counts |
| B13 | Admin channel edit context/detail | read | Missing | GET /api/v1/notifications/channels/{channel}; GET /api/v1/notifications/channels/{channel}/context; notifications.channels.read | Bounded native options and driver fields; no secret config echo |
| B14 | Admin channel update | write | Missing | PUT /api/v1/notifications/channels/{channel}; notifications.channels.write | Native rights, driver allowlists, expected state, encrypted write-only secrets |
| B15 | Admin channel test | action | Missing | POST /api/v1/notifications/channels/{channel}/test; notifications.channels.test | SMS dry_run/contact consent; Talk accepted != delivered; no blind replay |
| B16 | Admin device inventory/search | read | Missing | GET /api/v1/notifications/admin/web-push/devices; notifications.devices.manage | Native manage_channels; safe target owner/device fields only |
| B17 | Admin device revocation | write | Missing | DELETE /api/v1/notifications/admin/web-push/devices/{subscription}; notifications.devices.manage | Native permission, public ID, audit and exact read-back |
| B18 | Portal list/detail/summary | read | Missing | GET /api/v1/notifications/portal; GET /api/v1/notifications/portal/{notification}; notifications.portal.read | Native active portal context and portal-only copies; no internal payload |
| B19 | Portal preference context | read | Missing | GET /api/v1/notifications/portal/preferences; notifications.portal.read | Portal audience and mail/database fields only |
| B20 | Portal preference update | write | Missing | PUT /api/v1/notifications/portal/preferences; notifications.portal.preferences.write | Same rows; unsupported channels remain disabled |
| B21 | Portal mark one/all read | write | Missing | POST /api/v1/notifications/portal/{notification}/read; POST /api/v1/notifications/portal/read-all; notifications.portal.update | Other users and internal copies untouched |
| B22 | Portal open | action | Missing | POST /api/v1/notifications/portal/{notification}/open; notifications.portal.update | Current portal-safe target and persisted acknowledgement |
| W01 | Workday pending reminders | read | Existing - verify | GET /api/v1/workday-reminders; workday-reminders.read | Existing own permission, eligibility, no coordinator |
| W02 | Workday preference read/write | read/write | Existing - verify | GET and PUT /api/v1/workday-reminder-preferences; workday-reminders.read/write | Notification-owned shared preference action; no generic scope shortcut |
| W03 | Workday snooze | action | Existing - verify | POST /api/v1/workday-reminders/{id}/snooze; workday-reminders.write | Current generation; retry does not extend snooze |
| W04 | Workday reminder landing/open | read/action | Partial - verify | GET /api/v1/workday-reminders/{id}; explicit open via owning shared action; workday-reminders.read/write | Render-equivalent own context; separate explicit read acknowledgement |
| X01 | Own command outcome read-back | read | Missing | GET /api/v1/notifications/operations/{operation}; notifications.operations.read | Original actor/token and current object/native rights; sanitized result only |

## Embedded Workflow Evidence

NotificationBell::loadNotifications maps to B01; markAsRead and markAllAsRead to
B03; openNotification to B04/W04; snoozeWorkday to W03. Its passive poll does
not imply provider delivery. Notification settings render registry groups,
readiness and Workday eligibility (B05/W02); save loops require transactional
validation (B06). Channel edit renders active Nextcloud options, SMS templates
and Contact choices (B13), and test handles Talk plus SMS dry_run (B15).

Portal preference UI is part of index as well as its explicit update route
(B19-B20). Workday reminder landing renders context and marks a notification read;
four existing API operations do not alone prove that page's full parity (W04).
Administrative secret fields and subscription transport data are not inventories.

## Completion Evidence To Fill During Implementation

Each row needs exact runtime method/path, Swagger operation/schema, native policy
and token ability, successful typed HTTP test, denied/revoked/foreign case,
side-effect/result read-back and relevant docs. Mark Existing/Partial rows verified
only after behavior tests; do not infer from route registration or source comments.
All new implementation rows currently lack this evidence and remain unimplemented.
#205, #258 and #262 future workflows remain separate, explicitly outside this claim.


## Verified Implementation (2026-10-27)

All B01-B22, W01-W04 and X01 rows are verified on Dev. The JSON companion binds
each workflow to exact runtime operations, token scope, typed schema and test evidence.
Every one of the 30 Notification HTTP operations has a successful real-bearer contract
assertion and persistence/read-back checks. Workday's new detail plus four retained
operations are independently covered by ReminderTest and the complete 37-operation
Workday/work-plan API contract. Current native and token rights remain separate.
See notification-api-verification.md for exact suites, native probes, HTTPS and
BookStack evidence. Main promotion is explicit; production and manual review are separate.
