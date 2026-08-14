# RFC: Nexum Integration Hub And MCP Server

Status: Approved
Date: 2026-08-14
Owner: Svein / Codex

## Context

Nexum PSA is intended to become an operational hub for Trønder Data. Anything that a permitted
user can do visually in Nexum should, over time, also be available through an API and through an
MCP server. This includes actions that are represented in Nexum but are performed in connected
systems such as Plesk, WordPress, DNS, domain services, hosted websites, mail, monitoring, and
server operations.

The first target is internal Trønder Data operations. The architecture must nevertheless support a
future product offering where several Nexum installations or customers use the same integration
and agent concepts without granting one customer access to another customer's systems.

Nexum already has relevant foundations:

- domain APIs with explicit Sanctum abilities;
- an Integration module with API-key and AI-agent concepts;
- role-restricted active AI Agents and domain defaults;
- an API ability catalogue;
- a central AI/privacy data-egress direction;
- domain-owned actions and workflow guards, including Ticket workflow actions.

The MCP server must build on these foundations. It must not become a second authorization system,
a direct database client, or an unrestricted proxy to external providers.

## Goals

- Provide a stable MCP interface to Nexum capabilities.
- Treat API parity and MCP exposure as separate gates: every business action should have one
  authoritative programmatic contract, while MCP exposure additionally requires a complete safety
  and execution contract.
- Make Nexum the authoritative hub for identity, authorization, customer/site scope, integrations,
  agents, approvals, execution state, and audit.
- Ensure that an interactive AI session cannot do more than the authenticated Nexum user is allowed
  to do.
- Support internal Trønder Data integrations as well as customer/site-specific integrations.
- Support three execution modes: interactive technician, supervised Agent, and autonomous automation.
- Allow an Agent to orchestrate multiple permitted Nexum and external integration capabilities.
- Expose read-only status and inspection capabilities before introducing mutating capabilities.
- Require previews, policy evaluation, audit events, and post-action verification for mutations.
- Make capability and integration boundaries explicit enough to become a future customer product.
- Preserve domain ownership: Tickets own ticket meaning and workflow; Integration coordinates provider
  access and execution policy; external adapters own provider-specific communication.

## Non-Goals

- Do not implement an MCP server in this RFC.
- Do not add or change application routes, database tables, permissions, or provider credentials.
- Do not expose raw database queries or a generic arbitrary HTTP/SSH/Plesk command through MCP.
- Do not let MCP bypass existing Nexum authorization, workflow guards, privacy gates, or secret
  handling.
- Do not automate destructive production actions in the first slice.
- Do not decide the final commercial packaging, pricing, or customer-facing availability.
- Do not assume that every visual action already has a complete or safe API.
- Do not use browser automation as the default integration mechanism where a supported API exists.

## Current Behavior

Nexum API Management uses Sanctum personal access tokens with explicit abilities. The existing API
ability catalogue represents domain-level read and mutation abilities. The Integration module also
resolves active Agents according to the authenticated user's roles and optional domain defaults.

Existing AI/privacy design establishes that the effective policy is the most restrictive combination
of organization policy, provider/model policy, Agent/workload policy, token abilities, Work Context,
and authenticated-user authorization. The MCP path must use the same principle.

The current API and visual surface are not yet a proven one-to-one capability map. Some domain actions
are API-backed, some are UI-only or partially exposed, and external provider operations have separate
provider APIs and credentials. This RFC therefore begins with an inventory and gap analysis rather
than assuming parity.

## Proposed Change

### 1. Separate MCP service, official Nexum platform component

The MCP server will run as a separate, thin protocol service/process that calls Nexum's authenticated
API. It may eventually live in the same repository or deployment family, but it will not access
Nexum's database directly and will not own durable business or workflow state.

Nexum remains the policy and execution authority:

```text
MCP client
    |
    v
Nexum MCP service
    |
    v
Nexum API, policy, domain actions, and Execution engine
    |
    v
Integration adapters
    |
    v
Plesk, WordPress, DNS, domains, mail, monitoring, servers, ...
```

The MCP service translates model-facing requests into typed Nexum API operations. It does not invent
new permissions and does not turn provider-specific APIs into unrestricted generic tools. Durable
execution, approval, audit, retry, and verification state belongs to Nexum so an MCP connection or
service restart cannot become the source of truth for an operational action.

### 2. One authorization chain

Every MCP request must carry or resolve an execution identity. The effective authorization is the
intersection of:

1. authenticated Nexum user or approved automation identity;
2. Nexum role and existing record visibility;
3. API token abilities and token restrictions;
4. Agent/workload capabilities and allowed domains;
5. customer, site, integration, and organization scope;
6. central AI/privacy and data-egress policy;
7. action-specific workflow, approval, and risk policy.

No lower-level configuration can widen a higher-level restriction. An Agent, token, or MCP client
cannot elevate the underlying technician's Nexum permissions.

The production MCP endpoint will use Streamable HTTP over HTTPS and act as an OAuth protected
resource. Interactive access uses short-lived, audience-bound authorization with PKCE. Autonomous
work uses a separate workload identity. A token presented to the MCP service is never forwarded to
Nexum or an external provider. Nexum instead issues a separate, short-lived execution grant bound to
the actor/workload, organization, customer/site scope, capability, and correlation ID. Provider
credentials remain server-side in Nexum's protected integration layer.

### 3. Integration abstraction

External systems will be represented through explicit Nexum integrations and provider adapters. An
integration must have a declared owner and scope, such as:

- internal Trønder Data integration;
- organization-wide integration;
- customer-specific integration;
- site-specific integration.

Each adapter exposes named capabilities with typed input/output, a read or mutation classification,
required Nexum ability, scope requirements, risk level, reversibility, idempotency expectations,
credential reference, and verification method.

The initial provider inventory should include Plesk, WordPress, DNS/domain services, hosted websites,
mail, monitoring/RMM, and server operations. Plesk is selected as the first external read-only
adapter because it provides one useful slice across hosting accounts, sites, domains, SSL, and basic
website state. The inventory is a planning exercise; it does not imply that all providers are
implemented or currently safe to mutate.

### 4. Agents as controlled orchestration profiles

Nexum Agents remain first-class Nexum concepts. MCP may invoke an Agent, but an Agent must be bound to:

- an approved provider/model or local execution mode;
- allowed Nexum domains and external integrations;
- allowed tools/capabilities;
- customer/site scope;
- an execution mode;
- a risk and approval policy;
- input, output, timeout, rate, and quantity limits;
- audit and result-retention policy.

An Agent is an orchestration profile, not a permission bypass. It may call only capabilities already
allowed by the effective policy. Agent orchestration and durable workflow state remain in Nexum;
MCP is an invocation and interoperability surface, not the Agent runtime authority.

### 5. Execution modes

The platform will distinguish:

- **Interactive technician:** a user chats with AI. The system can ask for confirmation when policy
  requires it and executes under that user's effective authorization.
- **Supervised Agent:** an Agent can perform a workflow, but pauses at configured approval points or
  when the execution leaves its declared scope.
- **Autonomous automation:** a scheduled or event-driven execution runs without a live chat, but only
  under an explicit policy with bounded scope, action list, limits, expiry, and emergency disablement.

Confirmation is therefore risk- and policy-based, not a single global switch. Reading status is
normally low risk; DNS changes, publication, mail sending, credential changes, deletion, and
production infrastructure changes require stronger policy or explicit approval.

An interactive approval must be bound to an immutable proposed action containing target, scope,
normalized parameters, risk summary, expiry, and digest. Any material plan change invalidates the
approval. An AI model cannot approve its own action. Autonomous execution relies on a previously
approved, bounded policy rather than model-generated self-confirmation.

### 6. First capability slice

The first implementation slice, after this RFC is approved, should be read-only and cover:

- resolve the current Nexum identity and effective access summary;
- list permitted clients, sites, domains, and integrations;
- inspect integration connection and health status;
- inspect Plesk/site/WordPress status where a supported read API exists;
- return clear scope, authorization, freshness, and unavailable-provider information;
- record sanitized MCP access metadata without recording secrets or unnecessary customer content.

No production mutation is included in this first slice.

### 7. MCP primitives and execution lifecycle

The MCP surface will use:

- Resources for bounded, structured context and execution results;
- Tools for named read or mutation capabilities with strict input/output schemas;
- Prompts only as user-selected workflow templates, never as authorization;
- MCP Tasks, when negotiated with the client, as protocol projections of durable Nexum Executions.

The first version will not depend on server-initiated sampling. Model execution remains in the MCP
client or Nexum's explicitly configured Agent runtime.

Every mutation follows the lifecycle:

```text
resolve scope -> inspect -> plan -> authorize -> execute -> verify -> record
```

Each durable Execution records the actor/workload, capability version, scope, normalized parameters,
policy decision, approval reference, idempotency key, provider references, state, verification, and
sanitized errors. Long-running operations can be working, input-required, completed, failed, or
cancelled. Cancellation does not imply rollback after an external side effect has occurred.

### 8. Capability parity inventory

Before implementation, the project will maintain a capability matrix with at least these columns:

| Area | Visual operation | Domain action/API | MCP exposure | Provider | Side effect | Ability | Scope | Risk | Idempotency | Approval | Verification | Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|

The matrix is the source for deciding whether a capability is exposed through MCP. A visual button
without a complete API contract is a gap, not permission to call internal implementation details.
API availability does not automatically grant MCP exposure; the second gate requires explicit
scope, schema, side-effect class, risk, idempotency, approval, and verification contracts.

## Impact Analysis

### Affected modules

- Integration: provider adapters, Agent bindings, capability catalogues, execution policy, and audit.
- User Management/authorization: authenticated identity and role/permission evaluation.
- Each source domain: API contracts and domain-owned action/workflow enforcement.
- System/operations: background execution, health, rate limits, emergency disablement, and logs.
- Customer/site context: scope resolution and isolation.

### Permissions and security

The MCP service must use scoped credentials and explicit abilities. Broad full-access tokens are not
an acceptable default for Agents or MCP clients. Production access uses audience-bound, short-lived
OAuth credentials and separate execution grants; token passthrough is forbidden. Secrets remain in
the existing protected credential handling path and are never returned as MCP data.

MCP tool annotations such as read-only, destructive, idempotent, and open-world are compatibility
hints, not authorization controls. Nexum must independently enforce every decision server-side.

### External integrations

Provider adapters must prefer official APIs or supported connectors. A provider's UI is not treated as
the API contract. Where no supported API exists, the capability remains unavailable or is separately
approved as a controlled fallback with additional risk and verification requirements.

### Reliability

External operations may time out, partially succeed, or return ambiguous results. Each mutating
capability must define idempotency, retry, timeout, rollback/compensation, and post-action verification
before it is eligible for autonomous execution.

External systems cannot participate in one Nexum database transaction. Multi-step workflows require
checkpoints and explicit compensation where safe reversal exists. Durable Nexum Executions remain
authoritative even when the MCP client disconnects.

## Data And Migration Plan

No schema or migration is proposed by this RFC.

Before implementation, the design must decide whether existing Integration Agent/API models can be
extended or whether separate records are needed for provider integrations, capabilities, executions,
approvals, and audit events. Any schema proposal requires a follow-up ADR or Feature Slice.

Existing users, roles, API abilities, Agent bindings, and privacy settings must remain valid. New
MCP access must default to deny until an explicit capability and policy binding exists.

## Testing Plan

The implementation plan must include:

- authorization tests proving a technician cannot exceed ordinary Nexum access through MCP;
- customer/site isolation tests for internal and customer-specific integrations;
- Agent capability and domain-scope tests;
- read-only first-slice contract tests;
- OAuth audience, expiry, PKCE/delegation, token-passthrough rejection, and execution-grant tests;
- secret and sensitive-data redaction tests;
- denied-action reason and audit tests;
- timeout, provider-unavailable, stale-status, and partial-success tests;
- idempotency and post-action verification tests before any mutation is autonomous;
- manual verification against one safe non-production integration before wider rollout.

## Documentation Plan

- Keep this RFC as the architectural decision proposal.
- Add a capability matrix and API-gap inventory.
- Document the MCP authentication and execution-identity model.
- Document Agent execution modes and approval policies.
- Document each provider adapter's scope, credentials, supported operations, and verification.
- Add operational runbooks for denied actions, provider outage, credential rotation, and emergency
  disablement.
- Update Nexum API and Integration knowledge documentation when implementation begins.

## Resolved Design Decisions

1. Deploy the first MCP service privately alongside Nexum behind a controlled HTTPS endpoint. Public
   customer exposure is a later productization step.
2. Use Streamable HTTP for production and `stdio` only for local development.
3. Use OAuth-compatible, audience-bound interactive identity and separate workload identity. Keep
   current broad or long-lived API tokens out of the target MCP design.
4. Never pass the MCP access token downstream. Use a separate Nexum service identity plus a short-lived
   signed execution grant.
5. Store execution and approval records as protocol-neutral Integration/Execution concepts. MCP Task
   IDs project or reference these records; they do not own them.
6. Implement Plesk as the first external read-only adapter.
7. Reuse existing Agent role/domain/provider foundations, but add explicit external capability
   descriptors and bindings. Do not expose a generic unrestricted Agent tool.
8. Require explicit protocol/capability versioning and compatibility tests before supporting external
   MCP clients.

## Approval

Approved by Svein in conversation on 2026-08-14. The post-approval architecture refinement in this
revision was also explicitly authorized by Svein on 2026-08-14. Implementation remains gated by the
phases and follow-up design work described in this RFC.
