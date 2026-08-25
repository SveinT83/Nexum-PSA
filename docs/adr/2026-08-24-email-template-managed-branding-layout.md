# ADR: Email Template Managed Branding Layout

Status: Accepted
Date: 2026-08-24
Decision owners: Email, System/Branding, and Marketing
Related: `docs/rfc/2026-06-09-marketing-domain-email-campaigns.md`

## Context

Email templates currently store subject, an HTML body fragment, and plaintext. The renderer adds a
hardcoded HTML document around fragments, while complete HTML documents bypass that wrapper. The
admin page cannot edit the wrapper and its preview double-escapes rendered HTML. Marketing copies
body content into campaign snapshots but has no separate layout snapshot.

The product needs a proper HTML editor, editable layout HTML, branding-derived defaults, an explicit
manual-customization boundary, and stable campaign output. The editor choice must work with Blade,
Bootstrap, Vite, and canonical HTML without requiring a cloud account or imposing a copyleft choice
on the proprietary product.

## Decision

### Canonical Content

Rendered HTML remains the canonical portable content format. Email template content is stored in
`body_html`. The outer document is represented separately by `layout_mode` and `layout_html`.
Custom layouts contain exactly one reserved `{{ email_body }}` slot.

### Branding Ownership

`layout_mode=branding` asks `EmailTemplateRenderer` to build the layout from current
`CompanyProfileSettings` at render time. Organization branding is used, not the signed-in user's
theme preference. Editing ordinary template content does not change this mode.

`layout_mode=custom` stores a materialized layout. Switching to custom starts from the current
branding layout, then freezes it. Resetting clears the stored layout and returns to branding mode.
The renderer never silently rewrites custom HTML after a branding change.

### Marketing Stability

When Marketing creates a campaign email, it stores a materialized layout snapshot together with its
existing subject/body/plaintext/variables snapshot. This freezes both branding-managed and custom
template layouts for that campaign email. Later reusable-template or company-branding edits cannot
change an already created campaign email.

### Editor Adapter

Jodit is self-hosted from npm and bundled by Vite. It is MIT licensed, framework-independent, works
with a normal textarea, and supplies visual plus HTML source/split modes without a cloud key. Upload,
file-browser, and base64-image features are disabled.

The Blade component and canonical HTML fields are editor-adapter boundaries. A future approved
block-editor slice may replace Jodit without migrating content ownership or changing outbound jobs.

### Preview And Validation

Unsaved preview goes through the server `EmailTemplateRenderer`. The iframe is read-only and uses an
empty sandbox. Client cleanup does not replace server validation. Outbound template HTML rejects
active browser content and unsafe URL schemes; body fragments cannot contain full-document tags.

## Alternatives Considered

- Keep the textarea and only fix escaping: does not satisfy visual/source editing or editable layout.
- Put the full layout and marketing content in one field: breaks ownership and snapshot clarity.
- Infer customization from timestamps or body changes: body editing would incorrectly freeze brand
  layout and historical intent cannot be inferred safely.
- Separate style/header/body/footer database fields: easier for simple presets but less flexible and
  makes arbitrary email-client-compatible layouts harder to preserve.
- TinyMCE or CKEditor: technically capable, but current self-hosted licensing requires a GPL or
  commercial decision that is unnecessary for this slice.
- GrapesJS/MJML now: a strong future block-builder option, but it introduces editor project data,
  MJML compilation, asset workflows, and a broader shared-content platform beyond this focused slice.

## Consequences

- Email becomes the single owner of layout composition and validation.
- System/Branding supplies organization values and colors but does not own Email template records.
- Marketing owns immutable campaign layout snapshots, not live template references for rendering.
- One additive migration and a bounded backfill are required.
- Frontend build output grows due to the page-activated editor dependency.
- Preview is authoritative but adds debounced authenticated server requests while editing.
- Advanced custom layout authors must preserve the body slot and remain responsible for email-client
  compatibility inside the server safety policy.
- A future drag/drop platform remains possible through the editor adapter and canonical HTML, but is
  not silently promised by this slice.
