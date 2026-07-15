# ADR: Data Exchange Platform Ownership

Status: Accepted
Date: 2026-07-03
Decision Makers: Svein / Codex

## Context

Nexum needs a broad import/export system that can serve Economy exports, future accounting provider
profiles, manual file exchange, schedules, API automation, n8n/Zapier workflows, and later richer
module data movement.

The risky design would be to let one feature such as Economy export build a temporary export path,
or to give users raw SQL access to the database. That would create security risk, duplicate future
integration work, and force later rewrites when Tripletex, PowerOffice, import, API, FTP/SFTP, and
schedules are added.

The architecture decision is about ownership:

- which module owns Data Exchange profiles and runs,
- which module owns external credentials,
- which modules own source/target business rules,
- how to prevent raw SQL and secret leakage.

## Decision

Create a dedicated `DataExchange` module.

`DataExchange` owns profiles, Data Builder configuration, import/export runs, generated files,
schedules, delivery attempts, audit events, retention, and Data Exchange API endpoints.

Domain modules own the data they expose:

- safe data source registration,
- allowed fields and relations,
- blocked/sensitive fields,
- filters,
- import targets,
- validation,
- persistence behavior,
- domain-specific tests and documentation.

`Integration` owns external credentials and connector configuration, including FTP/SFTP credentials,
webhook credentials, and future Tripletex/PowerOffice API credentials.

The v1 Data Builder is Livewire-based and does not allow raw SQL. All broad data access must flow
through registered sources and permission-aware metadata.

Security secrets and technical credentials are permanently blocked from Data Exchange exports,
including password hashes, remember tokens, two-factor secrets, API tokens, encrypted credentials,
private keys, and integration secrets.

## Rationale

This design keeps module ownership aligned with the existing Nexum architecture:

- Data Exchange can become a reusable platform instead of an Economy-only export feature.
- Economy/Orders export can be the first practical profile without creating throwaway code.
- Import writes remain safe because each module validates and persists its own data.
- Integration credentials stay in the existing integration/credential layer instead of being copied
  into export profiles.
- API scopes and documentation can follow the Domain API Foundation pattern.
- Future Tripletex/PowerOffice integrations can reuse Data Exchange profiles instead of replacing
  them.

Blocking raw SQL in v1 is intentional. Nexum needs a powerful Data Builder, but the product should
not expose arbitrary database access to normal administrators. Safe source metadata gives us
permissions, labels, relation control, field blocking, and future compatibility.

## Consequences

Positive consequences:

- One reusable import/export platform.
- Lower security risk than raw SQL.
- Clear module ownership.
- Reusable audit, file, schedule, and API runtime.
- Easier provider profiles for Tripletex and PowerOffice later.
- Better automation support for n8n, Zapier, API users, and manual file exchange.

Negative consequences:

- More upfront architecture work than a simple Economy CSV export.
- Each module must register safe data sources before it can expose rich export data.
- Import support requires module-specific import targets instead of direct table writes.
- Full v1 requires several feature slices before provider-specific accounting work should start.

## Alternatives Considered

### Economy-owned export first

Rejected. This would solve the immediate billing export need, but it would create duplicate logic
for profiles, files, schedules, delivery, API access, and future provider integrations.

### Report-owned export platform

Rejected. Report owns report discovery and future report builder behavior, but Data Exchange must
support imports, provider profiles, file delivery, API triggers, and module action buttons. Those
needs are broader than reporting.

### Integration-owned data exchange

Rejected. Integration should own external credentials and connector configuration, not business-data
mapping, module source metadata, import validation, generated files, or internal profile behavior.

### Raw SQL profile builder

Rejected for v1. It is powerful, but too risky for permissions, secrets, upgrades, field labels,
relations, tenancy/scope rules, and safe imports. A later advanced read-only SQL feature would need a
separate RFC and stricter guardrails.

## Follow-Up

- Implement the approved RFC in feature slices.
- Keep the first implementation slice focused on foundation and source registry contracts.
- Add Knowledge documentation as each user-facing surface appears.
- Add Integration API documentation when `data_exchange.*` abilities and endpoints are implemented.
- Revisit this ADR if a future approved RFC adds advanced read-only SQL or merges Data Exchange with
  Report Builder behavior.
