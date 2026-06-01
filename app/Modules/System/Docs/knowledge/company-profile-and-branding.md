Company Profile and Branding store the organization identity used by the Nexum PSA tech workspace.

Organization data is managed from `Admin -> System -> Company Profile`.

Visual identity is managed from `Admin -> System -> Branding`.

## What It Controls

- Company display name in the tech header.
- Optional legal name and organization number.
- Address, support email, phone, and website.
- Header logo, managed from Branding.
- Primary, secondary, and accent brand/action colors, managed from Branding.

## Storage

Company Profile is stored as a JSON payload in `common_settings`:

- `type`: `company_profile`
- `name`: `branding`

This keeps branding as platform configuration instead of a separate business entity.

Uploaded logos are stored on the public storage disk under `company-branding/`.

## Admin Surfaces

Company Profile owns organization data:

- Company display name.
- Legal name.
- Organization number.
- Address.
- Support email.
- Phone.
- Website.

Branding owns visual identity:

- Fallback logo.
- Light mode logo.
- Dark mode logo.
- Primary action color.
- Secondary action color.
- Accent color.
- Light and dark shell surface colors.
- Primary and secondary button colors.
- Preview of common branded UI elements.
- Reset to default for logos and theme colors without clearing organization details.

## Header Behavior

The tech layout reads the Company Profile on render.

If no settings exist, the shell falls back to:

- Company name: `Nexum PSA`
- Primary color: Bootstrap primary blue
- Secondary color: Bootstrap secondary gray
- Accent color: Bootstrap teal
- Logo: generated building icon mark

## Theme Behavior

The tech layout writes branding colors into CSS variables on every render:

- `--nexum-brand-primary`
- `--nexum-brand-secondary`
- `--nexum-brand-accent`
- `--bs-primary`
- `--bs-secondary`
- Bootstrap RGB variants for utility classes.

`resources/css/custom-color.css` must use these variables instead of hardcoded brand colors. This
keeps buttons, links, active navigation, borders, page header accents, and shell surfaces aligned
with the colors configured in Company Profile.

Brand/action colors and surface colors must remain separate concepts.

Future Branding work should add dedicated settings for:

- Additional Bootstrap component colors beyond the most used shell and button elements.

Current configurable light and dark surfaces:

- Header background and text.
- Footer background and text.
- Left sidebar background and text.
- Main background.
- Right sidebar background and text.
- Page header background and text.
- Card header background and text.
- Content background.
- Primary button background and text.
- Secondary button background and text.

## Deployment Notes

Branding does not require a dedicated migration because it uses `common_settings`.

Production environments must have Laravel public storage linked if logo uploads should be visible:

```bash
php artisan storage:link
```

After deploying layout or route changes, clear cached bootstrap files:

```bash
php artisan optimize:clear
```
