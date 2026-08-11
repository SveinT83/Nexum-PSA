# Feature Slice 1: Web Push Channel And Device Foundation

Status: Done
Date: 2026-07-24
Parent: `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`
ADR: `docs/adr/2026-07-23-notification-owned-web-push-channel.md`
Owner: Svein / Codex

## Implementation Progress

The code, Composer lock, additive migration, privacy-safe device UI, shared service-worker
extension, lifecycle audit, disabled-user cleanup, generic self-test job, tests, Knowledge
documentation, and deployment runbook are implemented in the authoritative Dev working copy.
Migration batch 51 ran on Dev, stable Dev VAPID keys are configured, and sanitized readiness reports
Ready.

On 2026-08-11, Dev runtime inspection found a cron-managed `email,default` database queue worker
running every minute and active long-running queue workers. The Dev crontab does not run the full
Laravel `schedule:run` list; inbound Email is covered by the dedicated `email:poll --account=1`
cron path. Browser/device checks in `HR-2026-07-24-001` still require a named human reviewer, but
the implementation slice itself is complete.

## Goal

Establish and prove the secure Notification-owned Web Push channel, shared service-worker behavior,
and complete internal-user device lifecycle before connecting a business event.

## User-Visible Behavior

An authenticated internal user can open Notification Preferences and:

- see whether Web Push is globally available, unsupported, denied, unsubscribed, or subscribed on
  the current device;
- explicitly request browser permission and register the current device;
- list every registered device they own using a generated label, browser/platform family,
  registration time, and last-seen time;
- revoke the current or another owned device;
- send a rate-limited, generic test push only to the current owned device.

The permission prompt appears only after the user clicks Enable. On iOS/iPadOS, Nexum explains that
the PWA must be installed on the Home Screen before registration is available. Registering a device
does not enable any business-event preference.

An administrator with `notification.manage_channels` can use a Notification-owned admin surface to
see the same privacy-safe summaries for internal users and revoke a lost or obsolete subscription.
No user or administrator sees a raw endpoint, public key, authentication token, or VAPID secret.

Signing out does not revoke a deliberately registered device. Disabling an internal User removes
all owned subscriptions automatically. A revoked device can be registered again later.

## Scope

- Add and pin a Laravel 12/PHP 8.2-compatible
  `laravel-notification-channels/webpush` Composer constraint and review its transitive dependency.
- Add `WEBPUSH_ENABLED`, stable VAPID public/private keys, and a stable VAPID subject through normal
  environment/secret configuration.
- Keep Web Push globally disabled when the feature flag is false or required VAPID configuration is
  incomplete. Show a sanitized readiness state instead of prompting the browser.
- Add package-compatible push subscription persistence tied to the internal User model.
- Store only generated device label, browser family, platform family, registration timestamp, and
  last-seen timestamp as inventory metadata.
- Add Notification-owned, secret-free subscription lifecycle audit events for registration, user
  removal, administrator revocation, expired-endpoint cleanup, and disabled-user cleanup.
- Add authenticated and CSRF-protected current-user create/update/list/delete/test routes in
  `app/Modules/Notification/routes.php`.
- Add permission-guarded administrator list/revoke routes under Notification ownership.
- Validate subscription endpoint, key, auth token, encoding, size, and ownership without logging raw
  material.
- Extend the existing `public/sw.js`; do not add a second service worker.
- Add visible `push` and safe `notificationclick` handling while retaining the existing online-first
  fetch and offline fallback behavior.
- Accept only same-origin relative/allowlisted targets and fall back to Notification Preferences.
- Focus an existing Nexum window when possible and otherwise open a new one.
- Send a rate-limited generic self-test to the current owned subscription only.
- Add `notification_settings.web_push_enabled` and
  `notification_settings.web_push_preview_enabled`, both default `false`, without exposing Web Push
  toggles for notification types that have no implemented Web Push payload.
- Treat every existing, non-expired subscription row as an active device. There is no pause state or
  per-device event matrix in the approved design.
- When a user enables a supported event in a later slice, deliver it to every active registered
  device. The user-level event preference remains the source of truth.
- Remove all subscriptions idempotently through a Notification-owned action when UserManagement
  changes an internal User to Disabled.
- Remove 404/410 expired subscriptions and audit the safe cleanup.
- Add focused Notification, UserManagement, and service-worker regression tests.
- Add a human-review entry before implementation handoff.

## Out Of Scope

- Inbound Email or Ticket recipient resolution.
- New business-event notification types.
- Email-account or Ticket-queue filters.
- Sensitive-content preview payloads.
- Source-page read-state synchronization.
- Quiet-hour fields or UI.
- Customer Portal Web Push.
- Native mobile applications.

## Data Touched

- New package-compatible `push_subscriptions` table or reviewed package-equivalent migration.
- Safe device inventory metadata on the package table or a tightly coupled Notification-owned
  companion record.
- New Notification-owned `web_push_subscription_events` table or equivalent durable audit model.
- Additive `notification_settings.web_push_enabled` boolean, default `false`.
- Additive `notification_settings.web_push_preview_enabled` boolean, default `false`.
- Web Push configuration and VAPID environment keys.
- Existing `public/sw.js`.
- No Email, Ticket, TicketMessage, or existing notification-row changes.

## Permissions

- Authenticated internal Users can create, list, refresh, test, and revoke only their own
  subscriptions.
- `notification.manage_channels` is required for cross-user safe inventory and revocation.
- No inventory or audit response serializes endpoints or key material.
- Device metadata never grants authorization.
- Test delivery requires current-user ownership and rate limiting.
- UserManagement may call only the Notification-owned disabled-user cleanup action.

## Tests

Automated:

- package/config boot and additive migrations;
- global flag off and incomplete-VAPID readiness states;
- browser permission is never requested on page load;
- authenticated current-user create/update/list/delete;
- endpoint uniqueness and safe refresh of an owned subscription;
- malformed, oversized, or cross-user subscription rejection;
- own-device and administrator inventory serialization excludes all secrets;
- ordinary-user denial for cross-user list/revoke;
- permission-guarded administrator revocation and audit;
- generic self-test ownership, current-device targeting, and rate limiting;
- user logout retains a subscription;
- changing a user to Disabled removes all subscriptions idempotently and audits cleanup;
- 404/410 cleanup and bounded handling of other provider failures;
- shared service-worker push/click handlers without fetch/offline regression;
- invalid/cross-origin click target fallback;
- default-off Web Push and preview fields without enabling unsupported existing types.

Manual:

- Chrome/Edge desktop registration, test receipt, focus/open, revoke, and re-register;
- Firefox and Safari/macOS where available;
- installed iOS/iPadOS Home Screen PWA;
- unsupported, insecure-context, denied, and global-not-ready states;
- two devices owned by one user;
- user remote-revocation and administrator revocation;
- logout followed by test receipt and authenticated click;
- disabled-user cleanup;
- service-worker update and existing offline fallback.

## Documentation

- Notification channel Knowledge documentation.
- Responsive PWA Knowledge documentation.
- VAPID generation, stable-secret, migration, cache-clear, and worker-restart runbook.
- Administrator device-revocation and user offboarding behavior.
- `docs/TODO.md` status.
- `docs/human-review.md` implementation review entry.

## Done Criteria

- Parent RFC is Approved and the ADR is Accepted before implementation.
- Global readiness prevents unusable browser permission prompts.
- One internal user can register, list, test, revoke, and re-register supported devices.
- An authorized administrator can revoke another user's device without seeing secrets.
- Disabling a user removes every subscription and records secret-free cleanup.
- One generic test reaches only the selected current device.
- The shared service worker retains existing PWA install/fetch/offline behavior.
- Focused Dev tests and automated service-worker smoke checks pass; named human
  browser/device review remains tracked separately.
- Deployment prerequisites and rollback steps are documented.
- Human review remains Pending until a named reviewer completes the listed checks.
