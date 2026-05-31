# Feature Slice: Work Hours And Skills Migration

Status: Partially Implemented
Date: 2026-05-31
Parent: `docs/rfc/2026-05-31-technician-profile-consolidation.md`
Owner: Svein / Codex

## Goal

Move platform-wide work hours, availability, and general technician skills into User Management-owned
profile data.

Ticket can use those values for assignment, but Ticket must not own them as the technician profile.

## User-Visible Behavior

- Technicians edit work hours and general skills from their unified profile.
- Admins can edit work hours and general skills from the admin user profile.
- Ticket assignment can still use work hours and relevant skills.
- The same work-hour data can later be reused by Calendar, Telephony, scheduling, and availability
  features.

## Scope

- Migrate work hours and timezone from the old Ticket technician profile structure to
  `user_profiles`.
- Add or define general skills/competencies under User Management.
- Prefer existing Taxonomy records for skills if they fit; otherwise document why a dedicated skill
  model is needed.
- Update profile UI sections for Work Hours and Skills.
- Update Ticket assignment engine to read User Management profile work hours and skills where
  relevant.

## Out Of Scope

- Full absence/vacation planner.
- Calendar scheduling redesign.
- Telephony implementation.
- AI-based skill scoring.
- Advanced skill levels unless approved separately.

## Data Touched

- `user_profiles`
- possible `user_profile_skills`
- existing Taxonomy tags/categories if reused
- old `ticket_technician_profiles` work-hours/timezone fields during migration
- Ticket assignment engine
- profile/admin profile views

## Permissions

- Users can edit their own work hours and skills unless an admin policy later restricts this.
- Admins with user-management permission can edit any technician's work hours and skills.
- Ticket assignment settings remain protected by Ticket/admin permissions.

## Tests

- Work hours migrate from old ticket profile data to User Management profile data.
- Technician can update own work hours.
- Technician can update own skills.
- Admin can update another user's work hours and skills.
- Ticket assignment engine respects migrated work hours.
- Permission checks block unauthorized edits.

## Documentation

- User Management Knowledge docs for work hours and skills.
- Ticket Knowledge docs for how assignment uses profile data.
- Beta completion plan if ownership changes during implementation.

## Done Criteria

- Work hours and general skills are no longer owned by Ticket.
- Ticket assignment still works.
- User and admin profile UIs expose work hours and skills clearly.
- Tests cover migration, editing, permissions, and assignment usage.

## Progress Notes

Partially implemented 2026-05-31.

- `user_profiles` now stores migrated work hours and timezone.
- The production-safe `user-profiles:backfill` command copies existing Ticket technician profile
  work hours and timezone into User Management profile records.
- Existing Ticket technician profile updates mirror timezone, work hours, and notes into
  `user_profiles` to prevent data divergence during the transition.
- Ticket assignment scoring reads UserManagement profile work hours/timezone first and falls back to
  Ticket technician profile values.

Remaining:

- Move the visible Work Hours editor out of the Ticket view and into a UserManagement-owned profile
  section.
- Decide whether category/tag skills are ticket-specific assignment signals or general technician
  competencies.
- Move general skills to UserManagement if approved.
