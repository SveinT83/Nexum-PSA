# ApiController

Controller file: `app/Modules/Integration/Controllers/Admin/ApiController.php`

## Purpose (current state)
This document describes how `ApiController` works today, based on the current implementation and its active routes.

## What it does today
- Handles requests for the **Tech Admin** domain.
- Is reachable through routes defined in `routes/tech.php`, `routes/techAdmin.php`, `routes/api.php`, or `routes/web.php` depending on endpoint type.
- Uses the current middleware model already defined for that route group (`auth`, `tech`, `admin`, `auth:sanctum`, or public where configured).

## Route and access context
- Verify exact mapped endpoints using `php artisan route:list`.
- Internal UI endpoints are expected to run under `/tech/...` when part of the technician/admin workspace.

## Related models/views/docs
- Some actions in this controller already have partial model/view documentation in nearby folders.
- Related documentation should be expanded when new actions are added.

## Gaps vs `nexum-psa-v1.md`
The MVP plan (`nexum-psa-v1.md`) requires additional maturity across routing, ticket intake, settings completeness, and observability.

Potential missing/partial items to validate for this controller:
1. Explicit acceptance criteria mapping (which endpoint satisfies which MVP criterion).
2. Clear fallback behavior documentation (default queue/category/priority where relevant).
3. Error handling matrix (401/403/404/422/500) per action.
4. Operational notes for support (logs, troubleshooting, expected side effects).
5. Test coverage notes (feature tests and critical path validation).

## Maintenance rule
- Keep this file updated whenever methods, validation rules, side effects, or route bindings are changed.
- File naming follows controller naming: `ApiController.md`.
