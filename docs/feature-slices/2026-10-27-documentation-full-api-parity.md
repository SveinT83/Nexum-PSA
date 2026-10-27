# Feature Slices: Full Documentation API Parity

Status: Done On Dev (all five sequential slices)
Date: 2026-10-27
Parent: docs/rfc/2026-10-27-documentation-full-api-parity.md
Owner: Codex
Human review: HR-2026-10-27-DOCUMENTATION-API Reviewed by Svein Tore, 2026-10-27

## Goal And User-Visible Behavior
Every supported Documentation web read/write/action and portal read is possible
through an official typed API with equivalent rights and side effects.

## Sequential Scope
1. Fresh actor rights, route ownership, strict filters, context and template selection.
2. Canonical vendors/suppliers/manufacturers, carrier validation and tracking preview.
3. Local encrypted replay, exact conflicts, shared visibility/audit/notifications.
4. Membership/site-scoped portal list/detail with safe response fields.
5. Runtime/Swagger equality, regression, native/HTTPS evidence and handbook read-back.

## Out Of Scope
#275 immutable lifecycle, remote carrier execution, new rights, Main/production.

## Data Touched
Existing domain tables and additive Documentation command/mutex tables. No backfill.

## Permissions
Existing documentation.* and taxonomy.manage_categories plus explicitly scoped PATs.
Portal uses own active account/contact/membership and no staff authorization.

## Tests
Feature tests for actual HTTP lifecycle, current actor/token rights and revocation,
field/scope validation, customer isolation, notifications/audit, idempotency,
staleness, encrypted receipts, response schemas and runtime contract.

## Documentation And Done Criteria
All five slices verified; current README/Knowledge, actual BookStack read-back,
coverage/evidence, TODO, HR and gated website handoff. Human review remains separate.

## Final Verification

All five slices pass: 548 tests / 8201 assertions, no failures; one existing dedicated-MariaDB contract skip.
33 typed HTTP operations, 40 schemas, native MySQL, trusted HTTPS and actual
Knowledge/BookStack content/access read-back verified. HR is Reviewed by Svein Tore, 2026-10-27.
No Main/production changes or provider activation. See the verification report.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Dev handoff and its human checklist
with "Godkjenner". The review gate is satisfied. Main/production actions are not
authorized by this acceptance; no individual manual scenario is claimed as witnessed.

## Verified Main Promotion (2026-10-27)

Svein Tore explicitly authorized "Merge til main". PR #329 is merged:
https://github.com/SveinT83/Nexum-PSA/pull/329
Main merge: c6249f422f74abe333a9b0c95d460adeb7193b68
Tested and merged tree: 64d7176610701a931bbf198787a711f1b17da071; exact equality independently confirmed by fresh fetch.
Isolated Main candidate: 545 passed / 8135 assertions, zero errors/failures.
One existing Storage MariaDB identity-contract test skipped for missing dedicated
test credentials. All 33 typed HTTP operations and 40 schemas verified; 37 PHP
syntax/Pint checks pass. Shared Dev code, unrelated changes and staged index preserved.
HR-2026-10-27-DOCUMENTATION-API remains Reviewed. No Main action remains.
Production deployment and provider/worker/runtime activation remain separate.
This follow-up changes documentation/status only; application tests were not rerun.
