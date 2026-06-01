# Profile And User Management

User Management owns application users, roles, permissions, user preferences, security settings, and
the authenticated technician profile shell.

Canonical profile data is stored in `user_profiles`.

## Profile Workspace

Technicians open their own profile from:

```text
/tech/profile
```

The main user menu should expose one Profile entry. Individual links for Preferences, Security,
Notifications, or Ticket Assignment Settings should not be duplicated in the main menu.

The profile workspace uses a shared side menu with these sections:

- Account
- Preferences
- Security / 2FA
- Notifications
- Work hours & skills
- Integrations
- View

Existing profile-related pages continue to keep their original route names while rendering inside
the unified profile shell.

## Ownership

User Management is the canonical owner for user and technician profile structure.

The Ticket module stores ticket assignment settings, including assignability, capacity, ticket
category matching, ticket tag matching, and assignment notes. User Management owns timezone, work
hours, availability, and general profile notes.

## Data Migration

Production upgrades must migrate existing users into `user_profiles`.

Run:

```bash
php artisan migrate
php artisan user-profiles:backfill
```

The migration creates the `user_profiles` table and performs an initial backfill. The command is
safe to run again after deploy. It repairs missing profile rows and copies existing phone fields.
When legacy Ticket technician profile tables are still present, it can also copy timezone, working
hours, and notes from those legacy rows.

The legacy `ticket_technician_profiles` tables are migrated into explicit Ticket Assignment
Settings and then dropped by the cleanup migration.

## Current Profile Pages

- `/tech/profile` shows the profile shell and account summary.
- `/tech/profile` also lets the signed-in user update name, email, phone numbers, timezone,
  availability notes, and profile notes.
- `/tech/profile/preferences` manages timezone, default calendar view, and normal workday defaults.
- `/tech/profile/security` manages password and two-factor authentication.
- `/tech/profile/notifications` manages notification delivery preferences.
- `/tech/tickets/profile` manages ticket assignment settings.
- `/tech/profile/integrations` is reserved for personal integration settings.
- `/tech/profile/view` is reserved for personal display preferences after branding is implemented.

## Development Rules

- New general profile features belong in User Management.
- Do not create another technician profile surface in a separate domain.
- Keep existing profile routes compatible until migration work explicitly replaces them.
- Keep the shared profile side menu in User Management.
- Ticket-owned profile data must not be expanded beyond ticket assignment needs.
