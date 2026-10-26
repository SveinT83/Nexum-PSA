Risk settings control defaults for future records. They do not rewrite existing assessments or history.

## Availability and prerequisites

The full settings API is implemented on Dev as of 2026-10-26. Main and production promotion remain
separately authorized. HR-2026-10-26-RISK-API was accepted by Svein Tore on 2026-10-26. The browser settings page already exists.

Use an active employee account with **risk.manage_settings**. The Admin location does not make
a role name a substitute for this permission.

## Change defaults

1. Open **Admin -> Risk Settings**.
2. Review **Assessment Defaults** and the item defaults.
3. Set the six values below and save.
4. Reopen the page and verify the saved values.
5. Create a disposable assessment/item to check the intended future defaults.

| Setting | Supported values |
| --- | --- |
| Default assessment scope | internal or client |
| Default assessment status | new, open or in_progress |
| Default item likelihood | 1 through 5 |
| Default item impact | 1 through 5 |
| Default item status | open, mitigated or accepted |
| Default item review interval | 1 through 3650 days |

Client scope still requires selection of an active Client at creation. Score is always calculated
from likelihood multiplied by impact. Approval cannot be supplied as a default lifecycle status.

## API and verification

GET /api/v1/risk/settings requires **risk.settings.read** and the current settings permission.
PUT/PATCH of the same path requires **risk.settings.update**, a stable **Idempotency-Key** and
the current **revision** in **If-Match**. PUT supplies all six keys; PATCH preserves omitted keys.
GET returns effective defaults without initializing a missing settings record.

After an API save, inspect the returned defaults and revision. A stale revision returns 409:
reload, review the concurrent change and make a deliberate new command. A missing revision returns
428; invalid or missing command keys and invalid settings return 422. See **Risk API Support**
for the exact command procedure.
