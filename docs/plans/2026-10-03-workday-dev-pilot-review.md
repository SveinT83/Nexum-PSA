# Workday Dev Pilot Review

Date: 2026-10-03. Reviewer: Svein Tore. Environment: Dev only.
Status: Dev employee pilot active; calendar/modal UX approved by Svein Tore on 2026-10-05.
Remaining explicit edge-case/device/operational checks stay in the human-review register.
API follow-up: [35-operation verification](2026-10-05-workday-entry-api-verification.md).
Interactive verification requires the reviewer's authenticated session.
Latest tests and synthetic browser checks: [modal verification](2026-10-05-workday-modal-verification.md).
Activation/read-back: [verification](2026-10-03-workday-dev-pilot-verification.md).
Human-review checklist: HR-2026-10-01-WORKDAY in [human-review.md](../human-review.md).
This guide supports the remaining checks; named approvals are recorded in human-review.md.

## Open the pilot

- [My Day](https://dev.nexumpsa.eu/tech/my-day): discover My workdays and My absences.
- [My workdays](https://dev.nexumpsa.eu/tech/workdays): enter, review, confirm and correct time.
- [Work plan](https://dev.nexumpsa.eu/tech/profile/work-plan): weekly hours and dated/recurring exceptions.
- [My absences](https://dev.nexumpsa.eu/tech/workday-absences): simple absence.
- [Notification preferences](https://dev.nexumpsa.eu/tech/profile/notifications): Workday confirmation reminder.
- [Confirmed workdays](https://dev.nexumpsa.eu/tech/workdays/overview): Superuser oversight.
- [Workday settings](https://dev.nexumpsa.eu/tech/admin/settings/workday): employee activation and retention preview.
- [OpenAPI](https://dev.nexumpsa.eu/docs): API contract for later consumers.

Use clearly marked synthetic test content in this pilot. Do not enter real medical details.
The current permitted Dev account is Admin User (Superuser). Its reminder settings at preflight
were in-app enabled, email disabled and Web Push disabled; no notification preference changes
or external sends are implied by pilot activation.

## Suggested first test

1. Open My workdays. Expected: navigable month calendar with today active and an hourly timeline below.
2. Select a planned weekday. Expected: the first free hour from the profile plan is selected.
   Inspect the profile timezone (Admin User was last verified as UTC); use synthetic descriptions.
3. Click free time in the calendar: Register time opens in a modal. Check Start/End/Activity,
   close with Escape and reopen via Time entry: pending values remain and nothing was saved.
   Save 09:00-09:45 as "DEV TEST - 45-minute meeting". Expected: one block at three quarters of
   an hour, 45 actual minutes, still unconfirmed. The next free period begins 09:45.
   Click the block: Edit time opens in a modal. Change it to 09:15-10:00: same 45 minutes at a new position.
   Add 11:00-12:30: a single 90-minute block twice as tall as the 45-minute block.
   Try extending the first block past 11:00: overlap must be rejected and saved time preserved.
   For the following checks, use a separate synthetic 08:00-16:00 day with an excluded
   12:00-12:30 break (450 actual minutes).
4. Review saved draft and confirm the synthetic day. Expected: Confirmed, 450 minutes.
5. Open Confirmed workdays. Expected: this confirmed test day is visible.
6. Start a correction with a test reason; save a changed draft. Expected: the original confirmed
   version remains effective until the correction is explicitly confirmed, and history remains.
7. From the saved activity, preview an internal Task for 09:00-10:00 with a DEV TEST title.
   Accept the explicit preview. Expected: one non-billable Task/time source; actual total stays
   450 minutes. Follow the Task link and inspect its retained data.
8. Return to My Day and check discovery/navigation.

## Complete the existing human checklist

| Check | Expected result |
| --- | --- |
| Personal plan | Different weekday hours persist; recurring Monday education can mark phone duty unavailable without recording absence or actual work. |
| Manual day | Calendar navigation; proportional minute blocks; first free planned hour selected; click-to-edit; overlap rejected; first save creates one private day and subsequent saves preserve other intervals/breaks. |
| Source reconciliation | Existing Task/Ticket time explains the actual total without adding duplicate minutes; changed/revoked sources are handled visibly. |
| Correction/concurrency | Previous confirmation/history survives; a stale second tab cannot silently overwrite the current revision. |
| Absence | Create/amend/cancel test leave; one neutral Calendar projection, correct partial-day availability, no private reason exposed. |
| Access | Own data and confirmed oversight remain distinct; private drafts stay private. Explicit HR permission and denied-employee cases need their corresponding test identities. |
| Reminder | In-app behavior, opt-out and snooze work with the personal plan/absence. Real email/push receipt needs a deliberately opted-in test recipient/device. |
| Internal Task | Preview/create/retry produces one non-billable Task and unchanged actual totals; no automatic completion. |
| Employee API | Review the 34-operation contract and personal bearer evidence; scopes and employee ownership are enforced. |
| Retention | Preview counts and synthetic expiry/restore evidence; keep destructive cleanup disabled until its separate approval and external-storage checks. |
| Responsive/accessibility | Desktop/mobile, keyboard, validation and unsaved-form behavior are usable. |

Report the tested steps and any defects. Only explicit confirmation from a named human reviewer
can mark HR-2026-10-01-WORKDAY Reviewed. It blocks Main promotion and production migration,
deployment and activation; the approved Dev pilot is for carrying out that review.

## Operational limits

The Dev preflight found no Workday, absence, Task or TaskTimeEntry records. All eight Workday
migrations were already applied. The external minute scheduler and queue workers are present.
Retention preview found 105 historical diagnostic copies and restore_ready=false; no cleanup
was run. Backup-provider rotation and application log-archive rotation are not certified.
These remain production/retention activation checks, with destructive cleanup independently off.

MCP/LiteLLM adapters, Tripletex transfer, holiday entitlement/approval, advanced rota planning
and phone-provider queue automation remain future work.
