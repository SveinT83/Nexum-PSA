# Feature Slice: Email Template HTML Editor And Branding Layout

Status: Implemented On Dev / Human Review Pending
Date: 2026-08-24
Parent: `docs/rfc/2026-06-09-marketing-domain-email-campaigns.md`
Owner: Codex

## Goal

Replace the plain Email template HTML textarea and hardcoded renderer chrome with a safe, usable
editor workflow where content and layout are separately editable, untouched layouts follow company
branding, custom layouts remain frozen, and Marketing campaign snapshots stay stable.

## User-Visible Behavior

- Admins edit template `Body HTML` in a proper visual editor with HTML source/split mode.
- Admins edit plaintext independently.
- The outer layout has an explicit `Branding managed` or `Custom` state.
- Branding-managed layouts use current company logo, name, light-theme surface colors, action color,
  support email, and website.
- `Customize layout` copies the current rendered branding layout into an advanced HTML source field.
- A custom layout requires exactly one `{{ email_body }}` slot.
- `Reset to branding` clears the custom layout and resumes current-branding rendering.
- Editing template subject/body/plaintext does not switch the layout to custom.
- The full-width form preview is read-only, sandboxed, responsive, and updates from unsaved values.
- Marketing campaign body editors reuse the visual editor, while the selected template layout is
  frozen into the campaign email snapshot.

## Scope

- Add a shared Blade/JavaScript HTML-editor component backed by self-hosted Jodit through Vite.
- Add `layout_mode` and `layout_html` to Email templates.
- Add a materialized layout snapshot to Marketing campaign emails.
- Migrate legacy complete HTML documents from `body_html` into custom layouts with a body slot.
- Add an authoritative preview endpoint in the Email module.
- Render branding layouts from Company Profile settings, using organization light-theme colors rather
  than a technician's personal light/dark preference.
- Add outbound-template HTML validation that rejects active browser content and unsafe URL schemes.
- Preserve existing template and campaign content without destructive replacement.
- Update Email and Marketing Knowledge/developer documentation.

## Out Of Scope

- Editing directly inside the preview iframe.
- A general Documentation/Knowledge/WordPress block-editor replacement.
- Image upload, asset library, remote file browser, or base64 image insertion.
- Per-client, per-language, per-queue, or email-specific branding settings.
- Automatic plaintext generation.
- Changing campaign approval, delivery, tracking, unsubscribe, queue, or scheduler behavior.
- Enabling Mail automatic external replies or any Mail completion runtime gate.

## Data Touched

- `email_templates.layout_mode`
- `email_templates.layout_html`
- `marketing_campaign_emails.layout_html_snapshot`
- Email template model, controller, renderer, routes, view, default templates, and tests.
- Marketing snapshot builder/model/renderer integration, campaign editor view, and tests.
- `package.json`, `package-lock.json`, Vite-managed JavaScript/CSS assets.

The schema migration is additive. Existing fragment templates become branding-managed. Existing
complete HTML documents become custom layouts and retain their rendered content. Existing Marketing
rows are backfilled inside the bounded additive migration before the new renderer becomes the
authoritative path.

## Permissions

No new permission is added. Existing Admin middleware protects Email template create/edit/update and
the new preview endpoint. Marketing continues to require `marketing.campaign.edit` for body changes.
The editor does not add upload or browsing permissions.

## Security

- Preview iframes use an empty `sandbox`; no script or same-origin capability is granted.
- Client editor cleaning is defense in depth only.
- Server validation rejects scripts, iframes, objects, embeds, forms/form controls, inline event
  handlers, unsafe URL schemes, CSS imports/expressions, and full-document tags in body fragments.
- Layout validation requires exactly one reserved body slot.
- Jodit file upload, file browser, and base64 image insertion are disabled.
- Existing variable replacement behavior is preserved in this slice; context-aware variable escaping
  remains separate hardening work and must not be represented as solved here.

## Tests

- Template CRUD persists body, layout mode, and custom layout.
- Branding layouts follow Company Profile changes.
- Body/subject/plaintext edits do not freeze branding.
- Custom layouts remain unchanged after branding changes.
- Reset resumes branding-managed rendering.
- A custom layout requires exactly one body slot.
- Unsafe outbound HTML is rejected.
- Legacy full documents migrate without visible duplication.
- Preview renders visual HTML, uses the real renderer, and exposes an empty sandbox.
- Marketing freezes the materialized layout when a campaign email is created.
- Later template/branding changes do not alter an existing campaign email render.
- Jodit preserves template variables such as `{{ unsubscribe_url }}` through submitted HTML.
- Focused Email, Marketing, System-branding tests, build, Pint, syntax, and diff checks pass.

## Documentation

- Amend the approved Marketing RFC with the layout and snapshot contract.
- Add an ADR for canonical HTML storage, Jodit, layout modes, and preview safety.
- Update Email outbound-template Knowledge documentation.
- Update Marketing campaign-email snapshot documentation.
- Update `docs/TODO.md` and add a new `docs/human-review.md` entry without overwriting concurrent
  Mail completion work.

## Done Criteria

- [x] Admin can visually edit and source-edit `Body HTML`.
- [x] Admin can customize or reset the separately stored layout.
- [x] Branding-managed and custom layout behavior is covered by tests.
- [x] Preview renders unsaved server output and never displays escaped HTML source as the visual view.
- [x] Marketing campaign emails snapshot a materialized layout.
- [x] Existing data has a safe upgrade/backfill path and no template is silently overwritten.
- [x] Unsafe active HTML is rejected server-side.
- [x] Focused automated verification and frontend build pass on authoritative Dev.
- [x] Knowledge, TODO, ADR, and human-review records are updated.
- [ ] Browser review remains explicitly open until a named human completes it.

## Dev Verification 2026-08-24

- Database-only backup completed before migration. Migration `170000` ran successfully on Dev.
- Readback: 10 Email templates are branding-managed, all 6 existing Marketing campaign emails have
  a non-null materialized layout snapshot, and zero templates/snapshots fail the HTML policy.
- Focused automated checks passed 23 tests / 203 assertions across Email policy/template rendering,
  admin preview, Marketing snapshot/delivery/preview, API, and current-editor test send.
- Vite production build passed; Jodit is emitted as a page-loaded chunk. Blade view cache, route
  discovery, targeted PHP syntax, and diff checks passed.
- Automatic browser review reached the Dev login page but no authenticated browser session was
  available. No credential was requested or entered. `HR-2026-08-24-004` remains Pending for the
  visual, responsive, keyboard, and real-email checks.
