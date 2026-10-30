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

## Simple Work Plan (Dev, Disabled By Default)

The approved Workday plan adds a dedicated schedule save at /tech/profile/work-plan, dated
Calendar projections and Education/Work/Other exceptions, with matching employee API operations.
See [Work plan](work-plan.md) for ownership, conflict preview, permissions, recurrence and read-back.
WORKDAY_ENABLED remains false until the full workflow/pilot is ready. This does not claim that
actual time, absence, reminders or the external NexumMCP adapter have been implemented.

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
- `/tech/profile/preferences` manages display timezone, default calendar view, visible calendar
  hours, and personal theme. It never rewrites working hours or Calendar availability.
- `/tech/profile/security` manages password and two-factor authentication.
- `/tech/profile/notifications` manages notification delivery preferences.
- `/tech/tickets/profile` manages ticket assignment settings.
- `/tech/profile/integrations` is reserved for personal integration settings.
- `/tech/profile/view` is reserved for future deeper personal display preferences.

## Password Validation

Password changes on Security / 2FA require the current password and a valid, confirmed new
password. Rejected changes show validation feedback beside the affected field and leave the
existing password unchanged. Submitted passwords are not restored into the form after an error.
The profile form uses the same guarded password-change action as Fortify; displaying its
validation errors does not bypass that action or the account-security boundary.

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

## Recovery Codes And The Guarded Vault Authentication Cutover

Recovery codes are personal authentication credentials. Do not paste them into Tickets, chat,
logs, screenshots or shared documentation. Keep the generated set in an approved password store.

The existing profile action and Fortify endpoint share one regeneration action. When the approved
guarded authentication cutover is enabled, generating a new set invalidates the previous set and
advances the account authentication epoch exactly once. Consuming a code replaces only that exact
code. Both operations invalidate prior active Vault proofs without granting new Vault step-up or
changing the user's Vault authority.

A challenge is tied to the account's authentication epoch and factor generation. Account or factor
changes require restarting sign-in. A pending, unconfirmed factor cannot be used for recovery login.
Failures roll back the protected code change and associated Vault evidence together. Safe recovery
events contain only user ID, execution ID and operation kind, never a raw code.

Validation errors do not copy TOTP or recovery-code input into the session's old-input bag.
Telescope excludes recovery-code lists, factor setup responses and legacy raw-code events, and masks
submitted authentication factors. This protection also applies before the Vault cutover.

**Deployment status:** the new guarded recovery/challenge paths are under Slice 04 verification.
The normal authentication provider is not switched by this change. Complete factor lifecycle,
installation/cutover verification and HR-2026-09-04-003 remain required before Vault activation.
This is not an instruction to enable Vault or migrate existing credentials.

## Pending Two-Factor Enrollment Under The Guarded Cutover

Starting a first setup, restarting an unconfirmed setup, and cancelling an unconfirmed setup are
distinct from changing an already confirmed factor. The guarded boundary requires the current
account's password and an authenticated matching user. A restart terminally supersedes the old
generation and creates a new pending generation; cancellation terminally disables the old generation.
Each real change advances authentication once and invalidates prior active Vault proofs. None of
these pending-only operations changes Vault authority or confirms a factor.

Generation history is retained after cancellation, a fresh enrollment and later account changes.
A failed protected write rolls back the generation, account epoch and associated proof evidence
together. Credentials and recovery-code values are not stored in generation/execution history.

The profile and Fortify enable/disable actions keep their legacy behavior before the coordinated
authentication cutover. Their guarded branch accepts `current_password`; the cutover/UI acceptance
must supply that password and complete the separate confirmed-factor authority workflow.
Confirmed-factor disable/replacement is deliberately denied by this pending-only boundary.
This implementation does not activate Vault or make the unfinished cutover ready for deployment.

## Privileged Invitations Under Vault Authority Enforcement

Accepting an invitation establishes the recipient's password. Once Vault authority enforcement is
active, an invited Admin or Superuser remains pending until an existing administrator completes
the separate protected activation with required approval. The invitation does not create a signed-in
session or grant active administrator authority. Assigned roles remain intact.

The invitation is used once; reusing it cannot reset the waiting account's password. Ordinary
non-privileged invitations keep their normal activation flow. Errors roll back password, token and
security-epoch changes together. A change in roles or enforcement state during acceptance requires
a fresh attempt rather than trusting the original decision.

**Deployment status:** the acceptance boundary and its no-login response are implemented on Dev
under Slice 04; the complete administrator activation and authority audit flow are still unfinished.
This behavior is not enabled by changing the normal authentication provider in this update.
HR-2026-09-04-003 and the full cutover verification remain required. Prepared for Knowledge/BookStack
sync as development documentation; do not present the complete activation workflow as released.

## Development Rules

- New general profile features belong in User Management.
- Do not create another technician profile surface in a separate domain.
- Keep existing profile routes compatible until migration work explicitly replaces them.
- Keep the shared profile side menu in User Management.
- Ticket-owned profile data must not be expanded beyond ticket assignment needs.

## Workday notification choices

Profile > Notifications includes Workday when the feature is enabled and the employee has own
view/register/confirm permissions. In-app is selected by default; Email and Web Push require
opt-in and configured channels/devices. Turn all channels off to disable reminders. These choices
do not change working hours, calendar plans or actual confirmed time. See the Workday reminder
guide for timing, absence handling and Snooze.

## UserManagement API

The full current workflow is verified on Dev and merged to Main in PR #353 through typed UserManagement API operations.
It includes own profile/avatar/preferences, user lifecycle, role/permission administration,
MFA policy, invitations and session-bound local security/SSO. API abilities always narrow
the account's current ordinary permissions. See [User Management API](user-management-api.md)
for exact steps, concurrency/retry rules, local verification and deployment availability.
Production deployment and provider/authentication activation remain separate.

## Workday timezones and rejected saves - 29 October 2026

The current Workday **Save interval** and **Save day details** actions record effective actual
time immediately. Separate draft review and confirmation belong to the legacy API workflow.

1. Open **Profile > Work plan** and check the timezone. The canonical profile timezone wins
   over legacy calendar preferences; if no profile exists, preferences are used, then the
   application timezone (UTC on this installation). Conflicting settings need administrator review.
2. Open **My Day > My workdays** and select the correct date. A new date uses your work-plan
   timezone; a saved day retains its original timezone. Changing the profile does not reinterpret
   a saved day's times. Ask an administrator before correcting a wrongly established day.
3. Enter completed local **Start** and **End** in that record's timezone. Europe/Oslo handles
   summer and winter offsets: local 20:40 is 19:40Z in winter and 18:40Z in summer.
   At local 20:45, 20:40 is complete; 20:46 is still future.
4. A rejected save shows the timezone/current clock and keeps submitted input in the modal.
   Correct the named field before retrying. Do not manually subtract an hour from actual minutes.
5. If an assistant receives HTTP 422, ask it for the exact field/message and a safe request
   reference. Use the same verified personal employee connection for date context, Save and
   read-back. Never send a token or password in a support message.

A plan of 480 minutes and 300 saved actual minutes can be legitimate. They do not prove wrong
identity. GET /api/v1/workdays/{work_date}/entry supplies the same user's date/timezone/version;
PUT /api/v1/workdays/{work_date}/save uses that version and an Idempotency-Key. Rejected
validation stores no partial day or receipt. Breaks need an explicit included boolean; owner
IDs and response-only minutes/state are not writable fields. A shared administrator connection
cannot substitute for the intended employee. The reported gateway identity/422 response still
requires the original safe diagnostic evidence; synthetic Dev tests do not establish it.

## Workday through the existing ChatGPT connection - 30 October 2026

For employees whose personal Nexum connection has been configured, use the existing
**Trønder Data – MCP** plugin in ChatGPT. Workday runs through its existing **nexumpsa**
connection. You do not need a separate Workday integration or a manually managed LiteLLM key.

1. Select the existing **Trønder Data – MCP** plugin in ChatGPT.
2. Ask it to read your Workday for the intended date before recording anything. The date
   context must show your own day, current version and record timezone. A plan or suggestion
   is not proof of work performed.
3. State the actual intervals and whether each break is included. Review the date, timezone
   and total before instructing the assistant to save. The current API action is **workdaySave**.
4. After saving, ask for a fresh read of that same day and compare intervals, breaks and total.
   Do not blindly repeat a failed save with a new retry key.

Access remains personal: another employee's day and administrative settings are not included.
A missing or revoked personal connection must fail rather than use a shared administrator.
For 401, ask the integration administrator to check the personal Nexum binding. For 403,
ask them to check the token scope and native Workday permission. For 422, obtain the exact
field error before changing the request. Never paste passwords or tokens into the chat.

For Norwegian local input, check **Profile > Work hours** and the date context for
**Europe/Oslo**. Profile/work-plan settings take precedence over older Preferences values.
A previously saved day keeps its own timezone: changing the profile does not reinterpret
historical intervals or change recorded minutes. Ask an administrator before correcting a
historical timezone; do not shift recorded work to compensate manually.

Availability: the gateway's personal list/date-context reads and denial checks are verified
for the configured incident accounts in production. Actual employee ChatGPT use and a real
employee-approved save remain human acceptance. The Ticket/Task local-display source fix is
still Dev-only until separately approved Main promotion and production deployment.
