# RFC: Technician Profile Consolidation

Status: Draft
Date: 2026-05-31
Owner: Svein / Codex

## Context

Nexum PSA currently has several profile-like surfaces:

- User Management admin employee profile.
- User preferences at `/tech/profile/preferences`.
- Security settings at `/tech/profile/security`.
- Notification preferences at `/tech/profile/notifications`.
- Ticket technician profile at `/tech/tickets/profile`.
- Ticket technician admin profile under Ticket Settings.

This creates confusion for technicians, admins, and AI agents because "profile" can mean different
things depending on the module. It also makes future features harder to place, such as profile image,
work hours, technician skills, per-technician integration URLs, and telephony intake tokens.

Beta completion should clean this up before new systems are built on top of fragmented profile
surfaces.

## Goals

- Create one coherent technician profile experience.
- Make the profile reachable from the existing main/user menu.
- Fold Preferences, Security Settings, Notification Preferences, and Ticket technician profile into
  one profile area with a side menu.
- Move real technician profile ownership to User Management now, not in a later cleanup.
- Keep domain ownership clear so Ticket owns only ticket-assignment-specific settings.
- Allow superadmin/admin users to manage relevant technician profile data from admin user routes.
- Avoid duplicating profile models or creating conflicting profile systems.
- Preserve existing behavior through a deliberate migration.
- Add tests and Knowledge documentation when implemented.

## Non-Goals

- Replacing the authentication system.
- Removing Ticket assignment profile data before a safe migration path exists.
- Building Telephony Call Intake now.
- Building a full HR module.
- Building advanced calendar/absence scheduling now.
- Reworking all notification delivery logic.

## Current Behavior

### User Management

User Management owns the core user/employee account:

- name
- email
- roles
- status
- invitation flow
- admin employee profile view
- basic contact details

It also owns user-facing Preferences and Security Settings routes.

Known routes:

- `tech.profile.preferences`
- `tech.profile.preferences.update`
- `tech.profile.security`
- `tech.profile.security.2fa.enable`
- `tech.profile.security.2fa.confirm`
- `tech.profile.security.2fa.disable`
- `tech.profile.security.recovery-codes`
- `tech.profile.security.password`
- `tech.admin.user_management.show`
- `tech.admin.user_management.profile.update`

### Notification

Notification owns personal notification preferences.

Known routes:

- `tech.profile.notifications`
- `tech.profile.notifications.update`

### Ticket

Ticket owns the ticket-assignment technician profile.

Known routes:

- `tech.tickets.profile.edit`
- `tech.tickets.profile.update`
- `tech.admin.settings.tickets.technicians`
- `tech.admin.settings.tickets.technicians.store`
- `tech.admin.settings.tickets.technicians.edit`
- `tech.admin.settings.tickets.technicians.update`

Ticket technician profile data includes:

- assignable yes/no
- max open tickets
- timezone
- working hours
- category skills
- tag skills
- notes

This data is currently used by the Ticket assignment engine and should not be casually removed.

## Proposed Change

Create a unified Technician Profile area owned by User Management.

This is not only a UI consolidation. The cleanup should also move platform-wide technician profile
data out of Ticket ownership.

Recommended route structure:

- `/tech/profile`
- `/tech/profile/account`
- `/tech/profile/preferences`
- `/tech/profile/security`
- `/tech/profile/notifications`
- `/tech/profile/work-hours`
- `/tech/profile/skills`
- `/tech/profile/integrations`

The exact route names can be adjusted during implementation, but the user experience should be one
profile area with a side menu.

### Profile Side Menu

Initial sections:

- Account
- Preferences
- Security / 2FA
- Notifications
- Work Hours
- Skills
- Integrations
- View

Future sections:

- Telephony intake URL/token
- Availability/absence
- Personal AI settings if needed

### Domain Ownership

User Management should own:

- profile shell
- account details
- profile image/avatar
- work/private phone fields if these are user account data
- platform-wide technician profile record
- work hours
- general availability
- general skills and competencies
- security/password/2FA UI
- generic preferences
- personal view preferences, including light/dark mode once system branding exists
- admin profile shell

Notification should own:

- notification preference data and persistence
- notification preference validation

Ticket should own:

- ticket assignment settings only
- whether the technician can receive automatic ticket assignment
- ticket assignment capacity, such as max open tickets
- ticket-specific assignment weights or category/tag matching only when this cannot be represented
  as general skills
- assignment-engine-specific rules

The unified profile UI may call module-owned actions/services for module-specific sections, but the
main technician profile must not remain a Ticket-owned concept.

`TicketTechnicianProfile` is the wrong long-term name and ownership boundary for general technician
profile data. It may be migrated, renamed, or replaced during this work.

### Admin Experience

Superadmin/admin users should open a user from User Management and manage relevant profile sections
from there.

Recommended admin route pattern:

- `/tech/admin/user_management/users/{user}`
- side menu or tab sections mirroring the user profile where appropriate

Admin-managed profile sections should reuse the same validation and persistence rules where
practical.

Ticket-specific assignment settings may still be stored in Ticket tables, but they should be named
and presented as assignment settings, not as the technician profile.

## Impact Analysis

### Affected Modules

- User Management
- Notification
- Ticket
- Calendar
- Integration
- Telephony future plan
- Knowledge

### User Management

Needs the profile shell, side menu, profile image handling, consolidated account/profile route
structure, and the canonical technician profile data model.

Existing Preferences and Security Settings should be moved into the profile shell without breaking
the current behavior.

### Notification

Notification Preferences should become a section in the profile area. The Notification module should
still own the data and update logic.

### Ticket

Ticket technician profile must stop being the owner of general technician profile data.

Ticket should keep or receive a smaller assignment-settings model/table for:

- assignable state
- max open tickets
- ticket-specific assignment matching/weights if needed
- ticket-assignment notes if needed

Ticket Settings may still have a "Technicians" page if it is useful for assignment configuration,
but it should clearly be "Ticket assignment profiles" and not the main technician profile.

### Calendar

Calendar currently references user preferences for default calendar and availability behavior.
Work-hours decisions may affect Calendar. This profile cleanup does not need to redesign Calendar
logic unless implementation proves that Calendar currently depends on the old Ticket-owned work-hour
data.

### Integration

Future per-technician integration URLs, such as Telephony Call Intake token URLs, should belong in
the profile's Integrations section when those features are implemented.

Company Profile/System Branding should provide the default brand and theme. User Management profile
preferences can later store technician-specific view preferences such as light/dark mode and limited
workspace display options.

## Data And Migration Plan

This work should include the required data migration now.

Recommended data model:

- `user_profiles` owned by User Management.
- One profile per internal user.
- Stores platform-wide profile fields such as avatar path, work phone, private phone, timezone,
  work hours, general availability, and profile notes.
- Stores or relates general skills/competencies through a dedicated relation such as
  `user_profile_skills`, preferably using existing Taxonomy records when they fit.
- Existing `user_preferences` can remain separate if it is clearly a preferences table, but it must
  be presented inside the unified profile UI. Personal view preferences such as light/dark mode can
  live there if no stronger reason exists to create a separate table.

Ticket-specific assignment data should move to a clearly named Ticket-owned structure, for example:

- `ticket_assignment_settings`
- one row per technician/user
- assignable state
- max open tickets
- ticket category/tag matching if it remains ticket-specific
- ticket assignment notes

Migration requirements:

- Migrate work hours and timezone from `ticket_technician_profiles` to the User Management profile
  table.
- Migrate general skills out of Ticket if they are no longer ticket-only.
- Preserve ticket assignment behavior by moving assignment-only fields into the new Ticket
  assignment settings structure.
- Keep compatibility code only as long as needed inside the migration/release, not as a long-term
  second profile system.
- Rename UI text from "Ticket technician profile" to "Ticket assignment settings".
- Update relationships, services, tests, and Knowledge docs in the same change.

## Testing Plan

Required tests:

- Technician can open unified profile.
- Profile side menu shows Account, Preferences, Security, Notifications, Work Hours, Skills, and
  Integrations sections.
- Existing preferences still save.
- Existing 2FA setup/disable/recovery/password flows still work.
- Existing notification preferences still save.
- Platform-wide work hours and skills save under User Management profile ownership.
- Ticket assignment settings still save and continue to affect assignment scoring.
- Assignment engine uses the new ownership boundary correctly.
- Admin can open a user profile and manage relevant profile sections.
- Route redirects or compatibility routes preserve existing links where needed.
- Permissions prevent non-admins from editing another user's profile.

## Documentation Plan

Update:

- User Management Knowledge documentation.
- Ticket assignment Knowledge documentation.
- Notification Knowledge documentation if route/user flow changes.
- `docs/BETA-COMPLETION-PLAN.md`.
- `docs/TODO.md`.

Document:

- Which module owns each profile section.
- How technicians update their own profile.
- How admins update technician profile data.
- What remains Ticket-specific.

## Feature Slices

This RFC must be implemented as Feature Slices, not as one broad change.

Initial slices to define before implementation:

- `docs/feature-slices/2026-05-31-technician-profile-shell-and-side-menu.md`
- `docs/feature-slices/2026-05-31-user-profile-data-model.md`
- `docs/feature-slices/2026-05-31-ticket-assignment-settings-split.md`
- `docs/feature-slices/2026-05-31-work-hours-and-skills-migration.md`
- Profile image and account details.
- Security/2FA profile section.
- Notification profile section.
- Personal view preferences.
- Admin user profile sections.
- Compatibility redirects from old profile routes.
- Knowledge documentation and operator docs.

## Open Questions

No open questions at this stage. The RFC should be reviewed for approval before implementation.

## Approval

Draft pending review.
