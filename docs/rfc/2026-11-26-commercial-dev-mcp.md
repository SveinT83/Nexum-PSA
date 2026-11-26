# Commercial catalogue connection on Dev

Status: Approved for Dev by Svein Tore, 26 November 2026. GitHub issue #412.
Owner: Commercial / Nexum MCP. Human acceptance: HR-2026-11-26-COMMERCIAL-MCP.

Provide a separate, small connection for operators who need to find or create a recurring cost and a versioned term and link them to a service. Expose catalogue context, list, detail and creation and a narrow service link update. The Dev upstream accepts an employee's personal Nexum Dev token. Flow through the existing Commercial API guard, field validation, audit, idempotency and revision handling. Discover only operations available to the current actor and token. A separate LiteLLM registration must have its own personal Dev credential binding. A production-bound identity, a shared API token and a broad toolset grant are unsuitable for this workflow.

Reuse the installed Laravel MCP SDK on the development environment. Register the transport only in local/testing deployments with the SDK available. Keep all mutations in the existing named API routes. Test scoped discovery, denial, creation and read-back, keyed retries, stale revisions, catalogue limits and portal-free staff-only access. No migration, new employee permission or production API change. Employee gateway discovery and a real personal login are separate acceptance steps.

Dev verification on 26 November covered 65 Commercial tests (828 assertions). The Python MCP client negotiated protocol 2025-06-18, discovered 12 authorized tools, created an isolated test term and read its version back. A read-only token saw nine tools. A user without catalogue rights saw none. The direct office endpoint requested a personal bearer token and the shared gateway received HTTP 403 from the Dev ingress. Employee discovery in ChatGPT awaits a permitted connection and personal authentication.

## Production authorization, 26 November 2026

Svein Tore accepted the Dev delivery and requested promotion to Main and production. Use the same native permissions, token scopes and actor policies in production. The shared gateway to Dev remains office-restricted. Employee ChatGPT discovery and account-specific acceptance require separate read-back.

Promote the existing locked Laravel MCP v0.3.4 package to a production dependency. The endpoint uses the installation's own personal tokens. Production does not need access to Dev or a shared employee credential.
