The Company Profile stores the organization identity used by the Nexum PSA tech workspace.

It is managed from `Admin -> System -> Company Profile`.

## What It Controls

- Company display name in the tech header.
- Optional legal name and organization number.
- Address, support email, phone, and website.
- Header logo.
- Primary, secondary, and accent brand colors.

## Storage

Company Profile is stored as a JSON payload in `common_settings`:

- `type`: `company_profile`
- `name`: `branding`

This keeps branding as platform configuration instead of a separate business entity.

Uploaded logos are stored on the public storage disk under `company-branding/`.

## Header Behavior

The tech layout reads the Company Profile on render.

If no settings exist, the shell falls back to:

- Company name: `Nexum PSA`
- Primary color: Bootstrap primary blue
- Secondary color: Bootstrap secondary gray
- Accent color: Bootstrap teal
- Logo: generated building icon mark

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
