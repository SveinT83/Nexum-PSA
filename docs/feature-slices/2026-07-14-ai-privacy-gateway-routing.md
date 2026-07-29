# Feature Slice: AI Privacy Gateway And Routing

Status: In Review
Date: 2026-07-14
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Codex

## Goal

Implement local-only, privacy-relay, and approved direct-external model routing with deterministic,
optional local-model, and fail-closed privacy controls.

## User-Visible Behavior

An admin can choose local processing, local privacy washing before a stronger external model, or
approved direct external processing. Privacy washing can be enabled or disabled within the effective
policy. A safe test surface explains what fields would be removed without silently sending production
data.

## Scope

- Filter disallowed structured fields before serialization.
- Deterministically remove credentials, secrets, configured identifiers, and blocking patterns.
- Support an optional local Ollama/OpenAI-compatible redaction/rewrite model.
- Post-validate the payload before any external request.
- Fail closed when a configured gateway stage fails; never send original data as fallback.
- Allow only explicitly configured fallback providers/models with equal or stricter policy.
- Add workload-scoped local pseudonyms with configured rotation/correlation windows.
- Route existing direct AI context/tool calls through the central evaluator and gateway.

## Out Of Scope

- Claiming that washed output is legally anonymous.
- Coordinator worklog endpoints.
- Attachment content processing.

## Data Touched

Integration routing/gateway configuration, optional local pseudonym mapping/rotation state, and
existing AI provider/agent execution paths. Raw secrets are never written to gateway traces.

## Permissions

Gateway settings use the approved policy/governance Admin permissions. Test/preview access is
separately guarded and cannot exceed the viewer's normal record access.

## Tests

- Structured minimization and deterministic secret/PII pattern removal.
- Optional local-model rewrite and post-validation.
- Explicit privacy-wash bypass only when policy/governance allows direct external processing.
- Local/gateway/external failure cannot leak raw input or choose a permissive fallback.
- Direct AI tools and equivalent coordinator policy decisions remain consistent.

## Documentation

Document processing modes, limitations of AI redaction, fail-closed behavior, and safe testing.

## Done Criteria

- Every supported AI egress path passes through the central policy decision.
- Gateway-enabled external requests cannot occur without successful validation.
- Focused Integration/AI tests and controlled Dev provider smoke tests pass.
