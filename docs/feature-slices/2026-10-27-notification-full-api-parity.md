# Feature Slices: Full Notification Browser/API Parity

Status: Done On Dev - parent RFC approved by Svein Tore, 2026-10-27.
Date: 2026-10-27
Parent: ../rfc/2026-10-27-notification-full-api-parity.md
Owner: Codex. Approver/reviewer: Svein Tore.
Review: HR-2026-10-27-NOTIFICATION-API (Pending).

Execute sequentially; each slice updates its tests, typed Swagger, source documentation
and matrix evidence before the next slice. Preserve current contributor changes.
Common out of scope: new providers, #205 destination redesign, #258 VAPID wizard,
#262 runtime activation, real sends and Main/production actions.

## 01 - Own Notification Reads, Opening And Acknowledgement

Status: Done On Dev.
Goal/behavior: equivalent list/detail/recent/total/open/read-all/read-one behavior.
Scope: shared visibility/query/open/read actions; preserve Email source-read semantics,
Workday eligibility and safe target handling; actual unread total for web/API.
Data: own notifications, source references (authorized reads), future receipt boundary.
Permissions: notifications.read/update plus active current ownership/native access.
Tests: source isolation, stale Workday, cross-user, unsafe targets, bounded pagination,
no-write GET, exact typed results, bell regression.
Docs: notification-api, source-sync guidance and matrix.
Done: rows B01-B04 and Livewire equivalents have passing evidence and truthful read-back.

## 02 - Own Preferences And Workday Integration

Status: Done On Dev.
Goal/behavior: discover registry/capabilities and save own channels transactionally.
Scope: shared web/API preferences, strict fields, write-only webhook handling,
Workday existing API reuse plus missing rendered detail/open mapping.
Data: notification_settings and existing Workday reminder state only.
Permissions: notifications.preferences.read/write; Workday's own scopes/permissions
remain additionally required for its data and writes.
Tests: defaults/exclusions, mixed invalid batch rollback, omitted/submitted types,
coordinator denial, readiness, generation replay and secret canaries.
Docs: Notification/Workday guides and matrix.
Done: B05-B06 and W01-W04 are proved; no parallel Workday lifecycle.

## 03 - Own Devices And Safe Self-Test

Status: Done On Dev.
Goal/behavior: list/register/resolve/revoke own devices and request one generic test.
Scope: shared native requests/actions, safe public IDs, consent/readiness and throttle;
add durable command claims/receipts and typed operation lookup for tests.
Data: push_subscriptions, subscription lifecycle audit, additive command table and queue
only under isolated tests. No real device/employee message during implementation.
Permissions: notifications.devices.read/write, notifications.tests.execute, current
active internal identity; real outbound API execution additionally default-off.
Tests: ownership transfer/race, inactive/revoked user, safe summaries, no secret echo,
throttle, readiness, queue ambiguity/replay, native lock/unique/encryption probes.
Docs: device/self-test prerequisites and distinction between queued and delivered.
Done: B07-B11 and receipt lookup have passing evidence; exact migration verified.

## 04 - Administrative Channels And Devices

Status: Done On Dev.
Goal/behavior: full native channel list/edit context/update/test and cross-user revoke.
Scope: shared driver validation/context/actions, existing SMS dry_run, legacy Talk
truthful outcomes, secret-free status, overwrite preconditions and command replay.
Data: notification_channels, SMS dry_run evidence, subscription audit, command receipts.
Permissions: notification.manage_channels plus notifications.channels.read/write/test
or notifications.devices.manage; no new native role grants.
Tests: revoked rights, driver allowlists, Contact consent, explicit connection preservation,
safe failures, duplicate/unknown test outcomes, stale settings and safe device revocation.
Docs: channel/API guide with #205/#258/#262 limitations explicit.
Done: B12-B17 proved, no automatic activation; HTTP acceptance never claims Talk delivery.

## 05 - Portal And Complete Coverage Verification

Status: Done On Dev.
Goal/behavior: portal notification and preference workflows with native customer context;
full-domain release evidence and handbook truth.
Scope: portal-safe shared actions/queries plus exact runtime/Swagger method inventory,
all response schemas and negative cases, affected regressions and trusted HTTPS checks.
Data: caller's portal notifications/preferences; authoritative Knowledge sources and
affected BookStack articles only after implementation.
Permissions: notifications.portal.read/update/preferences.write intersected with active
native portal context; no internal preference/device/channel access.
Tests: B18-B22, mixed internal copies, foreign records, revoked contact/membership,
safe current targets, read-only GET, replay and cross-module/API/Swagger regressions.
Docs: matrix/evidence, Notification/portal/API management, actual BookStack bodies/access,
TODO/RFC/ADR/HR status and public-safe website handoff if appropriate.
Done: every browser/embedded row and successful API method has verified typed evidence;
no failure deferred silently; BookStack verified or concrete blocker recorded. Named
human review and Main/production remain separate gates.

Verification: ../plans/2026-10-27-notification-api-verification.md. Main promotion authorized; production and human scenario review remain separate.

Main promotion: PR #336 merged and exact tested tree independently verified. Production/human scenario gates remain separate.
