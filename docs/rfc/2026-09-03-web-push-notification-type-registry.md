# RFC: Web Push Notification Type Registry And Eligible Event Coverage

Status: Done On Dev
Date: 2026-09-03
Owner: Svein / Codex
Related Issue: [GitHub Issue #257](https://github.com/SveinT83/Nexum-PSA/issues/257)
Parent RFC: `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`
Architecture: `docs/adr/2026-07-23-notification-owned-web-push-channel.md`
Feature Slice 1: `docs/feature-slices/2026-09-03-web-push-notification-registry-delivery.md`
Feature Slice 2: `docs/feature-slices/2026-09-03-web-push-preference-ui-documentation.md`

## Context

The approved Web Push foundation provides VAPID configuration, explicit browser/device opt-in,
privacy-safe subscription management, the shared PWA service worker, and implemented delivery for
inbound Email/customer replies. Supplier-order import notifications later added two more Web Push
payloads. The personal preference page nevertheless exposes one flat type list while Web Push
eligibility is maintained in separate constants and individual notification classes.

The current `NotificationSetting::TYPES` catalogue contains 27 keys. Only four keys are declared
Web Push-capable, and a new type can be added without declaring its audience, group, description,
supported channels, Web Push eligibility, payload implementation, or exclusion reason. Several
catalogue keys are customer-portal events or placeholders with no current internal emitter. This
makes incomplete coverage easy to introduce and makes the preference UI difficult to understand.

GitHub Issue #257 requires Web Push to become an explicit first-class opt-in channel for every
eligible current internal notification type while retaining current recipient authorization,
work/client scope, data minimization, default-off behavior, and best-effort delivery. This is Level
3 work because the delivery contract affects Notification plus existing Ticket, Asset, Storage,
and UserManagement boundaries. It does not create a new domain or change domain ownership.

## Goals

- Make one Notification-owned registry authoritative for every preference type and supported
  delivery channel.
- Require every registered type to declare a stable key, English label, description, preference
  group, audience, channel support, defaults, Web Push eligibility, and an exclusion reason when
  Web Push is unavailable.
- Expose independent default-off Web Push preferences for every current implemented and safe
  internal event.
- Prevent a new notification type from silently omitting Web Push policy or preference coverage.
- Keep customer-portal events in the same authoritative registry while excluding them from the
  internal profile surface and internal Web Push until a separate customer-facing slice is
  approved.
- Queue Web Push after the authoritative event notification path so provider latency or failure
  cannot suppress, delay, duplicate, or roll back in-app persistence.
- Recheck the active user, current preference, required permission, target existence, and target
  authorization immediately before delivery.
- Keep lock-screen payloads to a safe summary and a same-origin route that the recipient is
  currently authorized to open.
- Group and describe preferences in a responsive, keyboard-accessible Bootstrap UI.
- Preserve existing device opt-in, VAPID readiness, service-worker, read-sync, inbound Email
  durability, queue, and subscription-lifecycle behavior.

## Non-Goals

- Do not enable Web Push by default for any business event or registered device.
- Do not add Customer Portal Web Push, customer browser subscriptions, native applications,
  offline writes, silent push, or a second service worker.
- Do not add new notification emitters for catalogue placeholders merely to make them eligible.
- Do not expose invitation tokens, message bodies, comment text, client/customer names, email
  addresses, attachment names, provider data, raw alert text, subscription material, or secrets.
- Do not change Ticket, Asset, Storage, Email, or Customer Portal record-access ownership.
- Do not add a database migration, API endpoint, permission, dedicated push worker, or production
  provider configuration flow.
- Do not broaden per-type preview support beyond the separately approved inbound Email preview
  contract.

## Current Behavior

- `NotificationSetting::TYPES` is a label map, while Web Push support and preview support are
  separate allowlists.
- The internal preference controller validates against the type label map and silently forces
  forged Web Push values off for types outside the allowlist.
- The preference view renders all internal and portal keys in one wide table and shows a dash for
  unsupported Web Push types.
- `ticket_customer_reply_received`, `inbound_email_received`,
  `storage_purchase_import_exception`, and `storage_purchase_import_digest` have implemented Web
  Push delivery.
- `ticket_assigned`, `ticket_status_changed`, `ticket_comment_added`, `ticket_sla_warning`, and
  `asset_alert` have active internal notification classes and authorized in-app routes, but no Web
  Push payload or preference control.
- `ticket_created`, `ticket_updated`, `asset_alert_resolved`, and `system_announcement` have no
  current complete internal emitter/payload contract. `invitation_sent` is an account-security
  email flow. Portal-prefixed keys are customer-facing events.
- Generic Laravel notifications deliver channels in-process. The inbound Email path has a durable
  canonical/outbox boundary, while generic and supplier-order Web Push paths do not share one
  reusable queued best-effort boundary.

## Proposed Change

### Authoritative registry

Add a Notification-owned registry/service whose entry for every type contains:

- stable type key;
- English label and short description;
- preference group and audience (`internal` or `customer_portal`);
- supported channels and per-type defaults;
- Web Push state (`eligible` or `excluded`) and a required exclusion reason;
- whether limited preview is supported;
- required permission and target kind for Web Push delivery; and
- the notification class/contract responsible for safe payload and target authorization when the
  type is eligible.

`NotificationSetting` remains the persistence model, but its type list, labels, defaults, channel
support, internal preference groups, and validation derive from the registry. Compatibility
constants may remain temporarily as derived values if needed by existing callers; they must not be
independent sources of truth.

Automated completeness coverage fails when a registry entry lacks required metadata, when an
eligible Web Push type lacks a payload/authorization contract, when an excluded type lacks a
reason, or when a notification class requests an undeclared type.

### Initial eligibility catalogue

Keep the four currently supported types eligible. Add Web Push eligibility for these implemented
internal types:

- Ticket assigned to you.
- Ticket status changed.
- Comment added on Ticket.
- Ticket SLA warning.
- Asset alert.

The following remain explicitly excluded:

- `ticket_created`, `ticket_updated`, `asset_alert_resolved`, and `system_announcement`: no current
  complete internal emitter/payload contract.
- `invitation_sent`: account-security flow whose invitation data must remain email-only.
- every `portal_*` type: customer-facing audience outside the approved internal-device boundary.

A later implementation may move an excluded type to eligible only by adding its emitter, safe
payload, target authorization, preference, tests, and documentation in the same change.

### Queued best-effort delivery

Add a Notification-owned internal Web Push contract and a custom channel that queues a bounded job
on the existing default queue instead of contacting the browser provider inline. The job resolves
the current internal user, requires Active non-system state, re-reads the current per-type Web Push
preference, verifies the registry entry, checks the required domain permission and exact target,
and then calls the existing vendor transport. It never falls back to another user or target.

Queue-dispatch failure and provider failure are sanitized and contained. They do not change or
delete the authoritative database notification, re-run the source action, or retry source-domain
persistence. Temporary transport retry remains bounded by the job policy; expired subscriptions
continue through the existing audited cleanup. The existing durable inbound Email outbox remains
unchanged because it has stricter source and recipient attestation.

Move the two supplier-order notification classes from direct vendor delivery to this reusable
queued boundary. The five newly eligible Ticket/Asset classes implement the same contract. Each
payload uses a generic English title/body, a stable non-sensitive tag, and a same-origin authorized
route. Ticket subjects, comments, client identity, hostnames, raw RMM/provider text, supplier names,
order references, and import error contents stay out of Web Push payloads.

### Preference UI

Replace the flat wide matrix for internal users with compact Bootstrap groups such as Tickets,
Email, Assets and monitoring, Storage, and System. Each event shows its label, short description,
and supported channel switches with associated labels. Unsupported channels show a concise reason,
not an unexplained dash. Portal-only types do not appear on the internal profile page and continue
through the existing portal preference surface.

The layout must remain usable without horizontal page overflow at 390 px, preserve visible focus,
use native labeled inputs, and keep one Save preferences action. The server remains authoritative:
forged unsupported channel values are stored as disabled.

## Impact Analysis

- **Notification:** registry, settings model/controller/view, queued channel/job, payload contract,
  documentation, and tests.
- **Ticket:** existing notification classes adopt safe Web Push payload/authorization contracts;
  Ticket remains owner of `ticket.view` and Ticket visibility.
- **Asset:** the existing alert notification adopts the same contract; Asset remains owner of
  `asset.view` and record visibility.
- **Storage:** existing supplier-order Web Push moves behind the reusable queued best-effort
  boundary without changing source alerts or recipients.
- **Email:** no inbound Email delivery or outbox behavior changes; its two types are registered as
  already implemented.
- **Customer Portal:** its types are explicitly classified and excluded from internal Web Push;
  portal delivery behavior does not change.
- **UserManagement:** the current internal User remains subscription owner; active/disabled/system
  state is rechecked before delivery.
- **Permissions:** no new permissions. Existing `ticket.view`, `asset.view`, Storage access, and
  Notification device permissions remain authoritative.
- **Routes/API:** no new route or API contract. Push links reuse existing guarded routes.
- **Queue/scheduler:** new generic events use the existing default queue; no dedicated worker or
  scheduler entry is introduced. Deployment must restart long-lived workers.
- **PWA/service worker:** no behavior change expected; existing same-origin click handling is
  regression-tested.
- **Side effects:** existing explicit preference rows remain valid. Newly eligible types stay off
  until each user opts in.

## Data And Migration Plan

No schema migration or backfill is required. Existing `notification_settings` rows remain
authoritative. Registry defaults apply only when a user has no explicit row. New Web Push-eligible
types still default to `web_push_enabled=false`.

Rollback removes the new queued channel usage and returns the affected types to excluded status.
Existing device subscriptions and preference rows remain valid and are not deleted.

## Testing Plan

- Registry unit tests cover completeness, unique keys, required descriptions/groups/audiences,
  channel declarations, eligible payload contracts, and mandatory exclusion reasons.
- Feature tests prove every eligible internal type appears once with an independently labeled Web
  Push control and every portal/excluded type remains unavailable to forged internal requests.
- Preference tests cover enable, disable, persistence, defaults, and opt-out immediately before a
  queued delivery.
- Delivery tests cover database/in-app persistence before queueing, active-user and system-user
  checks, permission and exact-target authorization, missing/deleted targets, safe same-origin
  links, minimal payload fields, bounded retry, expired subscriptions, and contained provider
  failure.
- Ticket, Asset, Storage, inbound Email, subscription/device, read-sync, service-worker, and
  UserManagement cleanup regression tests remain green.
- Fix or explicitly resolve the current HTTP 419 test baseline before calling Issue #257 complete;
  a failing preference/device POST suite is not acceptable completion evidence.
- Human review verifies desktop keyboard use, 390 px mobile layout, opt-in/out for representative
  Ticket/Asset/Storage events, safe lock-screen content, unauthorized/deleted-target suppression,
  and no duplicate or rolled-back in-app notification after push failure.

## Documentation Plan

- Update `app/Modules/Notification/Docs/knowledge/notification-channels.md` with the registry,
  supported/excluded catalogue, payload policy, and best-effort queue behavior.
- Update affected Ticket, Asset, and Storage Knowledge documentation only where their user-visible
  notification behavior changes.
- Update `docs/TODO.md`, both Feature Slices, and a new `docs/human-review.md` entry during
  implementation and handoff.
- Reconcile GitHub Issue #257 with the verified Dev outcome. BookStack sync remains an operational
  handoff action.

## Open Questions

None. The Issue acceptance criteria, accepted Web Push ADR, existing permissions, and conservative
exclusion policy provide enough direction. Any later Customer Portal Web Push or new emitter needs
separate approval.

## Approval

Approved by Svein on 2026-09-03 with the instruction to build both Feature Slices, update the
documentation, mark the work complete, and close GitHub Issue #257 after verified completion.

## Implementation Result

Completed on authoritative Dev on 2026-09-03.

- `NotificationTypeRegistry` is the single policy source for all 27 current notification types,
  including labels, descriptions, groups, audiences, channel defaults, eligibility, required
  permission/target metadata, and explicit exclusion reasons.
- Nine internal types are Web Push-eligible: Ticket assignment, status, comment, customer reply,
  inbound Email, SLA warning, Asset alert, supplier-import exception, and supplier-import digest.
- Seven ordinary Ticket, Asset, and Storage types use the reusable queued best-effort boundary.
  The two inbound Email types retain their stricter canonical/outbox delivery path.
- Delivery rechecks the active non-system user, current preference, registry policy, permission,
  exact target, and current visibility before invoking the existing provider transport.
- Generic payloads contain no Ticket subject/comment/client identity, hostname/raw alert, supplier
  identity/order reference, import error detail, Email content, credentials, or subscription data.
- Profile > Notifications now renders compact domain groups with descriptions, associated native
  switch labels, explanatory unavailable states, no portal-only events, and responsive Bootstrap
  columns instead of the previous wide matrix.
- No migration, new permission, route, API, scheduler, service worker, or provider setting was
  introduced. Deployment requires cache clearing and a restart of long-lived default workers.

## Verification Result

- Registry/delivery/UI contract: 6 tests / 322 assertions.
- Notification settings, Web Push foundation, and inbound Email Web Push focused coverage: all
  Issue #257-scoped tests pass.
- Storage supplier-import regression: 20 tests / 167 assertions.
- Customer Portal notification regression: 11 tests / 179 assertions.
- Affected Ticket, SLA, Asset, and user lifecycle regression: 145 tests / 1,079 assertions.
- Scoped PHP syntax, Pint, and compiled Blade view verification pass.
- The complete Notification directory currently reports 118 passing and two unrelated tracked-clean
  Email durability-test failures caused by concurrent Email/test-bootstrap work. They do not touch
  the Issue #257 implementation and are not concealed as passing evidence.

Human browser/device review remains Pending in `HR-2026-09-03-004`. It blocks Main promotion and
production release of this Level 3 change, but does not reopen the completed Dev implementation or
GitHub Issue #257.
