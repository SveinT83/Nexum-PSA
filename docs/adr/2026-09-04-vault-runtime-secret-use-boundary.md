# ADR 6: Runtime Secret Use Boundary Across Vault, Connection Broker, and Execution

Scope update (2026-10-01): the PSA-owned MCP purpose is superseded by
[Separate NexumMCP Ownership](2026-10-01-separate-nexummcp-ownership.md).
The remaining PSA credential/security contract and the historical decision below
are retained. PSA Secrets/Vault is not a future PSA MCP-server backend.

Status: Accepted
Date: 2026-08-30
Decision Makers: Svein Tore / Codex
Related RFCs: #279, #270, #272

## Context

Agents, Tools, Scripts, and Automations need to operate external systems using SSH credentials, API tokens, application passwords, and other secrets. Sending those values to a model, chat, queue payload, Tool definition, or approval display would make the AI platform a credential exfiltration channel.

At the same time, Automation must be able to perform approved unattended work without requiring a human click on every scheduled run.

## Decision

All machine use of Vault secrets follows a use-without-reveal boundary:

Vault authorizes the item and version. The Connection Broker resolves the approved Connection and target. The Execution runtime injects the secret only into the isolated provider/tool process at the final transport boundary. The Agent, model, planner, chat, approver, MCP client, Tool definition, Script definition, Automation graph, queue payload, and API caller receive no plaintext.

The request carries safe references only:

- actor or workload identity;
- Agent, Tool, Script, or Automation version;
- exact capability and risk class;
- Client, Site, Asset, and Connection scope;
- opaque Vault Item reference or approved selector;
- required item type and fields;
- target host/service and allowed operation;
- quantity, time, recipient, and command/action limits;
- approval or standing-authorization reference;
- plan and parameter digest;
- expiry, nonce, and correlation ID.

Nexum evaluates current user/workload access, item lifecycle, exact version, Connection health, target binding, contract/dependency state, risk policy, and approval immediately before secret resolution. Changed scope, plan, target, Connection, version, grant, policy, or incident state invalidates the authorization.

The Execution runtime obtains a short-lived single-purpose secret lease. A lease is audience-bound to one executor and Connection, cannot be exchanged for plaintext through API/MCP, cannot authorize another operation, and expires quickly. The secret is supplied through an in-memory or protected process channel, never command-line arguments, environment dumps, temporary scripts, general files, or serialized jobs.

Provider adapters and Tools declare which parameters are sensitive. Output passes deterministic redaction before persistence or model return. If a provider echoes a credential, token, private key, password, cookie, authorization header, or derived sensitive value, the value is removed and a security event is recorded. Unknown output is treated conservatively.

Interactive chat follows the Tool risk and approval policy. The approval UI shows target, effect, safe parameters, credential identity/purpose, and risk, but not the secret.

Automation can run without per-execution approval only under a standing authorization bound to an immutable Automation version and declared Vault/Connection scope, actions, targets, limits, review/expiry date, and verification. If the Tool policy requires approval for every Automation run, or the plan leaves the standing scope, Execution stops for human input. A chat approval and an Automation standing authorization are distinct policy objects.

Temporary runtime Scripts may be generated and executed only inside the approved Execution sandbox. They do not receive more secret capability than the parent execution, cannot persist secret output, and are destroyed after the run unless separately reviewed and promoted as a reusable Tool.

No general run_command, arbitrary HTTP request, database query, or unrestricted SSH capability may use Vault merely because a secret exists.

## Rationale

This preserves the operational usefulness of AI and Automation while keeping plaintext outside model-controlled and durable coordination surfaces. Exact, expiring grants and late binding prevent stale approvals, target substitution, and secret reuse.

## Consequences

Positive:

- Models and chat histories do not receive credentials.
- The same credential can serve supervised and autonomous work safely.
- Approval displays remain informative without secret disclosure.
- Revocation or policy change can block a queued execution before use.
- Audit identifies which credential version was used without logging its value.

Negative:

- Tools and adapters must support sensitive-parameter injection and redaction.
- Some legacy CLI programs may be unsuitable if they require secrets on command lines or emit them.
- Debugging is harder because secret-bearing raw payloads cannot be retained.
- Execution, Connection Broker, and Vault availability become linked at runtime.

## Alternatives Considered

- Give plaintext to the Agent and instruct it not to repeat it. Rejected because prompts are not a security boundary.
- Store secrets in Tool or Automation definitions. Rejected because reusable definitions are widely visible, exportable, and versioned.
- Put secrets in queue jobs or environment variables. Rejected because process listings, dumps, retries, failed-job stores, and logs can leak them.
- Require approval on every Automation run. Rejected because bounded, reviewed repetitive work must operate unattended.
- Let Automation bypass per-run Tool policy automatically. Rejected because some capabilities remain too risky for standing authorization.

## Follow-Up

- Align #270 and #272 contracts with this exact boundary.
- Define secret lease, executor, redaction, and sensitive-parameter interfaces.
- Audit every supported Tool/provider for command-line, environment, file, output, and error leakage.
- Test stale grants, target substitution, echoed secrets, queue inspection, cancellation, and revoked versions.
- Add operational alerts for suspected secret echo or lease misuse.
