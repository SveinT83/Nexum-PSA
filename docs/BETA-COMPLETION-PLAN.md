# Nexum PSA Beta Completion Plan

This document defines the current completion focus for Nexum PSA.

Nexum PSA is no longer in MVP/version-1 planning. Beta is live. The current goal is to finish,
harden, document, test, and polish the existing product before starting large new systems.

## Purpose

The purpose of this plan is to keep humans and AI agents aligned while several contributors work on
the project.

Before new domains or large new capabilities are started, the existing modules must be reviewed and
completed enough that beta users can work safely and consistently.

## Completion Principle

Existing systems first.

New systems later.

Exceptions are allowed only when a new capability directly unblocks beta completion or has an
approved RFC.

## Definition Of Beta-Ready

A module or workflow is beta-ready when:

- The visible UI matches implemented behavior.
- No active button, toggle, route, or setting exposes unfinished functionality.
- Required settings exist and are reachable from the correct admin/profile area.
- Permissions are enforced consistently.
- Empty states, success messages, errors, and confirmations are clear.
- Core workflows have feature or regression tests.
- Knowledge documentation exists and can be synced to the Nexum PSA BookStack book.
- Deploy or post-update commands are documented when required.
- Known limitations are documented and tracked in `docs/TODO.md`.

## Current Top Priorities

### 1. Technician Profile And Preferences

Nexum needs a proper technician profile/settings area.

Expected capabilities:

- The technician profile must be reachable from the main menu/user menu where technicians already
  expect to find their own account area.
- Separate `Preferences` and `Security Settings` pages should be folded into the technician profile
  experience instead of living as disconnected areas.
- The profile should use its own side menu so account details, preferences, security, work hours,
  skills, and notifications can grow without cluttering one page.
- Technician name and profile data.
- Profile image/avatar.
- Work phone and private phone.
- Work hours and availability.
- Skills and service competencies.
- Notification preferences.
- Two-factor/security setup as part of the profile side menu.
- Security-related preferences where appropriate.
- Future per-technician integration URLs, for example Telephony Call Intake token URLs.

This should become the technician-owned preference surface instead of spreading technician settings
across unrelated admin screens.

Superadmin and admins with user-management permission must be able to manage the same relevant
technician profile data from an admin route on behalf of technicians. The admin route should not be a
separate model of profile behavior; it should reuse the same rules and data where practical.

### 2. Company Profile And System Branding

Nexum needs a company profile and configurable branding for beta and production use.

Expected capabilities:

- Company name and legal/company information.
- Organization number where relevant.
- Company address and contact information.
- Support/contact email and phone values used by system templates where relevant.
- Company/product logo in the header.
- Company brand colors for Nexum PSA theme styling.
- Login and public-facing branding where relevant.
- Fallback logo/name when no branding is configured.
- Fallback theme colors when no company colors are configured.
- Admin-controlled branding settings.
- Safe asset upload and rendering.

Branding and company details must be implemented as real settings, not hardcoded per deployment.
These values should later be reusable by email templates, public quote/contract pages, receipts,
customer portal surfaces, and generated documents.

Brand colors should allow Nexum to visually match the hosting/company profile without breaking the
project UI guidelines. The implementation should stay Bootstrap-compatible, avoid one-off inline
styling, and use safe defaults when colors are missing or invalid.

After company branding is in place, technicians should be able to adjust limited personal view
preferences for their own workspace. This should include light/dark mode and may later include small
layout or density preferences. Personal view preferences must sit on top of company branding, not
replace it, and must still follow the Nexum UI guidelines.

### 3. Existing Module Settings Audit

Each existing module should be reviewed for missing settings.

Audit questions:

- Which behavior is currently hardcoded but should be configurable?
- Which settings are needed before beta users can safely operate the module?
- Are settings placed in the correct domain?
- Are defaults seeded for clean installs?
- Are settings documented in Knowledge?

Modules to audit first:

- System.
- User Management.
- Clients.
- Contacts.
- Tickets.
- Email and Inbox.
- Calendar.
- Notification.
- Knowledge: manual article defaults are now configurable.
- Nextcloud.
- Commercial.
- Sales.
- Economy.
- Storage.
- Assets.
- Tasks.

### 4. Custom Fields Core

Custom Fields are now part of the beta-ready platform surface.

Expected capabilities:

- Admins can define reusable metadata fields for supported domains.
- Supported field definitions have visibility, UI editability, API editability, searchability,
  uniqueness, required state, and optional permission controls.
- Client is the first supported domain.
- Visible client fields appear in the Client workspace `Custom Fields` tab.
- Editable client field values can be updated from the Client workspace without editing the field
  definition itself.
- Client create/update API accepts `custom_fields` for API-editable fields.
- Client list API supports searching by searchable custom fields.
- Custom field definitions are discoverable through a read-only API so n8n and future AI agents can
  inspect configured fields before writing values.
- Knowledge documentation must explain the distinction between field definitions and field values.

### 5. Beta UX And Functionality Gap Sweep

Review beta screens systematically.

Look for:

- Missing confirmations.
- Missing success/error feedback.
- Broken empty states.
- Confusing navigation.
- Unclear ownership between admin/profile/user settings.
- Visible controls for unfinished functionality.
- Tables or forms that are hard to use with real data volume.
- Missing search/filter behavior.
- Missing clickable-row behavior where the rest of Nexum already uses it.
- Pages that do not follow Bootstrap and project UI guidelines.

### 6. Existing Module Test And Regression Sweep

Before large new systems, existing modules need stronger regression coverage.

Focus on:

- Login and routing behavior.
- Permissions and role gating.
- User management.
- Ticket lifecycle.
- Email/inbox handling.
- Contact duplicate prevention and client/site relations.
- Nextcloud sync and mapping.
- Knowledge and BookStack sync.
- Commercial contracts, services, rates, and SLA inheritance.
- Economy order generation.
- Storage reservations and picking.

## Rough Gap Findings

This section is intentionally rough. It captures known and newly discovered beta gaps before the
final beta-completion document is polished.

### Profile Fragmentation

Current profile-related surfaces are split across several routes:

- User preferences.
- Security settings.
- Notification preferences.
- Ticket technician profile.
- Admin employee profile.
- Ticket technician admin profile.

These should be consolidated into a coherent technician profile experience with a side menu. The
existing Ticket technician profile data should not be lost, but it should be presented as part of the
technician profile instead of as a disconnected Ticket-only destination.

The cleanup should happen properly now: User Management should become the real owner of the
technician profile, work hours, availability, and general skills. Ticket should only retain
ticket-assignment-specific settings.

Planning document:

- `docs/rfc/2026-05-31-technician-profile-consolidation.md`

### Admin And Settings Navigation Gaps

Some settings/admin routes exist but are not consistently exposed from the Admin landing page and
sidebar.

Known examples to verify:

- Calendar settings.
- Nextcloud settings.
- Notification channels.
- Two-factor admin settings.
- Ticket technician profiles.
- Individual integration settings.

Admin navigation should show all beta-relevant settings surfaces in predictable groups. If a settings
route exists and is production-ready, it should be discoverable. If it is not production-ready, it
should be hidden or marked as unavailable honestly.

### Missing Settings Ownership

Several modules appear to have no clear admin/settings surface yet, or only partial settings:

- Assets: manual registration defaults are now configurable.
- Contacts: contact defaults and relation type choices are now configurable.
- Knowledge.
- Risk: assessment and risk item defaults are now configurable.
- Tasks: manual task defaults are now configurable.
- Warroom: dashboard windows, list limits, and visible panels are now configurable.

Each module needs an ownership review:

- Does it need settings for beta?
- If yes, where should those settings live?
- Are defaults seeded?
- Are permissions defined?
- Is the behavior documented in Knowledge?

### Visible Unfinished UI

Visible beta UI must not expose unfinished promises.

Verified cleanup:

- Asset detail related-ticket empty state has been cleaned and no longer advertises unfinished behavior.
- Legacy Markdown specs were moved out of production view paths.
- N-able network-device coming-soon control has been removed from the integration settings screen.
- Commercial Contract/Service settings routes now show working hub pages with links to implemented
  commercial surfaces instead of rendering old view specifications.
- Contract create no longer appends old specification text beneath the real form.
- Sales lead detail now renders a working beta detail page instead of a "not started" specification.
- Queued email account health checks now reuse the real IMAP/SMTP test service instead of writing
  unconditional OK results.
- Scheduled BookStack pull/push jobs now mark active but misconfigured integrations unhealthy instead
  of returning silently.
- Commercial Contract settings route now uses `/tech/admin/settings/cs/contracts`; the old
  `/contacts` typo redirects for compatibility.
- Commercial Units creation now uses POST with CSRF instead of a GET route that created database
  rows.
- Commercial Cost deletion now uses DELETE instead of a GET route.
- Queue and Worker setup examples now render the current Laravel `base_path()` in the UI instead of
  a hardcoded development path.

For each case, either implement the feature, hide the UI, move the planning text into docs, or make
the limitation honest and useful.

### Knowledge Documentation Coverage

Repository-owned Knowledge documentation is published with `php artisan knowledge:sync-docs`, which
updates Knowledge records and marks them for the existing BookStack push worker. Seeders may exist
for compatibility, but they are not the normal documentation publishing workflow.

Modules that should be checked for Knowledge documentation:

- Asset.
- Calendar.
- Clients.
- Documentation.
- Email.
- Integration.
- Knowledge.
- Risk.
- Sales source docs vs seeded inline docs.
- Taxonomy.
- User Management.
- Warroom.

The beta-completion target is not necessarily a large book for every module, but every active module
should have enough Knowledge documentation for technicians/admins to operate it safely.

### Branding And Naming Inconsistency

Branding is currently hardcoded or inconsistent in several places.

Known examples to verify:

- The main tech layout now uses company profile logo/name fallbacks.
- The tech footer now uses company profile name instead of hardcoded `Nexum PSA`.
- The login page now uses Bootstrap, current company branding defaults, and a neutral email placeholder.
- Login and welcome copy no longer hardcode `Nexum PSA` as the visible subtitle.
- Public contract output now uses company profile name instead of hardcoded `tdPSA`.
- OpenAPI metadata now uses `Nexum PSA API Documentation` instead of `tdPSA API Documentation`.
- User-facing Knowledge, Integration, Risk PDF, Preferences, Notification test, and queue worker
  example text has been normalized away from old `tdPSA`, `NexumPSA`, or `Nexum-PSA` branding.
- Some UI, docs, and messages mix `tdPSA`, `NexumPSA`, `Nexum-PSA`, and `Nexum PSA`.

Company Profile and System Branding should resolve this with real settings, consistent naming,
fallbacks, and Bootstrap-compatible theme variables.

### Legacy Planning Files In View Paths

Old planning/specification files were moved out of `resources/views` and module view folders.
Historical copies now live under `docs/legacy/view-specs` or `app/Modules/{Domain}/Docs/legacy-view-specs`.

Verified cleanup:

- `resources/views` no longer contains `.md` or `.blade.md` files.
- Module `Views` folders no longer contain `.md` or `.blade.md` files.
- Legacy Email Livewire Inbox planning `.php` files were moved out of module `Views` and into
  `app/Modules/Email/Docs/legacy-view-specs/Livewire/Client/Inbox`.
- Legacy controller planning `.md` files were moved out of module `Controllers` folders and into
  each module's `Docs/legacy-view-specs/Controllers` folder.
- The legacy Ticket module status/planning document was moved from the module root to
  `app/Modules/Ticket/Docs/legacy-view-specs/ticket-module-status.md`.
- Runtime documentation cards now read from module `Docs/legacy-view-specs` paths where needed.
- Remaining resource-level task, task-template, and billing view specifications were moved to
  `docs/legacy/view-specs/resources/views`.
- Remaining Commercial contract and Sales lead module view specifications were moved to module
  `Docs/legacy-view-specs` folders.
- Empty unused runtime Blade files were removed from Clients, Commercial, Sales, Ticket, and global
  admin settings paths.

Planning documents should live under `docs/` or module `Docs/`, not in production view paths.
Production view paths should contain renderable views only.

## Work Method

For each module:

1. Read the module code, routes, controllers, views, models, migrations, tests, and Knowledge docs.
2. Compare implemented behavior against intended behavior.
3. Identify missing settings, broken flows, unfinished UI, missing permissions, and missing tests.
4. Add findings to `docs/TODO.md`.
5. Create RFCs for Level 2 or Level 3 changes before implementation.
6. Fix one scoped item at a time.
7. Add or update tests.
8. Update Knowledge documentation.
9. Leave a clear handover summary.

## Priority Order

Recommended order:

1. Security hardening and production safety.
2. User management, roles, permissions, and technician profile.
3. Branding and global system settings.
4. Reporting Domain Foundation.
5. Module Settings Audit across existing domains.
6. Admin Settings Discoverability Cleanup.
7. Visible Unfinished UI Cleanup.
8. Legacy Planning Files Cleanup.
9. Missing Settings Ownership RFC.
10. Domain API Foundation is beta-ready for the current module set. Current slice covers scoped API keys, Client/Site, Custom Fields, Asset, Contact, Ticket, Task, Knowledge, Storage, Calendar, Risk, Email Inbox, Notification, Sales, Taxonomy, Commercial, Economy, Report, and User Management API scopes.
11. Custom Fields Core hardening and first supported domain rollout.
12. Ticket, Email, Inbox, Contact, and Client workflow hardening found by the audit.
13. Notification, Nextcloud, Calendar, and Knowledge hardening found by the audit.
14. Commercial, Sales, Economy, Storage, Assets, and Tasks hardening found by the audit.
15. Future ideas and new domains.

This order can change when a production beta issue is discovered.

## Not The Current Focus

These ideas remain important, but should not distract from beta completion unless approved by RFC:

- Telephony Call Intake.
- Operational Signals.
- Service Workshop Foundation.
- Intelligence Domain.
- Large AI automation features.
- New major integrations.
- Advanced custom dashboards.
- Email Branding And HTML Template Editor.
- Storage Barcode Scanning.

## Documentation Expectations

Every beta-completion fix that changes behavior must update:

- The affected module Knowledge docs.
- `docs/TODO.md` when follow-up remains.
- RFC/ADR docs when the change is Level 2 or Level 3.

Knowledge documentation should explain how the feature works for technicians and admins, not only how
the code is structured.

## Completion Tracking

The active tracking list lives in `docs/TODO.md` under:

- `Beta Completion Before New Systems`
- `Technician Profile & Preferences`
- `System Branding & Header Logo`
- `Existing Module Settings Audit`
- `Beta UX / Functionality Gap Sweep`
- `Existing Module Test & Regression Sweep`

This document explains the direction. `docs/TODO.md` tracks the actual work.
