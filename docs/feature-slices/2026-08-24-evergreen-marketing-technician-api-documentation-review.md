# Feature Slice: Evergreen Marketing Technician API Documentation And Review

Status: Done / Scheduler Verification And Human Review Pending
Date: 2026-08-24
Level: 3
Parent: `../rfc/2026-08-24-evergreen-marketing-contact-sequences.md` (Slice 3)
Owner: Svein Tore Ramstad / Codex
Related ADR: `../adr/2026-08-24-marketing-at-most-once-delivery-identity-claims.md`

## Goal

Expose the approved evergreen contact-sequence behavior honestly in technician and API surfaces,
document the operational contract, and prepare the complete Level 3 change for named human review.

## User-Visible Behavior

- Campaign scheduling no longer offers `When Sequence Completes`, `Repeat`, or `Repeat Unit`.
- The page explains that each contact receives each campaign email once, caught-up contacts wait for
  a new email, and new contacts follow the selected enrollment policy.
- Technicians can see `In progress`, `Caught up`, `Blocked/review`, and `Next due` summaries.
- Adding an active email to a legacy completed campaign is an explicit continuation action. It
  returns the campaign to active processing without recreating or resending old steps.
- Uncertain outcomes are visible for investigation, but there is no blind resend action.
- API clients receive a clear validation error for `completion_behavior=repeat` while legacy repeat
  fields remain truthful, deprecated read compatibility during the documented transition.

## Scope

- Remove repeat/completion controls, cycle-oriented wording, and misleading completed-state behavior
  from technician create/show/schedule/email flows.
- Add concise evergreen behavior guidance and derived campaign/contact summaries.
- Add explicit legacy completed-campaign continuation through a new active campaign email, guarded by
  existing Marketing authorization and the delivery invariant.
- Reject repeat in technician and API writes rather than silently mapping it to ongoing behavior.
- Preserve deprecated legacy lifecycle fields in API reads for the compatibility period and expose
  derived ongoing/caught-up/review state.
- Apply identical append, continuation, stable-identity, progression, and no-resend rules to
  technician and API-created changes.
- Update Marketing README and Knowledge documentation, Integration API documentation, TODO status,
  deploy/operations notes, and BookStack synchronization status.
- Add/update one durable `docs/human-review.md` entry for the complete Level 3 update.

## Out Of Scope

- Changing the ledger/key schema, claim state machine, or calendar algorithm established in Slices 1
  and 2.
- Automatic reactivation of all legacy completed campaigns.
- A generic workflow builder, content-equivalence detection, or automatic uncertain-delivery retry.
- New permission names, route ownership, consent policy, suppression policy, or Email transport
  ownership.
- Promotion, merge, deployment to Main, production migration, or production email sending.

## Data Touched

- Marketing campaign controller/request validation, Blade views, and existing module routes.
- Marketing API campaign validation/resources/controllers and Integration ability documentation;
  existing ability names remain unchanged.
- Derived reads from campaigns, active campaign emails, recipients, deliveries, identity keys, and
  due/review states.
- Explicit legacy completed-campaign status continuation only when a new active email is accepted.
- `app/Modules/Marketing/README.md`, Marketing Knowledge docs, Integration API Knowledge/docs,
  `docs/TODO.md`, and `docs/human-review.md`.

Historical repeat/cycle fields, recipient rows, tracking, and events are not deleted or rewritten by
these surfaces.

## Permissions

Reuse existing Marketing technician route middleware, campaign manage/approval authority, and
Integration API abilities. Summary and review details must not widen list, Contact, campaign,
recipient, or mailbox visibility. Explicit continuation requires the same authorization as adding
and activating a campaign email; read compatibility never grants write compatibility.

## Tests

- Technician schedule validation and rendering contain no repeat controls or repeat write path.
- `completion_behavior=repeat` receives a clear validation error in technician and API requests.
- Legacy read fields remain present/deprecated and derived ongoing/caught-up/review state is truthful.
- A campaign with all current contacts caught up remains active and shows correct zero/summary state.
- Appending an email to an ongoing campaign schedules it once for eligible contacts.
- Explicitly appending to a legacy completed campaign activates only the new continuation path and
  does not recreate old recipients or claims.
- In-progress, caught-up, blocked/review, and next-due counts follow the same query contract as the
  sender, including suppression and uncertain outcomes.
- No UI/API action can blind-resend `claimed`, `provider_write_started`, `sent`,
  `outcome_unknown`, or `duplicate_skipped` identities.
- Existing campaign approval, pause, consent, suppression, tracking, sender-account, and API-scope
  regressions continue to pass.
- Browser checks cover schedule copy, responsive layout, continuation warning, summaries, and
  outcome-review presentation.

## Documentation

- Update `app/Modules/Marketing/README.md` with evergreen lifecycle, identity, schedule, claim,
  migration, scheduler/worker, and unresolved-outcome operations.
- Update Marketing Knowledge documentation without repeating its page title as a first heading and
  mark it for BookStack synchronization.
- Update Integration API documentation for rejected repeat writes, deprecated reads, derived state,
  and continuation behavior.
- Update `docs/TODO.md` with the approved slices and verified status.
- Create/update one human-review entry covering no-resend, schedule alignment, new-contact start,
  appended email behavior, legacy continuation, API compatibility, uncertain outcomes, preflight/
  migration readback, queue/scheduler health, and rollback limitations.

## Completion Evidence And Remaining Gates

- The complete isolated Marketing suite passed on authoritative Dev with 65 tests and 802
  assertions, including technician/API validation, derived summaries, legacy continuation, and
  lifetime no-resend regressions.
- Read-only browser QA covered campaign overview, detail, and create surfaces. Repeat controls were
  absent; 768-pixel and 390-pixel widths had no horizontal overflow; and the browser console was
  clean. Legacy completed continuation remains automated-test evidence only because the browser QA
  intentionally made no state-changing continuation.
- Marketing README/Knowledge source, Integration API documentation, TODO status, deployment notes,
  and `HR-2026-08-24-002` are updated. Local `knowledge:sync-docs --module=Marketing` processed one
  chapter and one article with zero skipped and reported module `Marketing`; no BookStack push is
  claimed.
- Implementation/documentation and default-queue execution capacity are verified. Dev has three
  active `email,default` workers rooted at `/var/Projects/tdPSA`; Marketing uses the default queue.
  The failed-jobs table has zero Marketing failures and two unrelated failures, with no payload or
  failure details exposed by the read-only audit.
- Automatic schedule dispatch is not verified. No tdPSA `schedule:run` or `schedule:work` runner was
  found in accessible cron, systemd, or process sources; the unavailable root crontab remains a
  visibility gap. Do not rely on automatic due processing until the external scheduler is verified.
- `HR-2026-08-24-002` remains Pending. No named reviewer has completed the remaining controlled send,
  API, continuation, review-outcome, or runtime checks.

## Done Criteria

- [x] Technician repeat controls and repeat writes are removed with accurate evergreen guidance.
- [x] API repeat writes are rejected and compatibility reads remain documented and truthful.
- [x] Derived progress/review/next-due summaries use the runtime source of truth.
- [x] Explicit legacy continuation appends only new work and never replays a guarded email.
- [x] Marketing README, Knowledge, Integration API docs, TODO, and deploy operations are updated.
- [x] Focused controller/API/feature tests and affected regression suites pass on authoritative Dev.
- [x] Read-only browser QA verifies the overview/detail/create surfaces and exposes no unfinished
  repeat control.
- [x] Preflight and migration readback are checked on Dev with sanitized evidence.
- [x] Default queue-worker health and failed-job state are checked: three active `email,default`
  workers, zero Marketing failures, and two unrelated failures.
- [ ] Verify the external tdPSA scheduler and its sanitized runtime evidence; no runner was found in
  accessible sources, and the root crontab was unavailable.
- [x] The large update has one open human-review ID with all required manual checks; automated tests
  do not mark it Reviewed.
- [x] No Main promotion, production migration, or external campaign send occurs in this slice.
- [x] `git diff --check` passes for the complete change.
