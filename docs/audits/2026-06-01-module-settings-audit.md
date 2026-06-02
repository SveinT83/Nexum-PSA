# Module Settings Audit - 2026-06-01

This audit records beta-relevant settings and UI ownership gaps found during the Module Settings Audit.

The goal is to identify scoped follow-up work. It does not approve broad refactors by itself.

## Audit Summary

Several core modules already have settings surfaces, but discoverability and ownership are uneven.

The biggest beta risks are:

- Settings routes exist but are not consistently exposed from the Admin hub.
- Some active modules have no clear settings surface.
- Some modules expose permissions for settings but have no settings routes yet.
- Legacy planning/specification Markdown files still live inside production view paths.
- Some visible UI still advertises future behavior, for example Asset related tickets.

## Existing Settings Surfaces

These modules currently expose settings or admin configuration routes:

- Calendar: `/tech/admin/settings/calendar`
- Clients: client formats and per-client settings
- Commercial: contracts, services, units, rates
- Economy: order and billing settings
- Email: accounts, config, rules, templates
- Integration: RMM, Tactical RMM, BookStack, API, AI, Nextcloud
- Notification: admin channels and user notification settings
- Sales: rules and workflows
- Storage: inventory/warehouse settings
- System: company profile, branding, taxonomy, queues/workers, templates
- Ticket: queues, types, statuses, priorities, rules, workflows, assignment settings
- User Management: users, roles, permissions, two-factor settings

## Discoverability Gaps

These settings exist but are not sufficiently visible from the Admin landing page:

- Calendar settings.
- Nextcloud settings.
- Notification channels.
- Ticket assignment rules and ticket technician assignment settings.
- Integration-specific settings beyond API management.
- User roles, permissions, and two-factor settings.

## Missing Or Unclear Settings Ownership

These modules need settings ownership decisions before beta is considered clean:

- Asset: manual registration defaults, enabled system asset types, default IP mode, and default manual status are now configurable. RMM sync defaults, alert handling, and related-ticket behavior still need later slices.
- Contact: default contact type/status and relation type choices are now configurable. Duplicate protection remains mandatory. Communication/language preference defaults still need a later slice.
- Knowledge: default visibility, status, review interval, and sort priority are now configurable. BookStack behavior remains Integration-owned.
- Risk: assessment defaults, scoring defaults, review cadence, and settings route are now configurable.
- Task: default status, default priority, and default estimate are now configurable. Task templates, checklist defaults, and assignment defaults still need later slices.
- Warroom: dashboard widget visibility, time windows, and list limits are now configurable.
- Report: future report export/saved-view settings after more reports exist.

## Visible Unfinished UI

Known visible unfinished or confusing surfaces:

- Asset detail "Feature coming soon" related-ticket copy was replaced with a neutral empty state.
- Integration N-able network equipment "Coming soon" control was removed until the behavior is implemented.
- Legacy planning/specification files with "Not completed" text have been moved out of production
  view directories, including the Email Livewire Inbox `.php` planning files.
- Login now uses Bootstrap and company branding defaults instead of old `tdPSA` placeholder/style.

## Legacy Planning Files In View Paths

Planning and specification files should not live in production view paths.

Completed cleanup moved these files into legacy documentation folders:

- Resource view specs: `docs/legacy/view-specs/resources/views`
- Module view specs: `app/Modules/{Domain}/Docs/legacy-view-specs`
- Email Livewire Inbox specs: `app/Modules/Email/Docs/legacy-view-specs/Livewire/Client/Inbox`
- Controller planning specs: `app/Modules/{Domain}/Docs/legacy-view-specs/Controllers`

No `.md` or `.blade.md` files should remain under production `resources/views` or module `Views` folders.
No planning-only `.php` files should remain under module `Views` folders.
No planning-only `.md` files should remain under module `Controllers` folders.

## Recommended Beta Follow-Up Order

1. Admin navigation discoverability cleanup.
2. Visible unfinished UI cleanup.
3. Legacy planning files cleanup.
4. Settings ownership RFC for modules without settings surfaces.
5. Module-by-module settings implementation where beta-critical.

Progress:

- Asset Settings slice completed on 2026-06-01.
- Contact Settings slice completed on 2026-06-01.
- Legacy Planning Files Cleanup completed on 2026-06-01.
- Task Settings slice completed on 2026-06-01.
- Warroom Settings slice completed on 2026-06-01.
- Knowledge Settings slice completed on 2026-06-01.
- Risk Settings slice completed on 2026-06-01.

## Not In Scope For This Audit

- Building every missing settings surface immediately.
- Creating report builder functionality.
- Barcode scanning.
- Email template editor.
- Full API foundation.
