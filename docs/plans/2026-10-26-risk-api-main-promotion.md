# Risk API Main promotion candidate

Status: Candidate verified; Main merge explicitly authorized by Svein Tore on 2026-10-26.
Human review: HR-2026-10-26-RISK-API Reviewed.
Production deployment and provider/runtime activation are not part of this authorization.

The isolated candidate starts from Main 3af91d5bce15c46cf3184d319cde148835297cd9.
It contains only the approved Risk implementation, five matching ability entries,
Risk-only shared handbook/TODO/human-review sections and its planning/evidence.

## Candidate verification

- Risk: 26 tests / 3246 assertions, 59.33 seconds.
- Affected regression set: 165 tests / 4878 assertions, 399.50 seconds.
  These suites overlap; their totals are not additive.
- 38 changed PHP files pass syntax and Pint.
- Two whitespace-only method argument changes match identical semantic PHP token signatures.
- All 17 browser routes and 27 classified API methods link to matrix/scopes/Swagger/tests/read-back
  in 2026-10-26-risk-api-coverage.json.
- Native candidate registration matches all 27 scope ceilings and actor permissions.
- All 37 schemas exactly match native Swagger.
- The tracked static Swagger updates Risk paths/schemas only; other domain paths are preserved.
- Existing Main TODO/human-review entries and API Management outside Risk are preserved verbatim.
- The large shared Dev working copy is not committed or reset.

## Deployment separation

This candidate is code promotion only. The Dev migration was already applied as batch 27.
A later separately authorized production deployment requires the exact migration:

    php artisan migrate --path=database/migrations/2026_10_26_010000_create_risk_api_commands.php --force
    php artisan optimize:clear
    php artisan l5-swagger:generate

Then verify trusted HTTPS JSON/PDF/Swagger and permission denials. No frontend build,
queue restart, scheduler change or provider activation is required.
Preserve command tables and APP_KEY; rollback refuses nonempty command evidence.
High-volume production load behavior remains unmeasured.

The exact merged PR/SHA and final read-back will be recorded after GitHub confirms the merge.
