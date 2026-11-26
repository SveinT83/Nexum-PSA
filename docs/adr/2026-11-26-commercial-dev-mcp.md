# Scoped Commercial catalogue on Nexum Dev

Accepted for Dev on 26 November 2026. Related: [RFC](../rfc/2026-11-26-commercial-dev-mcp.md), issue #412.

The Commercial module owns a dedicated Streamable HTTP MCP resource at /mcp/commercial. On Dev the existing Laravel MCP development dependency handles protocol negotiation. The approved operation list uses twelve route names. Their input schemas come from the domain API contract. Personal Dev bearer tokens, current native permissions and Sanctum scopes determine which tools a caller sees and can invoke.

Tool execution delegates to the existing named REST route and its middleware. It retains transaction, replay key, revision and response handling. The service link operation accepts costs and terms only. The adapter makes no external HTTP requests, stores no personal token and adds no user permissions. Existing staff API grants and production gateway registrations retain their configuration.

The Dev web host accepts direct office clients. A request from the shared gateway container was rejected with HTTP 403 by the Dev ingress. Employee ChatGPT use requires a separately approved network path and Dev-specific personal credential binding in the gateway. No production activation or schema migration is part of this decision.

## Production authorization, 26 November 2026

Svein Tore accepted the Dev delivery and requested promotion to Main and production. Use the same native permissions, token scopes and actor policies in production. The shared gateway to Dev remains office-restricted. Employee ChatGPT discovery and account-specific acceptance require separate read-back.

Promote the existing locked Laravel MCP v0.3.4 package to a production dependency. The endpoint uses the installation's own personal tokens. Production does not need access to Dev or a shared employee credential.
