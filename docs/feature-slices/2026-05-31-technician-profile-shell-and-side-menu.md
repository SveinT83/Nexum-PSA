# Feature Slice: Technician Profile Shell And Side Menu

Status: Implemented
Date: 2026-05-31
Parent: `docs/rfc/2026-05-31-technician-profile-consolidation.md`
Owner: Svein / Codex

## Goal

Create one coherent technician profile entry point and visual shell before moving data.

This slice establishes the route, layout, side menu, and navigation direction for the unified profile
experience.

## User-Visible Behavior

- The main/user menu should point to one Profile entry instead of separate Preferences, Security
  Settings, Notifications, and Ticket Profile entries.
- `/tech/profile` opens a profile area with a side menu.
- The side menu shows sections for Account, Preferences, Security / 2FA, Notifications, Work Hours,
  Skills, Integrations, and View.
- Existing profile-related routes should either redirect into the new shell or continue to work while
  rendering inside the new shell.

## Scope

- Add or update User Management profile routes for the unified shell.
- Add profile side menu component/view.
- Move existing profile links in the main/user menu to the unified profile entry.
- Wire existing Preferences/Security/Notifications/Ticket assignment pages into the shell as
  temporary sections where possible.
- Keep this slice focused on navigation and layout, not data migration.

## Out Of Scope

- Creating `user_profiles`.
- Migrating work hours or skills.
- Renaming Ticket assignment tables.
- Profile image upload.
- Light/dark mode implementation.
- Admin user profile restructuring.

## Data Touched

- No database schema changes expected.
- Existing routes and views touched.
- Existing controllers may be reused or lightly wrapped.

## Permissions

- Authenticated technicians can edit their own profile sections.
- Existing permission behavior for each section must remain intact.
- No new admin permissions in this slice.

## Tests

- Technician can open `/tech/profile`.
- Main/user menu shows one Profile link.
- Old profile links still work through redirect or compatible rendering.
- Unauthenticated users cannot open profile routes.
- Existing preferences/security/notification tests remain green.

## Documentation

- Update User Management Knowledge docs or create them if missing.
- Update beta-completion plan if route decisions differ from the RFC.
- Update TODO status when complete.

## Done Criteria

- One profile entry exists in the main/user menu.
- A profile side menu exists and is Bootstrap/project UI compliant.
- Existing profile-related behavior still works.
- No Ticket-owned data is moved in this slice.
- Tests pass for User Management, Notification profile preferences, and Ticket profile route
  compatibility where touched.

## Implementation Notes

Implemented 2026-05-31.

- Added `tech.profile.index`, `tech.profile.integrations`, and `tech.profile.view` routes in
  User Management.
- Added a UserManagement-owned profile side menu shared by Preferences, Security, Notifications,
  and the current Ticket Assignment Settings page.
- Replaced the main navigation user dropdown with one Profile entry plus Logout.
- Kept existing Preferences, Security, Notification, and Ticket profile routes compatible.
- Added UserManagement documentation for the profile shell and ownership boundary.

Validated with:

- `HOME=/tmp php artisan test app/Modules/UserManagement/Tests/Feature`
- `HOME=/tmp php artisan test app/Modules/Notification/Tests/Feature/NotificationSystemTest.php`
- `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php --filter=Technician`
