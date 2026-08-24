# Feature Slice: Evergreen Marketing Contact Progression And Calendar Scheduling

Status: Done / Scheduler Verification And Human Review Pending
Date: 2026-08-24
Level: 3
Parent: `../rfc/2026-08-24-evergreen-marketing-contact-sequences.md` (Slice 2)
Owner: Svein Tore Ramstad / Codex
Related ADR: `../adr/2026-08-24-marketing-at-most-once-delivery-identity-claims.md`

## Goal

Replace campaign-wide completion/repeat advancement with contact-specific ordered progress and
calendar-aligned scheduling, then enforce the durable claim state machine at the SMTP boundary.

## User-Visible Behavior

- An approved campaign remains active but idle when every eligible contact is caught up.
- A new `start_at_first_email` contact receives email 1 at the next configured campaign occurrence,
  then at most one later ordered step at each subsequent occurrence.
- A newly appended active campaign email becomes the next missing step for caught-up contacts and is
  also reached normally by contacts still progressing.
- A contact never advances past a missing, suppressed, failed, claimed, or outcome-unknown earlier
  step.
- Weekly and monthly sends remain aligned to the configured weekday/day and time rather than the
  moment a contact or email was added.
- Overlapping workers may inspect the same due contact, but only one may cross the SMTP boundary.

## Scope

- Derive contact and campaign `caught up`, `in progress`, `blocked/review`, and next-due state without
  marking an ongoing campaign completed.
- Implement `NextMarketingCampaignOccurrence` as the single calendar-occurrence calculator,
  `ResolveMarketingCampaignMemberProgress` as the per-member ordered-state resolver, and
  `SummarizeMarketingCampaignRecipientProgress` as the campaign summary source of truth.
- Synchronize only the next missing active campaign email for each eligible identity.
- Preserve `start_at_first_email`; retain `join_current_step` only as an explicit policy with the
  same no-resend and ordered-progression invariant.
- Calculate the next occurrence at or after enrollment/append time for daily, weekly, monthly, and
  supported custom rhythms, using the campaign timezone and no-overflow month behavior.
- Apply per-email delay and deterministic batch gap after the base campaign occurrence.
- Make a later step eligible only after the previous applicable step is confirmed `sent`.
- Keep suppression or loss of consent on the same step while the contact is ineligible.
- Wire the send job through `ClaimMarketingCampaignDelivery`, reserve/persist Message-ID, mark
  `provider_write_started` before `SmtpAccountMailer`, and finish as `sent` or `outcome_unknown`.
- Treat stale `claimed`, `provider_write_started`, and `outcome_unknown` deliveries as consuming and
  review-required; never replay them automatically.
- Stop automatic cycle advancement and repeat-recipient creation while preserving legacy fields as
  historical metadata. Lifecycle remains `active` and reports `progressed`, `in_progress`,
  `blocked`, or `idle` instead of creating a cycle or completing the campaign.
- Make legacy pending later-step rows non-due until their predecessor is confirmed `sent`; they may
  not bypass the ordered resolver merely because an old `due_at` has elapsed.

## Out Of Scope

- Removing repeat controls or changing public API validation/resources.
- Technician summary presentation and legacy completed-campaign continuation UI.
- Editing or deleting historical repeat cycles, deliveries, events, or engagement tracking.
- Automatic retry after any delivery claim or provider-write boundary.
- Reordering already sent campaign-email identities or inferring content equivalence.
- Changing consent, unsubscribe, suppression, quiet-hours, approval, sender-account, tracking, or
  batching policy.

## Data Touched

- `marketing_campaigns` lifecycle fields remain readable history; ongoing status/summary is derived.
- `marketing_campaign_emails` order, active state, explicit scheduled time, and delay.
- `marketing_campaign_recipients` next-step rows, due time, status, claim link, and Message-ID.
- `marketing_campaign_deliveries` and delivery identity keys from Slice 1.
- Marketing campaign/list audience resolution and stable Contact/client-user/email identity evidence.
- Marketing queued send job, lifecycle/synchronization actions, scheduler dispatch, Email provider
  binding, `SmtpAccountMailer`, and sanitized Email logs.

No migration in this slice may send or queue email. Queue/scheduler behavior changes become active
only after the Slice 1 migration/backfill gate is verified.

## Permissions

No permission names or route ownership change. Existing Marketing technician/API authorization,
campaign approval, sender-account authorization, consent, suppression, and pause checks must be
re-evaluated at execution time.

## Tests

- New contacts start at email 1 on the next configured occurrence, not `now()` or a historical date.
- Weekly, monthly, custom, DST/timezone, per-email delay, batch-size, and batch-gap boundaries.
- Email 2 cannot be selected or sent before email 1 is confirmed sent.
- Only one next missing active email exists per contact at a time.
- A caught-up contact stays enrolled; appending a new email schedules it once at the next occurrence.
- Newer contacts progress normally when a campaign is extended.
- List refresh, overlapping lists, changed list-member row, Contact/client-user compatibility, and
  email casing do not create a second step or claim.
- Suppression, consent loss, safe pre-claim failure, `claimed`, provider-write interruption, and
  `outcome_unknown` each block progression correctly.
- Progress summaries classify `pending`/`claimed` as in progress; `failed`, `suppressed`,
  `outcome_unknown`, and an unconfirmed duplicate as blocked; only `sent` advances.
- Two overlapping jobs produce one claim and at most one SMTP call.
- Queue redelivery after claim never calls SMTP again, including a stale `claimed` row.
- Existing pause, approval, quiet hours, batching, unsubscribe, tracking, and provider-binding
  regressions continue to pass.
- An idle ongoing campaign remains active; no repeat cycle or old recipient is created.

## Documentation

- This Feature Slice document.
- Slice 3 updates Marketing README/Knowledge, Integration API documentation, TODO, deployment
  operations, and human-review tracking after the runtime contract is implemented.

## Completion Evidence And Remaining Gates

- The complete isolated Marketing suite passed on authoritative Dev with 65 tests and 802
  assertions. Coverage includes ordered next-step selection, calendar alignment, appended email,
  identity refresh/enrichment, safe pre-claim recovery, uncertain outcomes, overlapping jobs, and
  SMTP-boundary state transitions.
- The read-only preflight and post-migration readback preserve historical evidence and show no
  ambiguous or uncertain delivery rows on Dev.
- Implementation and default-queue execution capacity are verified. Dev has three active
  `email,default` workers with `/var/Projects/tdPSA` as their working directory, and Marketing uses
  the default queue. The failed-jobs table contains two unrelated failures and zero Marketing
  failures; no job payload or failure detail was exposed during that read-only check.
- Automatic schedule dispatch is not verified. No tdPSA `schedule:run` or `schedule:work` runner was
  found in accessible cron, systemd, or process sources, and the root crontab was unavailable.
- Named review remains open under `HR-2026-08-24-002`; automated verification does not mark it
  Reviewed.

## Done Criteria

- [x] Contact progress is derived independently of campaign completion.
- [x] Only the next missing step can be synchronized, claimed, or sent.
- [x] Every supported rhythm uses the next calendar occurrence with delay and batch offsets.
- [x] Appended emails reach caught-up and progressing contacts once without replaying old steps.
- [x] SMTP is reachable only through the durable claim/provider-write state machine.
- [x] Safe pre-claim failures and temporary suppression resume the same step; claimed and uncertain
  outcomes require review and cannot advance or automatically resend.
- [x] Ongoing campaigns no longer complete or repeat automatically.
- [x] Focused Marketing and affected transport-boundary tests pass on authoritative Dev.
- [x] Default queue-worker and failed-job inspection is recorded for Slice 3 human review: three
  active `email,default` workers, zero Marketing failures, and two unrelated failures.
- [ ] Verify an external tdPSA `schedule:run` or `schedule:work` runner; accessible sources contained
  none, and the root crontab could not be inspected.
- [x] `git diff --check` passes for the slice changes.
