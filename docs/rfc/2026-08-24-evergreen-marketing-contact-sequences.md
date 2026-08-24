# RFC: Evergreen Marketing Contact Sequences

Status: Approved
Date: 2026-08-24
Owner: Svein Tore Ramstad / Codex

## Context

Marketing currently treats sequence completion as a campaign-level event. `Stop when sequence is
complete` marks the whole campaign `completed`, while `Repeat sequence` creates a new cycle and
queues the same campaign-email records for the same audience again.

That lifecycle is wrong for the intended nurture workflow. Completion belongs to each contact, not
to the campaign. A contact should receive the ordered campaign emails once, become caught up, and
remain enrolled. New contacts should start at the first email when the campaign uses that policy.
If a technician later appends another campaign email, both previously caught-up contacts and newer
contacts should receive that new step at the appropriate future campaign schedule.

The strongest product rule is that one contact must never be sent the same campaign-email record
twice. Sending similar content again must require a new campaign-email record with a new identity.

The current implementation does not provide that guarantee:

- Recipient matching is limited to `current_cycle`, and the database uniqueness key includes
  `cycle_number`.
- Repeat deliberately queues the same campaign email in a later cycle.
- Completed campaigns are excluded from recipient synchronization and cannot be extended through
  the technician or API campaign-email flows.
- All sequence steps are queued in advance, so a later step can become due even if an earlier step
  failed.
- A new contact or a newly appended email can become due immediately instead of at the next
  calendar-aligned campaign occurrence.
- SMTP transmission is not protected by a durable atomic recipient claim, so overlapping jobs or a
  process failure after provider acceptance but before the local `sent` update can invite a
  duplicate transmission.

This is a Level 3 change because it changes Marketing data semantics, queue processing, schedule
calculation, API behavior, migration/backfill behavior, and user-visible campaign lifecycle.

## Goals

- Make sequence progress contact-specific.
- Keep an approved campaign active but idle when all current contacts are caught up.
- For `start_at_first_email`, enroll a newly eligible contact at email 1 on the next configured
  campaign occurrence, then advance by one ordered email on each later occurrence.
- Send only the next unsent active campaign email for a contact after the preceding step has a
  confirmed successful send.
- When a new active campaign email is appended, make it the next missing step for every eligible
  contact that is already caught up, as well as for contacts still moving through the sequence.
- Enforce lifetime no-resend semantics for the pair of campaign-email identity and recipient
  identity, independent of cycle, list refresh, list-member ID, overlapping lists, email casing, or
  repeated scheduler execution.
- Remove automatic sequence repeat from technician and API write behavior.
- Preserve all historical recipient, tracking, and repeat-cycle records without deleting or
  rewriting evidence.
- Prefer at-most-once automatic SMTP transmission when delivery outcome is ambiguous. An uncertain
  delivery must require review and must never be replayed automatically.
- Keep suppression, opt-out, consent, quiet hours, batching, approval, pause, tracking, and sender
  account protections intact.

## Non-Goals

- Do not infer that identical text in two different campaign-email records is the same email.
- Do not add a visual automation/workflow builder.
- Do not change reusable Email template ownership or campaign snapshot ownership.
- Do not change unsubscribe, consent, suppression, open tracking, or click tracking policy.
- Do not delete duplicate historical sends that already occurred.
- Do not claim exactly-once delivery from SMTP. The enforceable guarantee is that Nexum will not
  automatically transmit a claimed or previously transmitted campaign-email identity again to the
  same recipient identity.
- Do not automatically reactivate every legacy completed campaign during deployment.

## Current Behavior

`MarketingCampaign::COMPLETION_BEHAVIORS` exposes `stop` and `repeat`.
`AdvanceMarketingCampaignLifecycle` either marks the campaign `completed` or increments
`current_cycle` and synchronizes the same emails again. Recipient lookup and uniqueness include the
cycle number. The due-send job processes only `approved` and `active` campaigns.

Adding an active email already queues current eligible members when the campaign is approved or
active. However, the technician and API controllers reject that action after campaign completion.
This means the useful append behavior exists only while the campaign has not reached its current
completion policy.

The send job reads pending rows, calls SMTP, and marks the row sent afterward. It does not first
make a durable exclusive claim. Scheduler-level `withoutOverlapping()` protects only event
dispatch; it does not make queued jobs or individual recipient transmission exclusive.

## Proposed Change

### 1. Campaign And Contact Lifecycle

- An approved campaign remains `active` after all current contacts are caught up.
- `caught up` is a derived contact state: the contact has no eligible active campaign email that is
  missing a confirmed or transmission-claimed delivery record.
- `caught up` is also exposed as a derived campaign summary, not a terminal campaign status.
- The campaign does not automatically become `completed` and does not create a new cycle.
- Pausing remains the explicit way to stop future processing. Legacy completed campaigns remain
  inert until a technician explicitly continues the sequence or adds a new active email.
- Adding a new active email to a legacy completed campaign is an explicit continuation action. It
  returns the campaign to active processing but must not queue any previously transmitted email.
- `Repeat sequence`, repeat interval controls, and cycle-oriented wording are removed from the
  normal UI. API writes requesting repeat are rejected with a clear validation error rather than
  silently changing meaning.

### 2. Stable Recipient Identity And Lifetime No-Resend Rule

Recipient matching must use stable identity evidence in this order:

1. First-class Contact identity when present.
2. Linked legacy client-user identity while compatibility records remain.
3. Normalized destination email as the final identity and cross-record duplicate guard.

`marketing_list_member_id` is not a durable delivery identity because list refresh can replace list
member rows. Before a recipient is created or claimed, Marketing must search all historical cycles
for the same campaign email and any matching Contact, legacy client user, or normalized email.

Implementation must add a durable delivery guard or equivalent database-backed invariant. It must
protect both stable person identity and the normalized destination mailbox. The exact schema and
claim state machine will be recorded in an ADR before implementation. Application-only
`firstOrCreate` checks are not sufficient.

Existing `cycle_number` values remain historical metadata. New processing does not use cycle to
permit another send.

### 3. Ordered Progression And Calendar-Aligned Scheduling

- Marketing selects only the next missing active campaign email for each eligible contact.
- A later step is not eligible until the preceding applicable step is confirmed sent.
- A pre-SMTP failure keeps the contact on the same step and may be retried only through a safe
  pre-transmission path.
- Suppression or loss of consent prevents transmission and progression while the contact is
  ineligible.
- An unresolved SMTP outcome blocks that contact at the current step and requires manual review.
  No later step and no automatic retry may bypass it.
- `start_at_first_email` remains the default and means exactly that, even when the campaign has been
  active for a long time.
- `join_current_step` may remain as an explicit newsletter policy, but it still uses lifetime
  no-resend and ordered progression from the selected current step.
- The first newly eligible step is scheduled at the next configured campaign occurrence at or
  after the enrollment/append time, not simply at `now()` and not at an already elapsed historical
  due time.
- After a confirmed send, the next step uses the next calendar-aligned campaign occurrence plus its
  optional per-email delay and recipient batch offset.
- A newly appended email for a caught-up contact is therefore sent at the next campaign occurrence.

### 4. At-Most-Once Transmission Claim

Before SMTP, the job must:

1. Resolve account, provider binding, content, consent, suppression, and other checks that can fail
   safely before transmission.
2. In a database transaction, lock the campaign-email delivery identity, reject any previously
   sent, claimed, or outcome-unknown match across all cycles, reserve a stable RFC Message-ID, and
   atomically move the selected row from pending to a transmission-claimed state.
3. Pass the reserved Message-ID to `SmtpAccountMailer`.
4. Mark the claim sent only after confirmed provider acceptance.
5. Mark an exception after transmission starts as outcome unknown and never requeue it
   automatically.

Two workers may inspect the same due work, but only one may acquire the durable claim. A process
failure after the claim can cause a missed delivery that needs review; it must not create an
automatic resend invitation. This tradeoff follows the explicit no-duplicate product rule.

### 5. Existing Data And Compatibility

The migration must be additive and begin with a read-only preflight that reports:

- Campaign counts by status and completion behavior.
- Current-cycle and next-cycle state.
- Recipient counts by status and cycle.
- Identity clusters where the same campaign email already has more than one recipient row for a
  matching Contact, client user, or normalized email.
- Pending later-cycle rows that would resend a previously transmitted email.
- Rows with an uncertain or incomplete delivery state.

Backfill rules:

- Preserve every historical row and tracking/event reference.
- Treat confirmed sent rows and any transmission-started or outcome-unknown row as consuming the
  lifetime delivery guard.
- Do not treat a suppression or a failure proven to occur before transmission as received.
- Mark pending rows that would repeat an already consumed campaign-email/recipient identity as
  duplicate-skipped or cancelled without SMTP.
- Resolve ambiguous identity clusters conservatively and fail the migration or deployment gate
  before sending if a safe canonical guard cannot be established.
- Convert repeat configuration to ongoing non-repeat semantics without creating new recipients.
- Leave legacy completed campaigns inert until an explicit continuation action.

Migration and deploy order must pause Marketing dispatch/workers, take and verify a backup, run the
preflight, apply the additive migration/backfill, read back guards and duplicate-skipped counts, run
focused tests, then resume processing. The migration itself must not send or queue email.

### 6. Technician UI And API

Technician UI:

- Remove `When Sequence Completes`, `Repeat`, and `Repeat Unit` controls.
- Explain the ongoing behavior: each contact receives every campaign email once; caught-up contacts
  wait for a new email; new contacts follow the selected enrollment policy.
- Show useful derived counts such as `In progress`, `Caught up`, `Blocked/review`, and `Next due`.
- Allow a new email to be added to a legacy completed campaign with an explicit notice that this
  continues the sequence without resending previous emails.
- Surface unresolved delivery outcomes for review. Do not provide a blind resend action.

API:

- Remove repeat from accepted create/update/schedule values.
- Reject `completion_behavior=repeat` with a clear validation response.
- Preserve legacy lifecycle fields in read resources during a compatibility period, mark them
  deprecated, and expose the derived ongoing/caught-up state.
- Apply the same continuation, stable identity, progression, and no-resend rules to API-created
  contacts, list refreshes, campaign emails, approval, and due-send requests.

## Impact Analysis

Affected domain: Marketing.

Cross-module dependencies:

- Email: stable Message-ID generation, provider binding checks, and the SMTP unresolved-outcome
  boundary are reused; Email transport ownership does not move.
- Contact and Clients: stable recipient identity and legacy `client_users` compatibility are read,
  but identity ownership does not move.
- Integration API: existing Marketing API scope names remain; resource and validation behavior
  changes must be documented.
- Signal/Sales: historical tracking and engagement references must remain intact.

Affected runtime surfaces:

- Marketing campaign model and lifecycle action.
- Recipient synchronization and schedule calculation.
- Due-send queued job and console/scheduled dispatch behavior.
- Technician campaign create/show/schedule/email flows.
- Marketing API campaign resources, validation, and email flows.
- Recipient schema, migration/backfill, tests, README, Knowledge documentation, TODO, and human
  review.

Permissions remain unchanged. No route ownership change is expected. Queue workers and the external
Laravel scheduler are deployment dependencies.

Primary risks:

- Accidentally replaying historical repeat-cycle rows during migration.
- Treating a refreshed list-member ID as a new person.
- Advancing a contact after a failed or uncertain earlier step.
- Misaligning weekly/monthly calendar occurrences around timezone and month boundaries.
- Breaking existing API clients that still write repeat fields.
- At-most-once handling can leave an ambiguous delivery unsent; this is intentional and must be
  visible for review.

## Data And Migration Plan

- Add new migration files; never edit the already-applied 2026-07 lifecycle migration.
- Add durable delivery identity/claim state needed by the accepted ADR.
- Add indexes for due selection and identity guards only after preflight/backfill proves them safe.
- Backfill in bounded, idempotent batches and retain sanitized counts/checkpoints.
- Preserve `cycle_number`, repeat timestamps, and completed timestamps as historical evidence even
  when no longer used to authorize sending.
- Do not delete recipient rows or tracking events.
- Provide a dry-run/preflight command and a separate explicit apply path for any data repair that
  cannot be completed safely inside the schema migration.
- Rollback may remove new unused structures only before new claims are written. Once live claims
  exist, rollback must preserve delivery guards to avoid duplicate sends.

## Feature Slices

### Slice 1: Delivery Invariant, ADR, Preflight, And Compatibility

- Record the at-most-once delivery identity/claim ADR.
- Implement read-only preflight and additive migration/backfill.
- Enforce lifetime delivery guards across Contact, legacy client user, and normalized email.
- Preserve and classify all repeat-cycle history without sending.

### Slice 2: Contact Progression And Calendar Scheduling

- Replace campaign completion/repeat advancement with derived caught-up state.
- Select only the next missing step per contact.
- Align enrollment, progression, and appended emails to the next configured campaign occurrence.
- Block progression on failure, suppression, or unresolved delivery outcome.
- Implement durable transmission claim and no-automatic-replay behavior.

### Slice 3: Technician/API Surfaces, Documentation, And Review

- Remove repeat controls and reject repeat API writes.
- Allow explicit continuation of legacy completed campaigns without replay.
- Add caught-up/in-progress/review summaries.
- Update README, Knowledge, API docs, TODO, tests, and human-review tracking.

## Testing Plan

- A Contact receives each `marketing_campaign_email_id` at most once across repeated job runs and
  all historical cycles.
- A newly eligible Contact starts at email 1 under `start_at_first_email`; existing caught-up
  Contacts do not receive any old email again.
- Weekly scheduling uses the next configured weekday/time, not the time the Contact was added.
- A newly appended email after all Contacts are caught up is sent once to both existing and newer
  eligible Contacts at their correct next occurrence.
- Email 2 cannot send before email 1 is confirmed sent.
- Pre-SMTP failure, suppression, and unresolved SMTP outcome each produce the correct non-advancing
  state.
- List refresh, changed list-member ID, overlapping lists, Contact/client-user compatibility, and
  email casing do not create a duplicate delivery.
- Two overlapping jobs produce one durable claim and at most one SMTP call.
- A process interruption after claim does not make the row automatically sendable again.
- Legacy repeat/completed migration preserves history, cancels only unsafe pending duplicates, and
  queues no email during migration.
- Technician and API flows can append to an idle ongoing campaign and explicitly continue a legacy
  completed campaign without replay.
- Repeat API writes are rejected and resource compatibility fields remain truthful.
- Existing suppression, unsubscribe, approval, pause, batching, tracking, and provider-binding
  regression suites continue to pass.

## Documentation Plan

- Update `app/Modules/Marketing/README.md`.
- Update Marketing Knowledge documentation and mark it for BookStack synchronization.
- Update Integration API documentation for deprecated repeat fields and new derived state.
- Update `docs/TODO.md` with the approved feature slices and final status.
- Add a new `docs/human-review.md` entry covering schedule alignment, no-resend behavior, legacy
  continuation, duplicate/uncertain state, API behavior, and migration readback.
- Record deployment commands and the Marketing scheduler/queue operational checks.

## Open Questions

None. The requested product behavior resolves the key choices: completion is per Contact, the
campaign remains ongoing, newly appended emails extend the sequence, and duplicate prevention takes
priority over automatic retry after an uncertain SMTP outcome.

## Approval

Approved by Svein Tore Ramstad in conversation on 2026-08-24 with the explicit instruction to
implement and fix the behavior on the authoritative Dev server and Dev branch.
