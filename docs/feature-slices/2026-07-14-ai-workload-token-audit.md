# Feature Slice: AI Workload Tokens And Access Audit

Status: Done
Date: 2026-07-14
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Codex

## Goal

Bind coordinator tokens to approved workloads and record privacy-conscious access/security evidence.

## User-Visible Behavior

Admins create read-only coordinator tokens for a named workload, see effective limits and policy,
review allowed/denied access events, rotate/revoke tokens, and configure finite audit retention.

## Scope

- Bind one coordinator token to one approved workload policy.
- Reject full-access and write abilities for coordinator tokens.
- Enforce token expiry, request range/page/rate limits, and optional network restrictions.
- Record mandatory metadata-only access events with stable decision/reason codes.
- Add optional encrypted local prompt/response retention, off by default and separate from audit.
- Add retention cleanup and restricted Admin review UI.

## Out Of Scope

- New worklog/stale endpoints.
- Exporting audit payloads to an external SIEM.
- Automatic rotation without a later approved workflow.

## Data Touched

Token/workload bindings, sanitized access events, optional encrypted payload-retention records, and
cleanup metadata. Authorization headers, credentials, and secrets are never stored.

## Permissions

Separate permissions for workload-token management, access-log review, and optional retained-payload
review.

## Tests

- Unbound, expired, broad, write-capable, and policy-incompatible tokens are denied.
- Allowed and denied requests produce sanitized metadata without raw query/payload/secret content.
- Optional payload retention is off by default, encrypted, permissioned, and cleaned on schedule.
- Rate/range/page/network limits and token rotation/revocation work as documented.

## Documentation

Update API Management and operational retention/incident-review documentation.

## Done Criteria

- Coordinator tokens cannot bypass workload or installation policy.
- Audit and optional payload retention are visibly separate.
- Migrations, scheduler cleanup, focused tests, and Dev smoke tests pass.
