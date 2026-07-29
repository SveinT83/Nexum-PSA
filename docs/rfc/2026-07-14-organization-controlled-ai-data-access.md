# RFC: Organization-Controlled AI Data Access And Coordinator API Privacy

Status: Approved
Date: 2026-07-14
Owner: Codex

## Context

GitHub issue #178 proposes read-only coordinator APIs for technician time usage, recent work, and
stale Ticket/Task activity. The issue comment correctly identifies that these endpoints may expose
personal data and client-confidential MSP data to a coordinator or AI provider outside Nexum.

Nexum already lets an administrator configure AI providers, agents, data sources, tools, API scopes,
and retention. It does not yet have one enforceable policy that answers whether a specific workload
may disclose a specific class of data to a specific recipient for a documented purpose.

The proposed questions also create a workforce-privacy concern, not only a client-confidentiality
concern. Technician identifiers, time entries, inactivity, and "last touched" history can be used to
monitor employees. Replacing a technician name with a stable ID pseudonymizes the data; it does not
make employee-level activity anonymous.

Nexum currently models one owning organization per installation. Therefore, "organization-controlled"
means that every company operating its own Nexum installation controls its own policy. If Nexum later
supports several owning organizations in one database, these records must be organization-scoped
before that deployment model is allowed; application-global policy rows would not be sufficient.

This is Level 3 work because it changes API behavior, authorization, AI integrations, data disclosure,
audit data, and Admin settings across Integration, Report, Ticket, and Task.

## Goals

- Let each company decide whether coordinator and AI data access is enabled.
- Apply privacy-friendly defaults: disabled until configured, minimum data, minimum recipients, and
  minimum retention.
- Make disclosure policy specific to the provider, workload/agent, token, purpose, data class, and
  Work Context instead of relying on one global on/off switch.
- Keep local/self-hosted and external providers available as company choices without treating either
  choice as automatically compliant.
- Require documented provider/governance readiness before identified or free-text data can be sent to
  an external processor.
- Enforce progressive response profiles and token abilities for coordinator APIs.
- Record sanitized access metadata for accountability and incident investigation.
- Keep domain data ownership in the source modules while enforcing one consistent disclosure gate.
- Ensure the same policy protects direct Nexum AI tools and coordinator API access; neither path may
  bypass the other.
- Let the owning company turn AI, external processing, and privacy washing on or off through settings,
  globally and within the limits of provider/model and agent/workload policies.
- Support local-only processing, privacy-gateway relay to a stronger external model, and explicitly
  approved direct external processing.
- Limit the first workload to service coordination and capacity/follow-up support, with transparent
  employee-level access and no hidden productivity scoring.
- Correct existing API-key defaults so selecting no abilities never grants all abilities.

## Non-Goals

- Do not certify that an organization, provider, model, or processing activity is GDPR compliant.
- Do not replace legal advice, a data processing agreement, a transfer assessment, a record of
  processing activities, or a DPIA where one is required.
- Do not force all Nexum installations to use one LLM provider or a local Ollama model.
- Do not send, store, or expose credentials, authentication secrets, private keys, or raw integration
  secrets through coordinator endpoints under any policy profile.
- Do not add coordinator write actions in this RFC.
- Do not rank technicians, calculate productivity/disciplinary scores, or make automated employment
  decisions. Any later workforce-analytics proposal requires its own RFC and legal/privacy review.
- Do not expose ticket messages, task descriptions, internal notes, invoice text, or attachment bodies
  in the first coordinator API slice.
- Do not introduce shared-database multi-tenancy through this RFC.
- Do not claim that an API access log alone satisfies GDPR accountability obligations.

## Current Behavior

- Integration owns AI provider, agent, data-source, tool, API-scope, role, default-domain, and chat
  retention settings.
- API Management creates Sanctum tokens and displays their abilities.
- Domain API routes enforce explicit Sanctum abilities.
- `ApiAbilityCatalog::normalize()` currently converts an empty ability selection into every catalogued
  ability, and the API-key form checks every ability by default. This conflicts with least privilege.
- AI-agent read-only scope normalization currently relies on the ability name ending in `.read`.
  Progressive read abilities such as `worklog.read-identified` would therefore be misclassified unless
  the catalog gains explicit read/write metadata.
- AI provider records do not capture processing locations, recipient role, DPA status, subprocessor
  review, transfer basis, retention/training declarations, review owner, or review expiry.
- Agent data-source and tool selections control capability, but do not constitute a data-disclosure
  approval.
- There is no common middleware/policy service that evaluates provider governance, workload purpose,
  response profile, Work Context, and token ability together.
- There is no coordinator-specific control that separates legitimate operational follow-up from
  employee ranking or secondary performance-monitoring purposes.
- Personal access tokens record `last_used_at`, but Nexum has no sanitized audit ledger for API reads.
- Ticket and Task own separate time-entry records. There is no coordinator-safe unified worklog API.

## Proposed Change

### 1. Policy Model

Integration will own a central data-egress policy service and Admin settings. Every coordinator API
response and every direct AI context/tool call that can disclose Nexum data must obtain a decision
from this service.

The effective policy is the most restrictive combination of:

1. Organization/installation defaults.
2. Provider and model policy/governance status.
3. Workload or AI-agent policy.
4. API token abilities and token-specific limits.
5. Work Context and optional Client allow/deny rules selected by the owning company.
6. The authenticated user's existing authorization and record visibility.

A permissive lower layer can never widen a restriction from a higher layer. A Sanctum ability means
"may request"; it does not by itself mean "may disclose".

The installation policy is a maximum policy. Provider/model and agent/workload settings may be
equally restrictive or more restrictive, but can never widen the installation maximum. Request-time
policy may narrow the result again. Client records do not own independent policies; the company that
owns the Nexum installation controls which Client contexts may be processed.

### 2. Organization Settings

The owning company can configure:

- AI enabled: default `false`.
- External AI processing enabled: default `false`.
- Privacy gateway enabled for external processing: default `true`.
- Direct external processing without privacy washing enabled: default `false`.
- Allowed processing modes: local only, privacy-gateway relay, direct external, or an explicit subset.
- Maximum data profile: `aggregate`, `pseudonymized`, `identified_business`, `full_context`, or a
  custom allowlist; default `aggregate`.
- Work Context scope: internal only, selected Clients, all Clients except exclusions, or all permitted
  contexts; default internal only until explicitly changed.
- Allowed data classes for summaries, identifiers, business labels, and free text.
- Maximum query date range, page size, result count, and requests per minute.
- Access-audit retention, defaulting to a documented finite period.
- Whether denied requests are retained in the security ledger.
- Optional local prompt/response retention for troubleshooting: default `off`, separately permissioned,
  encrypted, and subject to a short configured retention. Mandatory audit remains metadata-only.
- A review/expiry date after which external disclosure is automatically blocked until renewed.
- Whether employee-level identification is allowed, the documented coordination purpose, staff
  information/consultation reference, review owner, and review date.

The settings UI must show the effective policy and explain why a requested capability is blocked.
It must not expose a toggle unless the underlying enforcement exists and is tested.

### 3. Processing Modes And Privacy Gateway

Each provider/model and agent/workload selects one of the processing modes allowed by the installation
maximum:

- **Local only:** the selected local provider/model receives the allowed data and nothing is relayed
  externally. Privacy washing may be enabled or disabled by company setting.
- **Privacy-gateway relay:** structured minimization and filtering run locally, an optional local
  Ollama/OpenAI-compatible model can rewrite free text, and a post-processing validator checks the
  result before an approved external model receives it.
- **Direct external:** the allowed data profile is sent to an approved external provider/model without
  privacy rewriting. The company may enable this only after the governance prerequisites are complete
  and an Admin explicitly confirms the risk.

The privacy gateway is a pipeline, not a promise that one local LLM has anonymized the input:

1. Remove fields not allowed by the effective data profile before serialization.
2. Apply deterministic filtering for credentials, secrets, personal identifiers, and configured
   client-confidential patterns.
3. Optionally use a configured local model, such as Ollama, to redact or rewrite allowed free text.
4. Validate the resulting payload against the effective policy and blocking patterns.
5. Send only when validation passes.

When privacy washing is enabled, a gateway/model/validation failure fails closed. Nexum must never
fall back to sending the original data. Provider fallback is allowed only when explicitly configured
and the fallback has an equal or stricter effective policy.

Pseudonyms should be workload-scoped and generated locally so an external provider does not receive
raw primary keys. Rotation and correlation windows are settings with privacy-friendly defaults.

### 4. Provider Governance Settings

Each AI or coordinator provider/recipient profile can record:

- Legal entity and recipient role.
- Processing and support-access regions.
- DPA status, reference, approval date, owner, and expiry/review date.
- Subprocessor review status and reference.
- International-transfer mechanism and assessment reference where relevant.
- Provider declarations for input retention, model training, and deletion.
- DPIA decision: not assessed, not required with documented rationale, in progress, approved, rejected,
  or expired.
- Record-of-processing reference and documented purpose.
- Approved maximum response profile.

These are governance records and enforcement inputs, not a Nexum-generated compliance certificate.
Every external provider/model requires an approved governance profile. External personal or
pseudonymous data is blocked without the required DPA status. Direct external identified/free-text
access is blocked while purpose, DPA, region, subprocessor/transfer review, DPIA decision, or approval
is incomplete, rejected, or expired. A local URL is not automatically trusted; it still needs an
active security configuration, but external-transfer fields can be not applicable.

### 5. Workload, Agent, Model, And Token Settings

Every coordinator workload or AI agent must be bound to:

- One approved provider/recipient profile.
- One explicit model, or an approved provider default model.
- One allowed processing mode and privacy-gateway configuration.
- A documented purpose.
- Allowed data sources and tools.
- Allowed response profile and data classes.
- Allowed Work Contexts and Client rules.
- Read-only API abilities.
- Token expiry, rate limit, maximum query window, and optional network restrictions.

API keys used by coordinators must be associated with a workload profile. General full-access tokens
cannot be used as coordinator tokens.

### 6. Data Profiles And Advanced Field Controls

The Admin UI provides understandable presets plus an advanced custom allowlist:

- **Aggregate:** counts, totals, ranges, and operational states without stable person/Client
  identifiers where the question can be answered without them.
- **Pseudonymized:** workload-scoped aliases, timestamps, durations, categories, and state, without
  names, titles, contact details, or free text.
- **Identified business context:** technician and Client names, record keys, and business labels such
  as Ticket/Task titles when explicitly allowed.
- **Full context:** explicitly selected descriptions, messages, notes, and other free text. Attachments
  require separate later approval and are not enabled merely by choosing this profile.
- **Custom:** per-data-class and per-field allowlist that cannot exceed the installation maximum.

Presets initialize a clear policy; advanced controls show the resulting effective classification.
Secrets, passwords, tokens, private keys, authentication headers, and raw integration credentials are
never selectable. Titles and free text are treated as potentially identifying and client-confidential.

### 7. Progressive Coordinator API

The first implementation remains read-only:

- `GET /api/v1/worklog/technicians`
- `GET /api/v1/worklog/time-entries`
- `GET /api/v1/tickets/stale`
- `GET /api/v1/tasks/stale`

Recommended abilities:

- `worklog.read` for aggregate/pseudonymized technician summaries.
- `time-entries.read` for pseudonymized entry rows.
- `worklog.read-identified` for names and business labels when policy allows them.

Raw message bodies, internal notes, invoice text, task descriptions, and attachment contents remain
outside the first API slice. Identified and full-context profiles are introduced only by a later
Feature Slice with separate abilities, explicit organization settings, and verified governance gates.

The default projection uses opaque/stable IDs where a row-level identifier is necessary and omits
technician names, client names, Ticket/Task titles, descriptions, messages, notes, emails, phone
numbers, and billing text. Aggregate responses should avoid stable identifiers when counts and totals
are sufficient for the question. Employee-level aggregates and stable technician IDs are still
treated as personal data and remain purpose-limited.

Report owns the cross-domain worklog read model and delegates source-specific queries to Ticket and
Task. Ticket owns stale Ticket behavior; Task owns stale Task behavior. Integration owns disclosure
policy, API-key/workload binding, provider governance, and the access ledger.

### 8. Non-Configurable Security Floor

Company choice is broad, but the following controls cannot be disabled:

- Authentication, authorization, record visibility, and explicit token-ability checks.
- Secret/credential filtering.
- Sanitized access/security audit metadata for coordinator reads.
- Server-side maximums for rate, page size, result count, and query range.
- Encryption for stored credentials and sensitive governance references.
- Denial when the selected provider, workload, or governance approval is inactive or expired.
- No silent fallback from a blocked provider/profile to a more permissive provider/profile.
- No raw-data fallback when the configured privacy gateway is unavailable or validation fails.
- No use of settings to grant data the authenticated actor could not otherwise access.
- No hidden employee monitoring, technician ranking, productivity/disciplinary scoring, or automated
  employment decisions through these coordinator endpoints.

### 9. Audit And Transparency

Coordinator requests will record only the metadata needed for accountability and investigation:

- Request/correlation ID, time, token/workload/provider identifiers, actor when available.
- Route, response profile, policy decision, rule/reason code, status, row count, and duration.
- Sanitized filter categories and a one-way fingerprint when correlation is needed.

Raw prompts, response bodies, message text, credentials, authorization headers, and unrestricted
query strings are not copied into the mandatory access ledger. Separately configured local payload
retention may store approved prompt/response content for troubleshooting, but is off by default,
encrypted, access-restricted, visibly disclosed, and automatically deleted. Credentials and secrets
are never retained. The mandatory ledger has a company-configurable retention period within a safe
system maximum and is visible only to an explicit Admin permission.

This ledger supports accountability and security; the product must not describe a per-read log as a
specific standalone GDPR requirement.

### 10. Safe API-Key Defaults

API Management changes to least privilege:

- Ability checkboxes are unchecked by default.
- An empty ability selection fails validation instead of granting every ability.
- Full access remains an explicit, separately confirmed administrative choice for non-coordinator
  integrations.
- Coordinator workload tokens cannot select full access or write abilities.
- Ability catalog entries declare read/write access mode explicitly; authorization code must not infer
  access mode from a scope-name suffix.
- Existing tokens are not silently rewritten. Admin receives a review list for broad/full-access
  tokens and can rotate or revoke them deliberately.

### 11. Feature Slice Order

1. API-key least-privilege regression fix and broad-token review UI.
2. Integration-owned installation policy schema, evaluator, permissions, and maximum-policy settings.
3. Provider/model governance and agent/workload policy settings.
4. Local/privacy-relay/direct-external routing with deterministic filtering, optional local Ollama,
   post-validation, preview/test tooling, and fail-closed behavior.
5. Coordinator workload/token binding plus sanitized access ledger and retention cleanup.
6. Privacy-minimized Report worklog and Ticket/Task stale-activity APIs.
7. Progressive identified/full-context response profiles after governance-path verification.

No later slice may start if verification failures from an earlier security slice remain unresolved.

## Impact Analysis

- **Integration:** owns organization disclosure settings, provider governance, workload profiles,
  token binding, the policy evaluator, audit ledger, cleanup, and Admin UI.
- **Report:** owns the cross-domain worklog read model and summary endpoints.
- **Ticket:** provides Ticket time/stale query services and owns the stale Ticket endpoint contract.
- **Task:** provides Task time/stale query services and owns the stale Task endpoint contract.
- **WorkContext/Clients:** supplies internal/client scope and optional Client restrictions without
  transferring ownership of Client data to Integration.
- **User Management/permissions:** new explicit permissions for governance settings and access-log
  review; existing record authorization remains mandatory.
- **API:** new read-only abilities, middleware/policy evaluation, response projections, rate limits,
  and audit events.
- **AI:** existing agent data sources/tools become capability inputs underneath the disclosure policy.
- **AI provider/model routing:** adds explicit local, privacy-relay, and direct-external modes without
  hardcoding Ollama or one external provider.
- **Queue/scheduler:** finite-retention cleanup for access logs and governance-expiry checks/alerts.
- **UI:** Bootstrap Admin settings under Integration, effective-policy explanations, explicit risk
  confirmations, and no preselected broad access.
- **Operations:** deployment requires migrations, permission seeding, cache clear, scheduler/queue
  verification, token review, and HTTP smoke tests on Dev.
- **Risk:** policy complexity can create false confidence. UI and docs must distinguish technical
  enforcement, organization attestations, and legal determinations.

## Data And Migration Plan

Use dedicated Integration-owned tables rather than a single `common_settings` JSON row because the
policy is relational, recipient-specific, versioned, security-sensitive, and auditable.

Proposed records:

- Organization/installation data-access policy and revision metadata.
- Provider governance profiles linked to existing AI providers where relevant.
- Provider/model policy records, including model-specific maximums and processing mode.
- Workload profiles linked to providers/agents.
- API-token-to-workload bindings and token-specific limits.
- Work Context/Client policy rules.
- Sanitized API access events.

Exact table names, indexes, foreign keys, retention fields, and whether policy revisions use snapshot
rows must be confirmed during the first approved implementation slice after inspecting the Dev
database state.

Migration defaults:

- Coordinator/AI egress policy disabled.
- Existing AI providers and agents remain stored but do not gain coordinator data access.
- Existing tokens retain abilities for compatibility but are flagged for review when broad or full
  access is detected.
- No existing token is automatically bound to a coordinator workload.
- No background migration sends data or calls a provider.

Rollback disables coordinator policy first, revokes new workload tokens, stops cleanup jobs, and
then removes new tables only if their audit retention/export obligations have been reviewed.

## Testing Plan

- Feature tests that coordinator access is denied by default even with a matching token ability.
- Tests that the installation maximum cannot be widened by provider/model, agent/workload, token, or
  request settings.
- Feature tests for the intersection of organization, provider, workload, token, context, and user
  authorization rules.
- Regression test that no selected API abilities never becomes all abilities.
- Tests that coordinator tokens cannot receive full access or write abilities.
- Tests that progressive read abilities remain available to read-only agents while catalogued write
  abilities remain blocked.
- Contract tests for aggregate, pseudonymized, and identified projections.
- Negative assertions that names, titles, descriptions, messages, notes, invoice text, contact data,
  attachment content, credentials, and secrets are absent from minimal responses and logs.
- Tests for inactive/expired governance records, provider changes, token rotation, Client rules,
  policy expiry, rate limits, date-range caps, pagination caps, and denial reason codes.
- Tests that technician identification is blocked until the workforce-purpose/transparency gate is
  complete and that the API exposes no ranking or productivity score fields.
- Tests that direct AI tools and coordinator API routes make the same policy decision for equivalent
  data.
- Pipeline tests for structured minimization, deterministic filtering, optional local-model rewriting,
  post-validation, privacy-wash bypass when explicitly approved, and fail-closed behavior.
- Tests that neither local-model failure nor external-provider failure can send raw data or choose a
  more permissive fallback.
- Audit tests for allowed and denied requests, sanitized filters, retention cleanup, and restricted
  Admin visibility.
- Ticket, Task, Report, Integration, permission, and API regression suites.
- Dev-server migrations, focused Laravel tests, queue/scheduler checks, and authenticated HTTP smoke
  tests before any push to `Dev`.

## Documentation Plan

- Integration Knowledge article for organization data-access policy and provider governance.
- API Management docs for least-privilege keys, coordinator workloads, abilities, rotation, and
  review of existing broad tokens.
- Report/Ticket/Task API contracts with exact response profiles and omitted data classes.
- Admin guide that separates technical controls from legal/accountability decisions.
- Deployment/runbook notes for migrations, permissions, scheduler cleanup, token review, and rollback.
- `docs/TODO.md`, RFC index, API/OpenAPI documentation, and BookStack-sync sources.
- Link official guidance used for the design:
  - [Datatilsynet: privacy by design/default and DPIA](https://www.datatilsynet.no/regelverk-og-verktoy/rapporter-og-utredninger/kunstig-intelligens/vurder-personvernkonsekvensene---og-bygg-personvern-inn-i-losningene/)
  - [Datatilsynet: data processing agreements](https://www.datatilsynet.no/rettigheter-og-plikter/virksomhetenes-plikter/hvordan-lage-en-databehandleravtale/)
  - [Datatilsynet: records of processing activities](https://www.datatilsynet.no/rettigheter-og-plikter/virksomhetenes-plikter/protokoll-over-behandlingsaktiviteter/)
  - [Datatilsynet: transfers outside the EEA](https://www.datatilsynet.no/rettigheter-og-plikter/virksomhetenes-plikter/overforing-av-personopplysninger-ut-av-eos/)
  - [Datatilsynet: monitoring and control in working life](https://www.datatilsynet.no/regelverk-og-verktoy/rapporter-og-utredninger/sjefen-ser-deg-overvaking-og-kontroll-av-arbeidstakeres-digitale-aktiviteter/hvilke-regler-gjelder/)
  - [EDPB: opinion on personal data and AI models](https://www.edpb.europa.eu/news/edpb-opinion-on-ai-models-gdpr-principles-support-responsible-ai_en)

## Open Questions

None blocking. Exact schema names and UI wording may be refined inside the approved Feature Slices
without weakening the installation maximum, governance prerequisites, or non-configurable security
floor.

## Approval

Approved by Svein in conversation on 2026-07-14. The approved direction is installation-owned settings,
a global maximum policy with stricter provider/model and agent/workload overrides, selectable local,
privacy-relay, and direct-external processing, optional privacy washing, mandatory governance records
before direct external processing, privacy-first defaults, and a non-configurable security floor.
