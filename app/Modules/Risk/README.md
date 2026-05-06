# Risk Module

The Risk module owns the risk analysis workflow for tdPSA. It lets technicians create risk assessments, attach them to either an internal company scope or a client, register individual risk items, track risk updates over time, approve completed assessments, and export a PDF report.

This module follows the tdPSA module architecture rules:

- Routes live in `app/Modules/Risk/routes.php`.
- Controllers live in `app/Modules/Risk/Controllers`.
- Views live in `app/Modules/Risk/Views`.
- Business operations live in `app/Modules/Risk/Actions`.
- Read/query composition lives in `app/Modules/Risk/Queries`.
- Module tests live in `app/Modules/Risk/Tests`.

Do not add Risk routes to `routes/web.php`, `routes/tech.php`, or any new file under `routes/`. Do not move Risk views back to `resources/views`, and do not move Risk controllers back to `app/Http/Controllers`.

## Purpose

The domain models a risk assessment as a container with many risk items.

A `RiskAssessment` answers:

- What scope is being assessed?
- Is the assessment internal or linked to a client?
- What is the current lifecycle status?
- Is every risk item addressed so the assessment can be approved?
- What is the aggregate risk score for reporting?

A `RiskItem` answers:

- What specific risk was identified?
- What category does it belong to?
- How likely is it?
- What impact does it have?
- What is the calculated risk score?
- What recommended actions and conclusions have been recorded?
- What is the current mitigation status?
- When should it be reviewed again?

A `RiskItemUpdate` is the audit trail for a risk item. Updates preserve the timeline of decisions and scoring changes instead of overwriting the history.

## Directory Map

```text
app/Modules/Risk/
    Actions/
        ApproveRiskAssessment.php
        DeleteRiskItemUpdate.php
        StoreRiskAssessment.php
        StoreRiskItem.php
        StoreRiskItemUpdate.php
        UpdateRiskAssessment.php
        UpdateRiskItem.php
    Controllers/
        Tech/
            RiskController.php
    Queries/
        RiskAssessmentQuery.php
    Tests/
        Feature/
            RiskSystemTest.php
    Views/
        Tech/
            index.blade.php
            form.blade.php
            show.blade.php
            pdf.blade.php
            items/
                show.blade.php
    routes.php
    README.md
```

## Route Surface

All Risk routes are registered inside `app/Modules/Risk/routes.php`. They are loaded by the application through the existing module route loader in `routes/tech.php`, so route names are prefixed by the surrounding `tech.` group.

| Method | URI | Route name | Purpose |
| --- | --- | --- | --- |
| GET | `/tech/risk` | `tech.risk.index` | List assessments in the active context |
| GET | `/tech/risk/create` | `tech.risk.create` | Show assessment create form |
| POST | `/tech/risk/store` | `tech.risk.store` | Create assessment |
| GET | `/tech/risk/show/{risk}` | `tech.risk.show` | Show assessment detail and risk items |
| GET | `/tech/risk/edit/{risk}` | `tech.risk.edit` | Show assessment edit form |
| PUT | `/tech/risk/update/{risk}` | `tech.risk.update` | Update assessment metadata and scope |
| DELETE | `/tech/risk/destroy/{risk}` | `tech.risk.destroy` | Soft-delete assessment |
| POST | `/tech/risk/show/{risk}/items` | `tech.risk.items.store` | Add a risk item |
| GET | `/tech/risk/items/{item}` | `tech.risk.items.show` | Show risk item detail/history |
| PUT | `/tech/risk/items/{item}` | `tech.risk.items.update` | Edit non-historical risk item fields |
| DELETE | `/tech/risk/items/{item}` | `tech.risk.items.destroy` | Soft-delete risk item |
| POST | `/tech/risk/items/{item}/updates` | `tech.risk.items.updates.store` | Add history update and current-state change |
| DELETE | `/tech/risk/updates/{update}` | `tech.risk.updates.destroy` | Delete a history update and resync current state |
| POST | `/tech/risk/show/{risk}/approve` | `tech.risk.approve` | Approve an assessment when all risks are addressed |
| GET | `/tech/risk/show/{risk}/pdf` | `tech.risk.pdf` | Render inline PDF report |

## Data Model

The module currently uses existing Eloquent models in `app/Models/Risk`:

- `App\Models\Risk\RiskAssessment`
- `App\Models\Risk\RiskItem`
- `App\Models\Risk\RiskItemUpdate`
- `App\Models\Risk\RiskItemLink`

The related migrations already exist under `database/migrations`:

- `2026_04_03_165000_create_risk_assessments_table.php`
- `2026_04_03_165000_create_risk_items_table.php`
- `2026_04_03_165001_create_risk_item_links_table.php`
- `2026_04_03_175543_create_risk_item_updates_table.php`

Important model behavior:

- `RiskItem::saving()` calculates `score = likelihood * impact`.
- `RiskItemUpdate::saving()` calculates the historical update score the same way when likelihood and impact are present.
- `RiskAssessment::is_approvable` returns true only when the assessment has items and no item has status `open`.
- `RiskAssessment::total_score`, `risk_percentage`, `highest_risk_item`, and `score_badge_class` are derived from related items.

## Workflow

### Assessment Creation

1. A technician opens `tech.risk.create`.
2. The shared form view receives a new empty `RiskAssessment` instance.
3. The controller validates title, description, scope, and optional client.
4. `StoreRiskAssessment` creates the record.
5. New assessments always start with status `new`.
6. Internal assessments store `client_id = null`; client assessments store the selected client id.

### Assessment Listing

`RiskAssessmentQuery` applies the same context rules used elsewhere in tdPSA:

- `session('only_internal')` limits the list to internal assessments.
- `session('active_client_id')` limits the list to that client.
- If neither session value is set, all assessments are listed.

The query eager-loads `client` and `items` because the index needs client labels and score summaries.

### Risk Item Creation

1. A technician adds an item from the assessment detail modal.
2. The controller validates title, category, likelihood, impact, status, optional text fields, and optional next review date.
3. `StoreRiskItem` creates the item and immediately creates the first `RiskItemUpdate`.
4. The first update is the historical baseline with note `Initial risk identified`.
5. If the parent assessment was `new`, it is moved to `in_progress`.

### Risk Item Updates

Risk items are treated as living records:

- The `risk_items` row stores the current snapshot for simple listing and filtering.
- The `risk_item_updates` rows store the audit trail.

When a new update is stored, `StoreRiskItemUpdate`:

1. Creates a `RiskItemUpdate` with note, status, likelihood, and impact.
2. Copies the update status/likelihood/impact onto the parent `RiskItem`.
3. Updates `next_review_at` when provided.
4. Moves the assessment back to `in_progress` if it was `new` or `approved`.

### Editing Risk Items

`UpdateRiskItem` intentionally separates descriptive changes from historical scoring changes.

If the item already has update history, these fields are locked in the edit action:

- `likelihood`
- `impact`
- `status`

Use the "Add Update" workflow for those fields. This preserves the audit trail and prevents silent rewriting of risk history.

The edit action still allows changes to:

- `title`
- `description`
- `recommended_actions`
- `conclusion`
- `category_id`

When those descriptive fields change, the action creates a history note explaining what changed.

### Deleting Updates

Deleting an update is limited to Superuser or the update creator. After deletion, `DeleteRiskItemUpdate` reloads the latest remaining update and synchronizes the parent `RiskItem` current snapshot with it.

This is important because the list and detail screens read the current state from `risk_items`, while the audit trail lives in `risk_item_updates`.

### Approval

`ApproveRiskAssessment` only approves assessments that satisfy `RiskAssessment::is_approvable`.

Current rule:

- The assessment must have at least one item.
- No item may have status `open`.
- Items with `mitigated` or `accepted` are considered addressed.

Approval stores:

- `status = approved`
- `approved_at = now()`
- `approved_by = Auth::id()`

## Status Values

Assessment statuses currently used by the module:

- `new`: created but no work has been registered yet
- `in_progress`: at least one item/update exists or an approved assessment was reopened by a change
- `approved`: finalized and signed off

Risk item statuses currently accepted by validation:

- `open`: unresolved risk
- `mitigated`: actions have reduced or resolved the risk
- `accepted`: risk is consciously accepted

Existing migrations use older defaults like `active` or `pending`. The module writes the newer statuses above. Be careful when adding reports or filters: old database rows may still contain older values unless migrated or normalized.

## Authorization

General access is handled by the surrounding tech route group and `tech` middleware.

Additional module-level checks:

- Only Superuser can delete an assessment.
- Only Superuser can delete a risk item.
- Superuser or the creator of a risk item baseline can edit the item details.
- Superuser or the creator of a risk item update can delete that update.

The module currently checks these permissions in the controller. If the authorization surface grows, consider moving the rules into policies.

## Views

The module uses namespaced views through `AppServiceProvider`:

```php
view('risk::Tech.index')
view('risk::Tech.form')
view('risk::Tech.show')
view('risk::Tech.items.show')
view('risk::Tech.pdf')
```

The provider registers each module view directory using the lower-case module folder name as the namespace. For `app/Modules/Risk/Views`, the namespace is `risk`.

## PDF Export

PDF export is handled directly in `RiskController::exportPdf()` using Dompdf.

The method:

1. Loads the assessment, items, categories, updates, creators, client, and approver.
2. Groups items by category.
3. Builds a summary array for the report.
4. Renders `risk::Tech.pdf`.
5. Streams the PDF inline with `Content-Type: application/pdf`.

If PDF generation becomes more complex, move the summary and Dompdf setup into a dedicated action or service inside this module.

## Tests

The module test file is:

```text
app/Modules/Risk/Tests/Feature/RiskSystemTest.php
```

Run module tests with:

```bash
php artisan test --testsuite=Modules --filter=RiskSystemTest
```

Current local environment note: at the time this module was documented, the test command could not execute because the PHP runtime did not have the SQLite PDO driver installed. `php -m` showed `PDO` and `pdo_mysql`, but not `pdo_sqlite`. The failure was:

```text
could not find driver (Connection: sqlite)
```

Install/enable the SQLite PDO extension or configure testing to use an available test database before relying on test results.

## Extension Points

Likely future work:

- Add policies for assessment/item/update authorization.
- Add module-local model classes if tdPSA later moves all domain models from `app/Models` into `app/Modules`.
- Add filters for status, category, score range, client, and review due date.
- Add dashboards for high-risk clients and overdue reviews.
- Add risk links to Documentation, Assets, Tickets, Contracts, or other domain records using `RiskItemLink`.
- Add recurring review reminders based on `next_review_at`.
- Add normalization/migration for older assessment/item statuses.
- Add unit tests for every action class once the database test driver is available.

## Developer Notes

- Keep controllers thin. Put business changes in `Actions`.
- Keep list/query decisions in `Queries`.
- Keep all Risk routes in `app/Modules/Risk/routes.php`.
- Keep all Risk Blade views in `app/Modules/Risk/Views`.
- Preserve the history model: do not update likelihood, impact, or status without creating a `RiskItemUpdate`.
- When adding a new UI surface, use the existing `Tech`, `Admin`, and `Client` folder split required by the architecture standard.
