Knowledge settings are managed from Admin -> Knowledge Settings.

The settings page controls defaults for manually created Knowledge articles and pages:

- Default visibility.
- Default status.
- Default review interval.
- Default sort priority.

These defaults are applied to the create form and to the shared article creation action. This keeps
the Livewire editor, fallback HTTP route, and future API-like creation flows aligned.

BookStack connection behavior is not configured here. BookStack is an integration and remains managed
from the Integration settings area.

Repository documentation sync keeps its own source metadata and sync behavior. Knowledge settings only
control manual article defaults.


## API Defaults On Dev (2026-10-27)

1. Read `GET /api/v1/knowledge/settings` with `knowledge.settings.read`.
2. Submit all four defaults to `PUT /api/v1/knowledge/settings` with
   `knowledge.settings.write`, an Idempotency-Key and, when editing an observed value, If-Match.
3. Read back the returned normalized defaults. Both actions require the user's current
   `knowledge.manage_settings` permission and share validation with the browser settings page.

Defaults apply to new proposals, not automatic approval/publication. Provider configuration remains
Integration-owned. Main promotion and production deployment are separate from Dev verification.
