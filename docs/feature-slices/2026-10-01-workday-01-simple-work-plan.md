# Feature Slice 01: Simple Work Plan And One Working-Hours Source

Status: Done On Dev; employee pilot active, human review Pending (2026-10-03).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - Pending; complete pilot not enabled.
Dependencies: RFC/ADR approved by Svein 2026-10-01; shared Dev WIP checked.

## Goal

Give each employee a usable weekly plan with dated/recurring exceptions, built on the existing profile and Calendar.

## User-Visible Behavior

The profile shows normal weekly working hours. A plan view shows recurring education and dated exceptions, including operational phone-duty availability. Planned time never becomes confirmed work automatically.

## Scope

- Make user_profiles.working_hours and the canonical profile timezone authoritative for normal weekly working windows. Inspect existing data before transition; empty/default profiles are not evidence that legacy custom values may be discarded.
- Reconcile UserPreference workday_start/workday_end with UpdateUserPreferences and Calendar defaults. Presentation defaults must not overwrite a custom weekday schedule. Preserve unmapped/custom rules and report conflicts for explicit resolution.
- Extract/use a dedicated UserManagement work-plan action. Updating a schedule must not invoke unrelated name/email/password or security mutations through UpdateUserProfile.
- Keep effective-dated CalendarAvailabilityRule/Override records and Calendar recurring blocks under Calendar ownership. Tag derived rules with provenance; mutate only owned derived rules. Existing Booking callers retain their normal source/window behavior.
- Represent education as an explicit plan activity and phone-duty availability as operational metadata. Recurrence is expanded by Calendar; changing one occurrence must not rewrite the whole series.
- Add self-service plan read/write API and scoped Calendar block operations alongside UI. Reuse existing administrative profile/calendar authorization for managing another person's plan; confirmed-work oversight alone grants no plan writes.

## Out Of Scope

Advanced rota optimization, official paid-education classification, actual time inference, phone-provider login/logout, and global rewrites of historic calendars.

## Data Touched

Existing UserProfile, UserPreference, CalendarAvailabilityRule, CalendarAvailabilityOverride and Calendar recurrence/event records. Add only missing provenance/effective-date metadata after schema inspection; no second employee profile or schedule engine.
Names for new storage/actions/routes are implementation proposals, not claims of existing tables.
Confirm exact migrations and existing state on authoritative Dev before runtime changes.

## Permissions

Self-only users.work-plan.read/update abilities; existing profile ownership and administrative profile guards. Calendar plan operations require own-calendar access plus the dedicated plan abilities and existing Calendar permissions. Define all routes inside owning modules' routes.php; preserve existing routes.

## Tests

- Custom weekdays/part-time hours survive saving unrelated preferences; a Monday education exception does not delete the normal weekly plan.
- Effective-date changes preserve prior plans; repeated writes do not duplicate derived Calendar rules.
- Recurring exception/cancellation, overnight windows, DST and profile/calendar timezone mismatch are handled explicitly.
- Booking availability and Calendar Find Time retain existing behavior for unaffected users.
- A personal token cannot manage another worker's plan or change roles/account-security fields.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Update UserManagement profile/API Knowledge and Calendar availability/API Knowledge; document the reconciliation preview and any additive migration.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

Profile and Calendar UI/API read-back agree on a synthetic weekly plan and dated exception; existing profile/Calendar/Booking regressions pass on Dev; conflicts are visible rather than silently migrated.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery Evidence (2026-10-01)

Svein approved implementation on 2026-10-01. The first slice is implemented directly on
authoritative Dev; see [verification and handoff](../plans/2026-10-01-workday-slice-01-verification.md).
Final targeted run: 75 tests / 599 assertions passed. Six generated API operations were read back
through trusted Dev HTTPS. No migration or runtime activation; WORKDAY_ENABLED remains false.
The remaining Workday slices and real employee MCP flow are not claimed complete.
HR-2026-10-01-WORKDAY remains Pending; manual pilot review is required before Main/production.
