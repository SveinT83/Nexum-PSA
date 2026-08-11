# ADR: Central Privacy Gate For AI And Coordinator Data Egress

Status: Accepted
Date: 2026-07-14
Decision Makers: Svein / Codex

## Context

Nexum can configure several AI providers and agents, and its domain APIs enforce Sanctum abilities.
GitHub issue #178 adds a new concern: a scoped API can still disclose personal or client-confidential
data to a recipient whose purpose, processing location, agreements, and approved data level are not
known to Nexum.

Technician time and activity are also employee data. Stable technician IDs are pseudonymous rather
than anonymous, and operational coordination must not silently become employee ranking or secondary
performance monitoring.

The system needs one architecture decision for both direct Nexum AI use and coordinator API use.
If each domain or endpoint implements its own local privacy switches, equivalent data can receive
different protection depending on the access path.

## Decision

Integration will own a central data-egress policy gate for AI providers and coordinator workloads.
Domain modules continue to own records, queries, authorization, and API contracts. Before protected
data is projected into an AI context or coordinator response, the central gate intersects:

- the owning organization's policy,
- provider governance status,
- workload/agent purpose and maximum data profile,
- token abilities and limits,
- Work Context/Client rules, and
- the authenticated actor's existing authorization.

The most restrictive result wins. The installation policy is the maximum; provider/model and
agent/workload settings can only keep or reduce access. The defaults are AI disabled, external
processing disabled, privacy washing enabled when external processing is later allowed, and minimum
data disclosure.

The owning company may configure three processing modes:

- local-only processing,
- local privacy-gateway processing followed by an approved external model, or
- explicitly approved direct external processing with privacy washing disabled.

The privacy gateway combines structured minimization, deterministic filtering, optional local-model
rewriting such as Ollama, and post-validation. When enabled it fails closed and never falls back to
raw external delivery. Direct external processing remains a company choice, but cannot be activated
until the provider/model governance prerequisites are recorded and approved.

Organization choices are settings, but authentication, authorization, secret filtering, sanitized
access logging, server-side maximums, and expiry/inactive denials form a non-configurable security
floor. The first coordinator capability also excludes hidden employee monitoring, technician
ranking, productivity/disciplinary scoring, and automated employment decisions.

Current policy is installation-scoped because Nexum currently has one owning organization per
installation. A future shared-database multi-organization deployment must add explicit organization
foreign keys and isolation tests before it can reuse this design.

## Rationale

- One gate prevents API, background-job, AI-tool, and chat-context paths from drifting apart.
- Layered policy gives each company control without letting a token scope silently override a more
  restrictive provider or organization decision.
- Purpose limitation and a workforce-transparency gate protect technician activity from silent
  secondary use.
- Domain ownership remains intact; Integration decides whether/how data may leave, not what Ticket or
  Task data means.
- Default deny and minimum projection follow privacy-by-design/default principles.
- A provider-neutral design supports local and external models without making a technical hosting
  choice stand in for a legal or risk decision.
- A non-configurable floor prevents "settings-based" from becoming "security can be turned off."

## Consequences

Positive:

- Every company can set its own recipient, purpose, data, context, and retention policy.
- Companies can use a local model, a local privacy processor plus a stronger external model, or an
  approved direct external model without changing Nexum's architecture.
- New coordinator endpoints have one consistent enforcement and audit contract.
- Direct Nexum AI tools cannot bypass controls applied to the external API path.
- Expired or incomplete governance can fail closed.
- Source modules keep their existing architecture ownership.

Negative:

- Access decisions become more complex and require clear reason codes and Admin explanations.
- New relational policy, binding, and audit data requires migrations and retention operations.
- Existing broad API tokens need deliberate review and rotation rather than a silent migration.
- Company-entered governance facts can be inaccurate; Nexum can enforce recorded decisions but cannot
  certify their legal quality.
- Policy evaluation becomes a security-critical shared dependency and needs broad regression tests.

## Alternatives Considered

- **Require one local Ollama model for all installations.** Rejected because companies need provider
  choice, local hosting does not remove all privacy/security duties, and it hardcodes infrastructure
  into product policy.
- **Rely on Sanctum abilities alone.** Rejected because an ability does not describe recipient,
  purpose, processing region, agreement status, response profile, or Client scope.
- **Add independent privacy toggles to every endpoint.** Rejected because access paths would drift and
  could bypass each other.
- **Always require privacy washing for external AI.** Rejected because the owning company must be able
  to approve direct external processing after governance prerequisites are complete; the central gate
  and security floor still apply.
- **Trust a local LLM as the entire privacy filter.** Rejected because probabilistic rewriting alone
  cannot guarantee removal. The selected local model is optional inside a deterministic, validated,
  fail-closed pipeline.
- **Store only an Admin compliance checkbox.** Rejected because an attestation without technical
  enforcement does not minimize or prevent disclosure.
- **Put all settings in one `common_settings` JSON payload.** Rejected because provider/workload/token
  relationships, versioning, expiry, permissions, and audits require a relational model.
- **Block every external provider permanently.** Rejected because approved external processing may be
  a valid company decision and Nexum should enforce documented policy rather than make that decision
  for every operator.

## Follow-Up

- Implement the approved RFC `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md` through
  its Feature Slices.
- Implement the API-key least-privilege regression slice first.
- Define and migrate Integration-owned policy, governance, workload, binding, and audit records.
- Gate both direct AI tools/context and coordinator API responses.
- Add Knowledge, API, deployment, and operational documentation.
- Revisit this ADR before any shared-database multi-organization deployment.
