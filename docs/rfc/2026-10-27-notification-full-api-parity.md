# RFC: Full Notification Browser/API Parity

Status: Approved - Svein Tore, 2026-10-27: "Godkjenner og merge til main".
Date: 2026-10-27
Owner: Codex. Product/release owner and intended human reviewer: Svein Tore.
Human-review checklist: HR-2026-10-27-NOTIFICATION-API (Pending; planned scope).
Coverage: ../plans/2026-10-27-notification-api-coverage-matrix.md and .json.
Decision: ../adr/2026-10-27-notification-api-authority-and-delivery.md (Proposed).
Slices: ../feature-slices/2026-10-27-notification-full-api-parity.md (Draft).

## Context

Svein requested "Notification lag 100% API støtte". Authoritative Dev is reachable
at /var/Projects/tdPSA on branch Dev. Current runtime discovery finds 14
Notification-owned browser rows, five CustomerPortal notification browser rows,
and only three Notification API operations. Livewire bell actions and Workday
reminders require explicit mapping in addition to these route counts.

The API currently lists the caller's stored notifications and marks one/all read.
It does not cover preference discovery/update, opening with exact side effects,
own Web Push devices, self-test, channel settings/tests, administrator device
inventory/revocation, or the portal's narrower notification workflows. Its three
success responses have no typed OpenAPI content. Workday already owns four
reminder/preference API operations and must retain that boundary.

Existing Notification/Workday contributor changes are dirty and must be preserved.
The preceding Knowledge workstream is explicitly awaiting its own RFC approval;
it has no active implementation to interrupt. This request selects Notification
planning; neither workstream is recorded as implemented or RFC-approved.

## Goals

Provide every current supported browser read, write, action, embedded Livewire
workflow and administrative operation through official typed APIs. Include current
actor rights plus explicit token abilities, validated context/options, exact persisted
read-back, shared side effects, Swagger contracts, meaningful tests, operational
documentation and verified employee BookStack publication. A full-coverage claim
requires an accepted row-by-row matrix, not a percentage based on endpoint counts.

## Non-Goals

Implementing the future Talk conversation model in #205, the VAPID activation wizard
in #258, shared scheduler/queue reconciliation #262, new SMS providers, quiet hours,
arbitrary notification broadcasting, notification deletion or new recipient routing.
No new role grants, employee/customer test sends, global enablement, backlog release,
VAPID generation/rotation, worker/scheduler activation, commit/push, Main promotion
or production deployment. A separately authorized controlled test remains possible.

## Current Behavior

Personal preferences use NotificationTypeRegistry and shared NotificationSetting rows.
Workday reminders additionally require current own-employee eligibility and readiness;
coordinator-bound tokens are denied by the Workday boundary. Registry exclusions
and existing defaults remain authoritative. Web Push registration requires readiness
and explicit device consent, refuses another account's endpoint, and returns only
safe public-ID/device metadata. Current-device resolution updates last_seen_at and
therefore remains a POST action.

Channel administration requires notification.manage_channels through native route
authorization. Nextcloud Talk currently stores a connection plus a legacy webhook;
SMS supports only dry_run and existing Contact phone/consent/template rules.
A Web Push self-test queues a generic notification and returns 202, not delivery.
The Talk controller currently calls any successful webhook HTTP response delivered;
#205 explicitly records why this is insufficient proof.

The bell loads at most ten unread copies, currently reports that limited count, and
has mark-read, mark-all, open and Workday snooze actions. Inbound Email opening resolves
the current source but leaves read acknowledgement to an authorized source view.
Ordinary opens mark the notification read. Workday visibility uses live eligibility.
CustomerPortal exposes only CustomerPortalNotification rows and portal preferences;
it must never become an internal notification or channel-administration shortcut.

## Proposed Change

1. Register official Notification routes in Notification/routes.php under the existing
   versioned Sanctum group; retain api.php only as a compatibility loader if needed.
   Preserve current three URLs and supported response fields. Recheck active identity,
   internal/profile access for internal-only operations, ownership and native admin
   permission; token scopes never grant human permissions.
2. Keep notifications.read and notifications.update for legacy own notification reads
   and acknowledgements. Add typed detail, summary and explicit POST open operations.
   Use one shared list/visibility/open/read boundary across controllers and bell.
   GET/detail/summary/context must not mark records read or contact providers.
   Return actual total unread plus the bounded recent list; align the bell count
   through the same query rather than silently counting only the ten loaded items.
   Preserve source-read separation, Workday filtering and other-user isolation.
3. Open returns a validated same-origin authorized navigation target, notification
   read-back and precise acknowledgement status. Reuse native source/target policy;
   never fetch additional restricted source fields through a stored notification.
   Share safe target resolution with browser and bell. Inbound Email opening alone
   does not claim source read; Workday only opens currently eligible own reminders.
4. Add notifications.preferences.read/write for internal context and preferences.
   Return grouped registry labels, descriptions, supported channels, defaults, current
   preferences and safe readiness/capabilities. Validate the complete submitted set
   before transactional persistence, preserve browser replacement/unchecked-checkbox
   semantics for submitted types, and leave omitted types unchanged. Use the same
   action for web/API. No arbitrary notification types or owner selectors.
   Workday remains governed by its own permissions and workday-reminders scopes:
   generic preference writes cannot bypass them; use its existing API/service.
   Personal webhook URLs are write-only; return configured state and allow explicit
   retain/replace/clear without sending the credential URL back in API read-back.
5. Add notifications.devices.read/write and notifications.tests.execute for own
   Web Push device list/register/current/revoke and generic self-test. Reuse request
   validation, readiness, unique endpoint ownership, safeSummary, audit and removal
   actions. Devices are identified by public_id. Endpoint and key inputs are accepted
   only by the authenticated direct subscription workflow, excluded from responses,
   validation echoes, logs, receipts and assistant tool results. API support cannot
   grant browser permission or fabricate a subscription. Current/test requests preserve
   their POST semantics; self-test retains throttle:3,10 plus current user checks.
6. Add notifications.channels.read/write/test and notifications.devices.manage for
   native admin channel inventory/detail/edit context/update/test and cross-user
   device list/revoke. Every operation also requires notification.manage_channels.
   Context supplies bounded existing active Nextcloud choices, SMS templates and
   eligible Contact options through the same native authorization; no new directory
   permission or provider credential access. Driver-specific validation disallows
   unsupported fields/providers. Existing encrypted secret inputs stay write-only;
   summaries expose configured flags, never raw config/secrets or credential URLs.
   Changing an existing enable switch is possible only through this explicit guarded
   write, with expected-current-state protection and sanitized audit.
7. Share test actions with web. Describe outcomes separately: blocked, dry_run,
   queued, provider_accepted, delivery_confirmed where independently evidenced,
   failed and unknown. A Talk webhook HTTP 2xx is provider_accepted/unverified,
   never confirmed Talk conversation delivery; normalize unsafe response bodies and
   exception strings. Reflect truthful wording in the existing web test result.
   Do not implement #205's new destination model under a parity claim.
   Real outbound API tests additionally require a default-off API execution switch
   and explicit test scope; dry_run logging does not require provider activation.
   Do not enable switches or send any live messages while implementing this RFC.
8. Add notifications.portal.read/update/preferences.write for portal list/detail,
   summary/preferences, mark-one/all and explicit open. Reuse the current
   CustomerPortal context resolver and active account/contact/membership checks for
   both bearer and session clients. Keep existing portal own-copy visibility unless
   a documented native rule requires membership-specific filtering; do not invent
   a new audience model. Resolve each source target against current portal scope,
   return portal-safe fields and enforce portal-only preference types/channels.
9. Reuse the four Workday reminder/preference APIs for bell/profile parity, preserving
   explicit own permissions, generation-based snooze and coordinator denial.
   Map the reminder landing page too: expose the rendered own reminder/workday-link
   context via a Workday-owned typed detail if the existing index lacks it, and use
   explicit open acknowledgement for its write side effect. No parallel reminder
   storage, user selector or general notifications scope can grant Workday rights.
10. Require Idempotency-Key on new externally effective test actions and new guarded
    administrative writes; use current-state preconditions for overwrites. Add a
    Notification-owned durable encrypted command receipt/claim for those operations,
    keyed by actor, token, operation and replay key. Store only allowlisted redacted
    results and keyed input fingerprints, never subscription/private/credential input.
    Recheck current actor/token/target access on replay and receipt lookup.
    Keep local mutation/receipt atomic; claim before provider/queue submission.
    Reconcile uncertain outcomes instead of sending again. Existing mark-read actions
    remain naturally idempotent and retain compatibility; generation protects snooze.
11. Publish typed request/success/error schemas, constraints, pagination and required
    scopes for every HTTP operation, including existing endpoints. Match actual
    runtime routes to Swagger exactly and validate real successful responses against
    schemas. Document 401/403/404/409/422/429/503 and truthful test outcomes.

## Impact Analysis

Notification owns queries/actions, devices, channel settings, resources, validation
and receipts. Integration owns the token ability catalog and Swagger infrastructure.
CustomerPortal retains membership/context policy; Workday retains own reminder
eligibility, time and generation semantics. Contact retains phone/consent authority;
Nextcloud owns connection credentials; Email/Ticket/Asset/Storage retain source access
and canonical read synchronization. UserManagement deactivation cleanup remains intact.
No UI redesign; only shared action/query use and necessary truthful result/count wording.

Risks: legacy scope-only callers may fail when their identity lacks native access;
partial preference saves must not occur; bulk read may not affect foreign users or
portal/internal audiences incorrectly; command results must not disclose revoked data;
concurrent registrations must not transfer endpoints; failed/ambiguous tests must not
duplicate notifications. Existing dirty files are dependencies, not a release manifest.

GitHub live read: #257 is closed/completed and must not be reopened or duplicated.
#205, #258 and #262 remain open and separate. #205 has no comments at inspection.
Their future capabilities are excluded; legacy webhook behavior is explicitly
identified and its false delivery claim is corrected only at the result boundary.

## Data And Migration Plan

Plan one additive Notification-owned command/claim table with unique replay identity,
encrypted sanitized result and safe status timestamps. Final schema/file is chosen
during the approved slice after inspecting existing receipt patterns. No notification,
preference, device or queue backfill, role grants, secret migration or VAPID changes.
Run only the exact additive migration on Dev after approval and preflight human-review;
verify native MySQL uniqueness/locking/rollback/encrypted read-back with isolated probes.
Later deployment requires the exact migration, generated Swagger and normal cache
clear; restart affected workers only under separately authorized rollout. No frontend
build expected unless verified source changes require one. Do not run all pending
migrations or activate a worker/full scheduler. Preserve APP_KEY and durable receipts
on rollback; disable new entry points rather than dropping live history.

## Testing Plan

Feature tests: all runtime/browser matrix rows and Livewire actions; fresh token and
native rights, active identity, revoked permission/token, cross-user/device/portal
denial, bounded input/filtering, transactional preferences, registry exclusions and
default values, Workday disabled/eligibility/coordinator/generation behavior, safe
open targets, Email source read separation, Contact consent and SMS dry_run, native
admin driver settings, device registration race/ownership/revocation/offboarding,
read-only no-write behavior, throttling, provider failures and secret canaries.
Exercise idempotency, stale-state conflict, queue/provider ambiguity and denied replay.
Include Notification, relevant CustomerPortal/Workday, API catalog/Swagger,
Nextcloud/Contact, Email/Ticket source sync and UserManagement cleanup regressions.
Run scoped syntax/Pint/whitespace, native DB probes and trusted HTTPS auth/Swagger
read-back. All outbound calls are mocked unless a controlled live test is separately
authorized. Record failures as blockers; do not certify coverage from route existence.

## Documentation Plan

Update Notification API/channels guides and relevant Workday, portal and API-management
sources, including exact menus, prerequisites, steps, expected outcomes and troubleshooting.
Sync affected authoritative Knowledge articles; verify actual BookStack body and employee
access without expanding permissions. Record publication blocker, owner and next action
in TODO when needed. Employee OpenWebUI retrieval is a separate claim.
Maintain matrix, verification report, RFC/ADR/slices, TODO and HR checklist together.
Only completed public-safe behavior is eligible for the website handoff; mark Dev-only
work do not publish pending real production availability.

## Open Questions

No unresolved product choice is required for current-browser parity. Approval of this
concrete RFC is required before Level 3 implementation. This approval does not authorize
#205/#258/#262, Main, production, secret setup or real customer/employee delivery.

## Approval

Approved by Svein Tore on 2026-10-27: "Godkjenner og merge til main".
Implementation and scoped Main promotion are explicitly authorized. Production,
provider activation and real sends remain separate. Manual scenario review is not
claimed complete by this approval.


## Implementation Checkpoint

All five sequential slices are Done On Dev. All 19 original browser route rows,
embedded actions and Workday integration map to verified typed operational APIs.
Evidence: ../plans/2026-10-27-notification-api-verification.md and the coverage matrix.
Thirty Notification HTTP operations / 39 schemas and one related Workday detail
are implemented. The previous Current Behavior section is the dated pre-change baseline.
Human scenario review remains In Review; Svein Tore's explicit Main instruction
authorizes scoped promotion after tests, not production or real delivery activation.

Main promotion complete: PR #336 (f9efc4eee84e16984e4b6c44433ff9749dd8c2d2). Production deployment is not included; the human scenario checklist remains In Review.
