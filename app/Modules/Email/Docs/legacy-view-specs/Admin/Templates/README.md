# Email Templates

Email templates define reusable outbound email content for tickets, system notifications, and future workflow/rule driven messages.

## Current Version 1 Scope

- Templates are stored in the database in `email_templates`.
- The Email module owns the model and CRUD because outbound email rendering/sending belongs to Email.
- The UI is exposed through the global Templates hub so admins manage templates from one place.
- Seeded defaults give new installations practical templates immediately.
- Outbound flows should reference stable `scope` + `key` pairs, not database IDs.

## Seeded Defaults

- `tickets / ticket_reply`
- `tickets / ticket_created`
- `system / system_notification`

These are seeded by `Database\Seeders\EmailTemplateSeeder`.

## Design Direction

For version 1, each use case should normally have one default template that admins can edit.

Creating many templates is useful during development, but the future product direction may restrict the UI to editing seeded templates only unless routing rules are added.

Future template selection rules may choose templates by:

- client
- brand
- language
- ticket queue
- workflow/rule condition
- system notification type

## Future Work

- Branding blocks and sender identity.
- Language-aware templates.
- Template preview with sample data.
- Variable validation against each template scope.
- Per-client or per-queue template routing.
- Version history and audit log.
- Shared partials such as footer, signature, and disclaimer.
