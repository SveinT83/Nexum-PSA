# Risk Module

Risk owns assessments, item snapshots, historical updates, approval, PDF and six configurable
defaults. The approved full parity target is documented in
docs/rfc/2026-10-26-risk-full-api-parity.md and ADR
docs/adr/2026-10-26-risk-shared-authority-and-command-integrity.md.

## Structure and ownership

Domain registration lives in routes.php; api.php is only the API loader shim.
Controllers are adapters for shared Actions and Support policies. Views remain in Views.
Existing business models remain in app/Models/Risk. Clients, WorkContext and Taxonomy keep ownership
of their entities. Integration owns API ability administration and Swagger delivery.

No provider calls, notification automation, new jobs, reminder scheduler, RiskItemLink management,
portal access, hard purge or restore surface is introduced.

## Workflow integrity

- Creation uses RiskSettings defaults. Assessment status can be new, open or in_progress.
  Client scope requires an active Client; internal scope rejects a supplied Client.
  Work Context is derived server-side. Metadata API PATCH preserves omitted fields.
- Item score is likelihood * impact, with each component from 1 to 5.
  Creation atomically adds initial history. An omitted review date uses the configured interval;
  explicit API null means no review date.
- Shared RiskPolicy refreshes current active employee permissions; API abilities are an additional
  ceiling. Descriptive editing requires Superuser or the first remaining history creator.
- Changed scoring/status with existing history is rejected; Add Update is the supported workflow.
  Same-current hidden fields remain compatible. Actual descriptive edits create one history note;
  no-op and replay do not create another row.
- History append copies state to the current item. History deletion requires the exact creator or
  Superuser plus risk.delete. Reconciliation selects newest created_at DESC, id DESC explicitly.
  Deleting the last entry preserves the current snapshot and invents no replacement owner.
- Approval requires a nonempty active item set consisting entirely of mitigated/accepted statuses.
  Unknown old statuses remain visible and block approval until reviewed; there is no status backfill.
  Already-approved records are not restamped. Changed item/history content reopens in_progress,
  preserving the last stamp as history. approval_valid identifies current sign-off.
- Assessment/item deletion is soft only, requires risk.delete and Superuser, and closes alternate
  item/history reads under the deleted parent. History deletion is a physical row deletion as before.

## API contract

The frozen catalogue in docs/plans/2026-10-26-risk-api-contract.json contains all 27 method/path
operations and 37 typed schemas. RiskApiOpenApi.php provides matching native Swagger attributes.
See Docs/knowledge/risk-api-support.md for the complete endpoint table, ability/current-right
mapping, filters, settings, error codes and command procedure.

GET/context/lookup/report never initialize settings or Work Context, change a browser session,
create commands or dispatch domain jobs. Independent item/history lists use bounded pagination
(default 15, maximum 100), stable ID tie-breaking and truthful totals. Legacy embedded resources
remain complete rather than silently truncated.

## Commands and concurrency

RiskMutex locks the singleton risk_api_mutex row before any browser/API mutation, then the active
parent assessment. Settings use the same mutex. Atomic local command effects and completed receipts
share that transaction. This conservative domain lock serializes Risk writes; it is deliberate for
beta integrity and may need measured performance tuning before high-volume use.

New approval/delete/settings commands require If-Match and Idempotency-Key (8-200 printable ASCII).
Legacy CRUD supports these optionally. Revisions hash full relevant state including history, so
same-second changes conflict. A receipt is bound to actor/token, operation, payload and revision.
Same key with changed input returns 409. Replay rechecks current authority and returns current
authorized state, never a second effect. GET operations/{command} exposes minimal outcome IDs
only to the original actor/token with current underlying rights.

RiskApiCommand context/result are encrypted and store minimal IDs/policy evidence, not copies of
risk notes. Keep APP_KEY and durable receipts through recovery. Do not delete receipts to retry.

## PDF

RenderRiskPdf materializes all permitted relationships in one database snapshot, groups categories,
uses the existing risk::Tech.pdf template and Dompdf, and returns application/pdf inline.
Thresholds remain 16+ Critical, 10+ High, 5+ Medium, otherwise Low. Caller templates/URLs/paths
are not accepted. Browser and API share this renderer.

## Verification and deployment

Run on authoritative Dev with umask 0002 and HOME=/tmp:

    php artisan test app/Modules/Risk/Tests

RiskSystemTest covers browser regressions. RiskApiParityTest covers operational positive/negative
cases and real responses for every contract. RiskApiContractTest compares exact routes and every
Swagger schema. Native MySQL locks/uniqueness, HTTPS, synthetic concurrent approval and rendered PDF
have separate evidence in docs/plans/2026-10-26-risk-api-verification.md.

The exact additive migration is 2026_10_26_010000_create_risk_api_commands.php (Dev batch 27).
For later explicitly authorized deployment:

    php artisan migrate --path=database/migrations/2026_10_26_010000_create_risk_api_commands.php --force
    php artisan optimize:clear
    php artisan l5-swagger:generate

No frontend build, queue restart, provider switch or scheduler change is required.
Migration rollback refuses to drop nonempty command evidence. Recover by preserving tables and
encryption key, resolving the application fault, then verifying receipts/current records.

Implementation is verified on Dev and merged to Main in PR #321, bf5c7d0c608895031eea04aa9a65cfd7c8a7ba98.
Human-review checklist HR-2026-10-26-RISK-API is Reviewed by Svein Tore (2026-10-26).
Production migration/deployment and production read-back remain separate and were not performed.
