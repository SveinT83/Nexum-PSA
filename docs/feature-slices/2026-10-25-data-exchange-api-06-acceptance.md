# Feature Slice: Complete contract and handbook verification

Status: Merged to Main (PR #317); human review Reviewed (Svein Tore, 2026-10-25)
Date: 2026-10-25
Parent: [Approved RFC](../rfc/2026-10-25-data-exchange-full-api-parity.md)
Owner: Codex
Coverage: DX11-DX12

## Goal

Audit/API contract, native schema/concurrency, HTTP, regressions, handbook and HR.

## User-Visible Behavior

Official typed APIs implement the same supported workflow as browser/domain actions, with exact
read-back and current actor authority. Unsupported automatic import pickup is reported honestly.

## Scope

Audit/API contract, native schema/concurrency, HTTP, regressions, handbook and HR. Preserve shared dirty Economy and Tripletex work.

## Out Of Scope

New providers/sources, arbitrary deletion/SQL, production deployment, Main promotion and activation.

## Data Touched

Existing DataExchange models, private generated files and additive durable command/occurrence tables
where needed. Credentials stay in Integration. No live customer/provider test writes.

## Permissions

No widened employee, token or provider authority. Scope and current permissions are both required; replay rechecks rights.

## Tests

Meaningful Dev success, validation, scope/actor denial, exact persistence/side effects and affected
browser/Economy/Client regressions. Native lock/unique checks for data/concurrency changes.

## Documentation

Module/API/Knowledge, RFC, TODO and verification report; BookStack actual read-back and inherited
employee access in acceptance. HR-2026-10-25-DATA-EXCHANGE-API is Reviewed by Svein Tore, 2026-10-25.

## Done Criteria

All assigned matrix rows map to typed methods and passing tests. No silent failing-test deferral.
Required handbook publication/read-back and runtime/native checks must be evidenced or blocked.

## Completion evidence

Implemented on authoritative Dev. Final affected set: 82 tests / 1011 assertions; broader affected set: 310 / 3233; API administration: 5 / 316. Sets overlap. Native locking, exact migration, trusted HTTPS, 35-method Swagger and BookStack read-back are verified in [the delivery report](../plans/2026-10-25-data-exchange-api-verification.md). HR-2026-10-25-DATA-EXCHANGE-API is Reviewed; Main promotion is authorized; runtime activation is separate.
