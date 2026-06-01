# Feature Slice: Ticket Assignment Settings Split

Status: Implemented
Date: 2026-05-31
Parent: `docs/rfc/2026-05-31-technician-profile-consolidation.md`
Owner: Svein / Codex

## Goal

Remove the misleading Ticket-owned "technician profile" concept and replace it with explicit
Ticket assignment settings.

Ticket should own ticket assignment behavior, not the technician profile.

## User-Visible Behavior

- UI text changes from "Ticket technician profile" to "Ticket assignment settings" or equivalent.
- Ticket Settings may still have a technician assignment configuration page, but it must be clearly
  assignment-specific.
- Assignment behavior remains stable after migration.

## Scope

- Design and create a Ticket-owned assignment settings structure.
- Migrate assignment-only fields from `ticket_technician_profiles`.
- Update assignment engine to read assignment settings and User Management profile data where
  appropriate.
- Rename UI labels and routes where needed.
- Keep compatibility redirects for old ticket profile routes where needed.

Assignment-only fields likely include:

- `is_assignable`
- `max_open_tickets`
- ticket-specific category/tag matching when it cannot be represented as general skills
- ticket-assignment notes

## Out Of Scope

- Building the new User Management profile shell.
- Profile image upload.
- General skills model if it belongs to User Management.
- Changing the broader Ticket workflow engine.

## Data Touched

- existing `ticket_technician_profiles`
- existing ticket technician profile category/tag pivot tables
- new or renamed Ticket assignment settings table
- Ticket assignment engine service
- Ticket settings routes/views/tests

## Permissions

- Existing Ticket admin/settings permissions should continue to protect assignment settings.
- Technicians should not be able to change assignment-only admin controls unless explicitly allowed.
- User Management admin profile may show assignment settings only to admins with appropriate access.

## Tests

- Existing ticket assignment tests still pass.
- Assignment engine reads the new settings structure.
- Old assignment data migrates correctly.
- Ticket settings UI saves assignment settings.
- Old routes redirect or remain compatible where required.
- Permission checks prevent unauthorized assignment setting changes.

## Documentation

- Update Ticket Knowledge docs for assignment rules/settings.
- Update User Management docs to clarify what profile data lives outside Ticket.
- Update RFC if final table names differ.

## Done Criteria

- No user-facing UI calls this the main technician profile.
- Ticket owns only assignment-specific settings.
- Assignment engine behavior remains stable.
- Tests cover migration and assignment behavior.

## Implementation Notes

Implemented 2026-05-31.

- Added `ticket_assignment_settings`.
- Added `ticket_assignment_setting_categories`.
- Added `ticket_assignment_setting_tags`.
- Migrates legacy `ticket_technician_profiles` rows and pivots into the new assignment settings
  tables.
- Drops the legacy `ticket_technician_profiles` tables after migration.
- Replaced `TicketTechnicianProfile` with `TicketAssignmentSetting`.
- Renamed user-facing labels from Ticket Technician Profile to Ticket Assignment Settings.
- Ticket assignment scoring now uses `ticket_assignment_settings` for assignment controls and
  `user_profiles` for timezone and work hours.
- Work hours and timezone are edited from the User Management profile, not from Ticket.

Validated with:

- `HOME=/tmp php artisan test app/Modules/UserManagement/Tests/Feature`
- `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php --filter=Technician`
- `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php --filter=assignment`
