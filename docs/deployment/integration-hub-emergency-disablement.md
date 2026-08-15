# Integration Hub Emergency Disablement Runbook

Status: Ready for human review
Related: GitHub #216 and `HR-2026-08-15-001`

## Scope

Emergency controls stop protected Integration Hub reads at global, capability/version,
Integration, Client, or Site scope. They do not roll back an external side effect and do not replace
credential revocation. The first Plesk slice is provider-read-only, but controls are still checked
both centrally and directly inside the adapter.

Operator endpoints require an explicit narrow Sanctum token with
`integration-hub.controls.manage` and the User permission
`integration.ai_governance_manage`. A web session, service token, or `*` token is rejected.

## Assess read-only state

Use the intended environment and inspect:

```text
GET /api/v1/integration-hub/readiness
GET /api/v1/integration-hub/controls?per_page=50
GET /api/v1/integration-hub/audit-events?correlation_id=<uuid>
```

Do not copy bearer values into incident notes. Record only token record ID, actor ID, correlation
ID, control key, and sanitized reason.

## Disable

POST one exact control with a machine-safe reason code:

```json
{
  "scope_type": "global",
  "disabled": true,
  "reason_code": "suspected_credential_exposure",
  "reason_summary": "Read access stopped while service credentials are rotated.",
  "correlation_id": "f30d258d-e188-45b0-8af7-80d67ebf4628"
}
```

For narrower scope use one of:

- capability: include `capability_key` and `capability_version`;
- integration: set `scope_id` to the Integration UUID;
- client: set `scope_id` to the numeric Client ID;
- site: set `scope_id` to the numeric Site ID.

The API validates that the target exists in the current installation. It records old and new
disable state, operator reason, actor, and correlation. A global disable also advances the grant
invalidation epoch, so grants issued before it cannot become valid after re-enable.

## Verify disablement

1. Read the control and confirm `disabled=true`.
2. Confirm readiness is unavailable for a global control.
3. Request a new narrow grant if grant issuance remains in scope, then call the affected read and
   confirm `emergency_control_active` without a provider request.
4. For Plesk, verify provider request counters/logs did not advance during the blocked call.
5. Confirm distinct operator-change and denied-read audit records share the intended correlation.

Do not test against a production provider merely to prove disablement.

## Incident actions

Depending on cause, separately revoke exposed issuer/service tokens, rotate the execution-grant
key, disable the affected Integration credential, or stop the MCP process. Emergency controls do
not delete or reveal credentials.

Inspect sanitized audit by correlation, capability, result status, and scope. Raw application logs
may contain unrelated customer data and are not the primary Hub audit source.

## Re-enable

Re-enable only after the cause is corrected and a named operator has reviewed the intended scope.
POST the same exact scope with `disabled=false` and a new reason:

```json
{
  "scope_type": "global",
  "disabled": false,
  "reason_code": "incident_resolved",
  "reason_summary": "Credentials rotated and read-only smoke verification passed.",
  "correlation_id": "1d35b684-ad8a-4cbb-9719-45338928ed23"
}
```

Then confirm readiness, issue a new grant, run the minimal identity smoke, and verify a distinct
re-enable audit event. Do not reuse a grant issued before the global disable.

## Failure and recovery

- If the operator API is unreachable, keep the MCP service stopped and provider credentials
  disabled while restoring Nexum. Do not bypass policy by calling Plesk directly from MCP.
- If the database is unavailable, controls cannot be verified and protected reads must be treated
  as unavailable.
- If an in-flight provider read completes after disablement, preserve its audit/Execution result;
  do not claim the control cancelled or compensated it.
- If the control target was wrong, create the correct control first, then explicitly re-enable the
  incorrect one with its own reason and audit trail.
