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
