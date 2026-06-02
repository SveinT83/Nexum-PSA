The Risk domain manages internal and client-specific risk assessments.

Risk assessments group risk items. Each risk item stores a current snapshot and a history timeline.
Likelihood and impact create the score, and item updates preserve the audit trail when risk state
changes over time.

Risk can be used for:

- Internal operational risk.
- Client-specific assessments.
- Security reviews.
- Project or service risk tracking.

Risk assessment routes live under `/tech/risk`.

## Current Model

- **Risk Assessment** is the container for a risk analysis. It may be internal or linked to a Client.
- **Risk Item** is the current snapshot of one identified risk.
- **Risk Item Update** is the history timeline for score and status changes.

Likelihood and impact are scored from 1 to 5. The current score is calculated as likelihood multiplied
by impact.

Once a Risk Item has history, likelihood, impact, and status should be changed through the item update
workflow rather than through descriptive item edits. This protects the audit trail.

## API

Risk exposes API routes under `/api/v1/risk` for trusted integrations, reporting workflows, and future
AI-assisted analysis.

Implemented scopes:

- `risk.read`: list and view risk assessments and risk items.
- `risk.create`: create risk assessments and risk items.
- `risk.update`: update risk assessments, risk items, and risk item history.

Implemented routes:

- `GET /api/v1/risk/assessments`
- `GET /api/v1/risk/assessments/{assessment}`
- `POST /api/v1/risk/assessments`
- `PUT /api/v1/risk/assessments/{assessment}`
- `PATCH /api/v1/risk/assessments/{assessment}`
- `POST /api/v1/risk/assessments/{assessment}/items`
- `GET /api/v1/risk/items/{item}`
- `PUT /api/v1/risk/items/{item}`
- `PATCH /api/v1/risk/items/{item}`
- `POST /api/v1/risk/items/{item}/updates`

API create and update operations use the same action classes as the Tech UI.

`PUT` and `PATCH /api/v1/risk/items/{item}` are for descriptive fields such as title, description,
recommended actions, conclusion, category, and next review date.

`POST /api/v1/risk/items/{item}/updates` is the correct endpoint for changing likelihood, impact, and
status after the item has been created.
