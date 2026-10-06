# Feature Slice: Web Push Notification Registry And Safe Delivery

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-web-push-notification-type-registry.md`
Owner: Codex

## Goal

Make one Notification-owned registry authoritative for type/channel policy and deliver every
approved internal Web Push event through a reusable queued, authorized, privacy-safe, best-effort
boundary.

## User-Visible Behavior

Users who explicitly enable an eligible event may receive a minimal Web Push on every registered
device. Current opt-out, active-user state, record access, and domain permission are checked again
before transport. An unavailable provider or invalid target never removes or rolls back the in-app
notification.

## Scope

- Add complete registry metadata for all current Notification preference types.
- Derive settings labels, defaults, channel support, audience, and eligibility from the registry.
- Require an exclusion reason for every Web Push-ineligible type.
- Add the internal Web Push payload/authorization contract and queued best-effort channel/job.
- Add safe payloads for Ticket assignment/status/comment/SLA and Asset alert notifications.
- Move supplier-order Web Push from direct vendor calls to the shared queued boundary.
- Preserve the specialized durable inbound Email external-delivery boundary.
- Add registry, preference-forgery, delivery, authorization, opt-out, payload, retry, and regression
  tests.

## Out Of Scope

- Preference layout changes beyond what is needed to consume registry data.
- Customer Portal Web Push, new event emitters, preview expansion, new routes/API/permissions,
  database changes, or service-worker behavior changes.
- Production activation or delivery to a real customer/user without the later human-review gate.

## Data Touched

- `notification_settings` is read and updated through existing behavior; no schema changes.
- Existing `notifications` rows remain authoritative and are never rewritten by push failure.
- Existing Web Push subscriptions and secret-free lifecycle audit remain unchanged.
- Notification/Ticket/Asset/Storage PHP classes and tests are updated.
- New queued jobs use the existing default queue without a new scheduler entry.

## Permissions

No new permission. Delivery requires an active non-system user, current Web Push preference,
registered device, registry eligibility, the existing domain permission, and current exact-target
visibility. Existing device administration remains protected by `notification.manage_channels`.

## Tests

- Registry completeness and exclusion-reason contract.
- Preference defaults, forged unsupported values, current opt-out, and unknown types.
- Canonical database delivery before queued push and provider-failure containment.
- Active/disabled/system user, permission, deleted target, wrong target, and current-recipient
  suppression.
- Minimal same-origin payloads for all eligible classes.
- Bounded retry and expired-subscription handling.
- Existing inbound Email, supplier-order, Notification, Web Push foundation, Ticket, Asset,
  UserManagement, and service-worker tests.

## Documentation

Update the parent RFC implementation notes, this slice status, TODO evidence, Notification
Knowledge documentation, and the later human-review checklist.

## Done Criteria

- [x] RFC is explicitly approved before implementation.
- [x] Every current type has complete authoritative registry metadata.
- [x] Every eligible internal type has a tested safe payload and current-target authorization.
- [x] Every excluded type has a documented reason and cannot be forged on.
- [x] Generic and supplier-order Web Push uses the queued best-effort boundary.
- [x] Existing inbound Email durability and device lifecycle behavior is unchanged.
- [x] Focused and affected regression tests pass on authoritative Dev.
- [x] Documentation and TODO evidence are current.

## Completion Evidence

Completed on authoritative Dev on 2026-09-03. The registry covers all 27 current types and marks
nine internal types eligible. The dedicated registry/delivery test passes 6 tests / 322 assertions;
Storage passes 20 / 167; Customer Portal passes 11 / 179; and affected Ticket, SLA, Asset, and user
lifecycle coverage passes 145 / 1,079. Existing inbound Email delivery still uses its specialized
outbox. Human device review is tracked separately in `HR-2026-09-03-004`.
