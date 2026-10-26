# Risk API Main promotion candidate

Status: Merged to Main and independently verified, 2026-10-26.
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

Merged PR: https://github.com/SveinT83/Nexum-PSA/pull/321
Merge SHA: bf5c7d0c608895031eea04aa9a65cfd7c8a7ba98
Merged tree: 16ddb17317171f0da4aab4f2fa743f8e2c35a797
The merged tree equals the tested candidate tree. GitHub reports merged/closed; origin/main
was fetched independently and matches this merge SHA. Production remains undeployed.

## Current handbook and editorial state

After Main merge, Risk Knowledge was synchronized and BookStack pages were updated
and independently read back: Overview 447/8, Settings 448/9, API Support 640/3 and
API Management 449/136. Existing employee inheritance remains intact; permissions
were not changed. Native BookStack automation is unavailable; personal publication
is the actual verified external result. Employee OpenWebUI retrieval is not claimed.
One existing editorial queue item was updated: Main merged, production not deployed,
with the explicit production/publication gate retained. No website content was published.
All 2159 preflight dirty-file identities were checked; only this scoped Risk work changed.
The authoritative Dev HEAD/index and unrelated work are preserved.
