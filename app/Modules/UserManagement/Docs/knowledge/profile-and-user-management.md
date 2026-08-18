# Profile And User Management

User Management owns application users, roles, permissions, user preferences, security settings, and
the authenticated technician profile shell.

Canonical profile data is stored in `user_profiles`. Personal workspace preferences are stored in
`user_preferences`.

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
- Work hours
- Ticket assignment
- Integrations
- View

Existing profile-related pages continue to keep their original route names while rendering inside
the unified profile shell.

## Ownership

User Management is the canonical owner for user and technician profile structure.

The Ticket module stores ticket assignment settings, including assignability, capacity, ticket
category matching, ticket tag matching, and assignment notes. User Management owns timezone, work
hours, availability, and general profile notes.

## Customer Portal Users

Customer Portal uses the existing `user_management` authentication table so customer contacts can
sign in with the same account lifecycle, password hashing, and active/disabled status checks.

Portal-only users must not receive internal Spatie roles or direct permissions. Their portal access
comes from Customer Portal account and membership records linked to `user_management.contact_id`.
Dual users may have both internal roles and Customer Portal memberships, but the two authorization
models remain separate.

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
- `/tech/profile` also lets the signed-in user update profile image, name, email, phone numbers,
  timezone, working hours, availability notes, and profile notes.
- `/tech/profile/preferences` manages timezone, default calendar view, normal workday defaults, and
  personal theme preference.
- `/tech/profile/security` manages password and two-factor authentication.
- `/tech/profile/notifications` manages notification delivery preferences.
- `/tech/tickets/profile` manages ticket assignment settings.
- `/tech/profile/integrations` is reserved for personal integration settings.
- `/tech/profile/view` is reserved for future deeper personal display preferences.

## Avatar And Theme

Profile images are uploaded to the public storage disk under `user-avatars/`.

Production servers must have Laravel public storage linked:

```bash
php artisan storage:link
```

The tech layout reads the personal theme setting from `user_preferences.settings.theme`.
Supported values:

- `company`
- `system`
- `light`
- `dark`

`company` inherits the default theme configured in System -> Branding. `system` leaves Bootstrap in
browser/system mode. `light` and `dark` write `data-bs-theme` on the HTML element.

## User Disablement And Web Push

The canonical User Management status-change action owns the transition to `DISABLED`. When an
internal user becomes disabled, User Management calls the Notification-owned cleanup action, which
removes every Web Push subscription belonging to that user.

Cleanup is idempotent and records one secret-free lifecycle audit event per removed device.
Reapplying the disabled status does not create duplicate cleanup records. The audit contains only
the actor when applicable, target user, public subscription identifier, coarse device summary,
action, and timestamp.

Normal sign-out does not remove Web Push subscriptions. Administrators should disable the user for
offboarding and may separately revoke a lost device from Admin > Notification channels.

## Protected System Actors

Some unattended domain workflows need a stable audit identity without impersonating a human user.
User Management can hold these identities as protected system actors.

A system actor is disabled for authentication, cannot sign in even if its password or status is
tampered with, is hidden from ordinary User Management and the user API, and cannot be edited,
activated, invited or assigned roles through those surfaces. Its permissions are direct,
least-privilege permissions maintained by the owning workflow.

**Nexum Supplier Order Automation** is the Storage-owned actor for automatic supplier, Item and
Purchase Order actions. It has no roles and only `storage.purchase_manage`,
`storage.purchase_import_profile_manage` and `documentation.create`. System actors must not be used
as technician, customer-portal or API-token accounts.

## Email Emergency-Access Permissions

User Management owns assignment of Email's narrow emergency permissions; Email owns every current
mailbox/account authorization decision and its audit records.

- `email.break_glass_activate` permits an active human security operator to request bounded,
  reason-bearing emergency access and to emergency-revoke another active record. It does not itself
  grant ordinary mailbox content, send, organize, AI, Ticket, rule, or configuration access.
- `email.break_glass_audit` is read-only access to metadata-only mailbox access history and marks an
  active human as a security notification recipient. It never grants activation or revocation.
- `email.raw_source_view` is an additional double guard for ordinary delegated or emergency raw
  message source access; it grants nothing without a current mailbox access source.

These permissions are not assigned to the default Administrator or Technician roles. Protected
system actors can never use personal mailbox delegation or emergency access, even if a permission is
assigned directly. Disabling a human user takes effect at the next Email authorization boundary.

## Email Provider Administration Permissions

Integration owns Email provider endpoint and credential authority while Email owns mailbox accounts
and content access. User Management may assign the permissions, but neither role assignment nor
account configuration bypasses the owning modules' execution-time checks.

- `integration.email_provider_manage` is assigned to the default Admin and Superuser roles. It
  manages public Email provider records and credential lifecycle metadata, not mailbox content.
- `integration.email_private_endpoint_manage` is Superuser-only. It is required in addition to
  provider management for listing, creating, staging, verifying, activating, binding, or testing an
  approved private/internal provider.
- `email.mailbox_sync_manage` is additionally required for provider preview, stage, Verify, legacy
  cutover, and rollback operations.
- `email.account_manage` is additionally required to bind an active, exactly verified provider to a
  new Email account.
- `system.telescope_view` is Superuser-only and gates the complete Telescope UI. Email provider
  management alone does not expose other application telemetry.

All lifecycle actions require a current active, non-system human and repeat authorization after
locking current records. These permissions never grant mailbox View, Organize, Send, raw-source,
attachment, search, conversation, Ticket, or emergency access.

## Development Rules

- New general profile features belong in User Management.
- Do not create another technician profile surface in a separate domain.
- Keep existing profile routes compatible until migration work explicitly replaces them.
- Keep the shared profile side menu in User Management.
- Ticket-owned profile data must not be expanded beyond ticket assignment needs.
