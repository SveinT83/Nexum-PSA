# Feature Slice: User Profile Data Model

Status: Draft
Date: 2026-05-31
Parent: `docs/rfc/2026-05-31-technician-profile-consolidation.md`
Owner: Svein / Codex

## Goal

Create the canonical User Management-owned profile data model.

This slice gives Nexum one real profile owner for platform-wide user/technician profile data.

## User-Visible Behavior

- Profile/account details are stored in User Management-owned profile data.
- Technicians can have profile metadata that is not Ticket-specific.
- Admins can rely on one canonical profile record for internal users.

## Scope

- Add `user_profiles` migration/model/factory as User Management-owned data.
- One profile per internal user.
- Add relationship from `User` to `UserProfile`.
- Include fields for:
  - avatar/profile image path
  - work phone
  - private phone
  - timezone
  - work hours
  - availability notes
  - profile notes
- Add safe defaults for clean installs.
- Backfill a profile for existing users.
- Move only clearly platform-wide profile data in this slice.

## Out Of Scope

- Profile image upload UI.
- Full work hours editor UI.
- General skills migration.
- Ticket assignment settings split.
- Notification preferences.
- Security/2FA changes.
- Company branding.

## Data Touched

- `users`
- new `user_profiles`
- existing user profile/contact fields if currently stored directly on `users`
- possibly `ticket_technician_profiles` only for reading/backfill if approved during implementation

## Permissions

- Users can edit their own profile through profile routes.
- Admins with user-management permission can edit profile data for other users.
- Permission names should follow existing User Management conventions.

## Tests

- Migration creates `user_profiles`.
- Existing users receive profile rows through migration/backfill logic.
- New users get a profile row by default or through first access.
- User model relationship works.
- Admin/user authorization is enforced.
- Existing User Management tests remain green.

## Documentation

- User Management Knowledge documentation must explain `user_profiles`.
- Update profile RFC if final field list changes.
- Update developer docs if model ownership patterns need clarification.

## Done Criteria

- User Management owns a canonical `user_profiles` table/model.
- Existing internal users can be associated with one profile row.
- No duplicate profile ownership is introduced.
- Tests prove relationship, authorization, and profile persistence.
