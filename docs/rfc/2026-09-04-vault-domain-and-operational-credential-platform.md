# RFC: Nexum Vault Domain and Operational Credential Platform

## Product boundary update (2026-10-01)

Svein removed the PSA-owned MCP server from the product direction. The separate
NexumMCP owns MCP; PSA retains its domain APIs. Secrets/Vault is not being built
as a backend for a future PSA MCP server. Technician/customer credential management
and actual PSA integration needs remain independently in scope. MCP-only work is
not a Vault completion prerequisite. Older MCP wording below is historical where
it conflicts with [the accepted boundary decision](../adr/2026-10-01-separate-nexummcp-ownership.md).

Status: Approved
Date: 2026-08-30
Owner: Svein Tore / Nexum PSA
Related: #279, #260, #270, #272

## Approved clarification: privileged invitations (2026-09-18)

Svein approved separating invitation acceptance from privileged activation ("Gjør som du foreslår").
When Vault authority enforcement is active, an invited Admin/Superuser may set the password and
consume the invitation once, but remains pending and is not logged in. An existing administrator
must complete activation through the normal current-plan, step-up, approval/sole-admin and audit
contract. Ordinary invitations keep their existing acceptance behavior. Existing roles are not
silently removed and an invitation token is not authority approval. See
[the accepted clarification](../adr/2026-09-18-vault-privileged-invitation-activation.md).
This approval changes the access workflow only; it does not authorize runtime activation or migration.

## Context

Nexum currently stores credentials in several domain- and provider-specific paths. AI providers keep encrypted values in a JSON secrets field. Email has a stronger Integration-owned credential-version lifecycle with staged, verified, active, retired, revoked, and destroyed states. Other integrations and modules use their own settings, encrypted fields, tokens, or provider-specific handling.

Those paths solve individual needs, but they do not form one complete credential platform. They duplicate ownership, permissions, rotation, audit, recovery, customer access, relationship mapping, and runtime resolution. They also prevent Nexum from becoming the operational replacement for Passportal.

Discussion #279 establishes a new product direction: Vault is a standalone Nexum domain and the authority for secret material. Integration continues to own Connectors, Connections, endpoints, provider adapters, health, and the Connection Broker. Client, Site, Asset, WordPress, Service, Email, and other domains retain ownership of their business records. AI, Tools, Scripts, and Automation may request bounded secret use but do not own or receive plaintext secrets.

This is Level 3 work. It introduces a new domain, security-critical data, cryptographic key management, permissions, customer-portal access, cross-domain relationships, migrations, queues, external-vault synchronization, API/runtime contracts, and incident-recovery requirements.

This RFC defines the complete target Vault product. Implementation may be delivered through controlled Feature Slices, but the Vault is not considered finished merely when AI or Connections can consume secrets. Completion requires the full internal workflow, customer portal, lifecycle, migration, recovery, external adapter, operational monitoring, documentation, and human/security review defined here.

## Goals

- Create one singular Vault domain that is authoritative for every Nexum-managed secret.
- Replace the operational need for Passportal with a complete Nexum-native credential workspace.
- Keep Vault fully usable without Vaultwarden, Bitwarden, or another external password manager.
- Relate credentials directly to Clients, Sites, Assets, WordPress installations, services, domains, network devices, software, contracts, and Connections.
- Support internal technicians, machine/runtime use, and explicitly authorized customer-portal access.
- Treat all Vault Items as organization-controlled business data, even when access is limited to one technician.
- Prevent deactivated technicians from orphaning credentials or operational workflows.
- Support immutable secret versions, rotation, verification, revocation, recovery, archival, and cryptographic destruction.
- Provide strong authentication, step-up authorization, independent approval where required, and a safe sole-administrator path.
- Use envelope encryption and replaceable key providers with explicit key lifecycle, backup, recovery, and compromise procedures.
- Ensure plaintext secrets never enter model prompts, Memory, chat history, queues, logs, notifications, analytics, URLs, search indexes, ordinary audit payloads, or reusable Tool/Automation definitions.
- Let Connections and the shared execution runtime use an exact Vault Item/version without exposing it to the Agent, Tool plan, approver, queue, or caller.
- Provide safe import, encrypted export, provider-by-provider migration, and conflict-aware synchronization.
- Deliver a Vaultwarden adapter for existing browser, desktop, and mobile client workflows while keeping Nexum authoritative.
- Provide complete operational monitoring, review dates, security alerts, audit, backup validation, and disaster-recovery testing.
- Preserve domain ownership and avoid creating a second Integration, execution, approval, or customer-data model.

## Non-Goals

- Building a native Nexum browser extension in this RFC. Vaultwarden/Bitwarden clients provide the first browser/mobile bridge.
- General privileged-session recording, keystroke recording, or a full PAM checkout product.
- Anonymous public secret links.
- Allowing customers to administer internal technician, service-identity, or Connection secrets.
- Giving AI models, Skills, Tools, Scripts, Automations, MCP clients, or ordinary API consumers plaintext secrets.
- Replacing a KMS or HSM product. Vault integrates with replaceable key providers and enforces the application boundary.
- Supporting every external password manager in the first completed release. Vaultwarden is required; later adapters reuse the same contract.
- Automatic provider-specific credential rotation for every external system. Vault owns lifecycle and scheduling; each automated rotation still needs a supported Connector/Tool and a separate safe contract.
- Moving Client, Asset, Service, Integration, Email, or other domain business logic into Vault.
- Implementing the code through this RFC comment. Implementation begins only after approval, required ADRs, threat modeling, and Feature Slice approval.

## Current Behavior

Authoritative Dev is on the Dev branch. It is a shared working copy with verified concurrent in-progress changes; implementation must preserve unrelated work.

Current secret handling is fragmented:

- AI providers store provider-specific encrypted values in ai_providers.secrets and decrypt them through the Integration model.
- Email account credentials are account-owned. The retired Integration provider credential lifecycle remains a tested safety reference and testing-only compatibility path, not current credential authority.
- Other integrations use provider-specific settings, encrypted fields, tokens, or credentials.
- Domain records may reference integrations, but there is no common Vault Item, relationship, grant, customer publication, recovery, or cross-provider lifecycle.
- There is no Vault workspace for technicians and no Vault surface in the customer portal.
- Vaultwarden is not an authoritative Nexum integration for credential inventory and conflict-safe item synchronization.
- Existing Laravel application encryption protects some values at rest, but application data, key hierarchy, independent recovery, mass rotation, compromise response, and per-item authorization are not one explicit Vault architecture.
- Existing audit and permissions are not one Vault-specific action model.
- Existing credential owners must continue working until each source passes an explicit staged migration and cutover.

The 2026-08-16 Email provider lifecycle remains the strongest security-pattern reference, but its provider-owned credential authority was superseded by the 2026-09-01 account-owned Email decision. Email currently owns mailbox account credentials; future Vault migration moves only secret material while Email retains account, endpoint, binding, and mailbox behavior and Integration retains provider Connections and adapters.

## Proposed Change

### 1. Create a singular Vault module

Create app/Modules/Vault as one standalone domain.

Vault owns:

- Vault Items and types;
- encrypted version payloads and encrypted attachments;
- safe and protected field classification;
- key references and cryptographic lifecycle;
- grants, flat collections, Vault access groups and their memberships, and publication policy;
- reveal, copy, use, export, rotation, revocation, recovery, and destruction decisions;
- customer-portal publication;
- record relationships;
- runtime secret-use grants;
- review dates and lifecycle alerts;
- Vault audit and security events;
- import/export jobs;
- external-vault mappings, desired state, conflicts, and resolution decisions.

Vault routes, controllers, Actions, Queries, policies, jobs, menus, tests, and Views live inside the singular Vault module. Integration adapters call Vault contracts; they do not own Vault records or secret copies.

### 2. Business ownership and item scope

Every Vault Item is owned by the Nexum installation and classified for one operational scope:

- internal organization;
- Client;
- Site;
- service or contract;
- machine/service identity;
- customer-published scope;
- another explicitly supported record scope.

A technician may have an individual access grant, but there is no private consumer password vault inside Nexum. Deactivation removes the technician's access and ownership responsibilities without deleting organizational credentials. An Admin may transfer responsibility, grants, reviews, and operational ownership.

Items may relate to multiple domain records through typed relationships. The source domain owns the related record and its visibility. Vault owns the secret and independently evaluates whether the current actor may perform the requested Vault action.

### 3. Vault Item types and field classification

The completed Vault supports at least:

- username/password login;
- API key, token, and OAuth material;
- SSH username/password, private key, certificate, and passphrase;
- database credentials and connection strings;
- Wi-Fi credentials;
- service-account credentials;
- recovery codes;
- certificate/private-key bundles;
- TOTP seeds and generated codes;
- secure notes;
- license and activation secrets;
- encrypted files and attachments;
- extensible structured item types and custom fields.

Each item type defines:

- safe metadata fields;
- protected metadata fields;
- secret fields;
- validation and normalization;
- reveal/copy/use behavior;
- supported runtime injection modes;
- rotation and verification capabilities;
- export/import mapping;
- customer-publication eligibility;
- search/indexing rules;
- retention and destruction behavior.

No field is treated as safe merely because it is called a username, title, URL, note, or metadata. Classification is explicit and versioned.

### 4. Versioning and lifecycle

A Vault Item has a stable UUID and immutable content versions.

A new or changed secret creates a staged version. The lifecycle supports:

- staged;
- verified where a supported verifier exists;
- active;
- retired;
- revoked;
- recovery-pending;
- archived;
- destroyed.

Activation records the exact version, actor, policy, reason, and verification state. Previous versions follow the configured retention and destruction policy. Rotation does not silently change active Connections or Automations; dependent bindings are revalidated and switched explicitly.

Revocation is a local Vault decision unless a provider Connector confirms external revocation. Nexum must never claim that a provider token, password, certificate, or session was revoked merely because local ciphertext was destroyed.

Destroyed ciphertext is unrecoverable through the application. Secret-free identity, lifecycle, relationship, and audit tombstones may remain according to retention policy.

### 5. Encryption and key architecture

Vault uses envelope encryption with authenticated encryption for every version payload and encrypted attachment.

The security ADR must define:

- data-encryption-key generation and per-item/per-version granularity;
- key-encryption-key hierarchy;
- cryptographic algorithms and libraries;
- nonce/IV generation and uniqueness;
- associated-data binding to installation, item, version, type, and classification;
- key provider interface;
- local sealed-key provider for installations without external KMS;
- external KMS/HSM providers;
- key versioning and cryptoperiod;
- rewrapping versus payload re-encryption;
- backup and recovery of key material;
- split storage of encrypted data and recovery/key material;
- startup/unlock behavior;
- compromise response and emergency rekey;
- memory exposure and best-effort zeroization;
- destruction and evidence;
- test vectors and compatibility.

Plaintext master keys and reusable decryption material must not be stored in the application database, repository, logs, ordinary configuration UI, or backup beside the ciphertext. The selected key provider must support unattended authorized runtime use without turning the database alone into the complete compromise boundary.

If the key provider is unavailable, Vault secret operations fail closed. Safe metadata may remain visible with an honest locked/unavailable status.

### 6. Search and indexes

Vault search operates on explicitly safe metadata and protected search representations. Plaintext secret fields are never placed in SQL full-text indexes, search engines, analytics, browser storage, or logs.

Searchable protected fields require an ADR-approved blind-index or equivalent design with:

- per-installation keyed derivation;
- normalization rules;
- collision and leakage analysis;
- query limits;
- key rotation;
- no substring or broad indexing unless its leakage is explicitly accepted.

Search results show only metadata the actor may view. The existence of an item may itself be restricted.

### 7. Authorization model

Vault authorization separates at least:

- list/discover;
- view safe metadata;
- view protected metadata;
- create;
- create a new version;
- reveal;
- copy;
- use without reveal;
- generate password;
- generate TOTP code;
- verify;
- rotate;
- grant/revoke access;
- publish/unpublish to customer portal;
- export;
- import;
- recover;
- archive;
- destroy;
- view audit;
- manage policy;
- manage key providers;
- perform break-glass access.

Effective authorization intersects:

- authenticated actor or workload;
- role and explicit Vault permissions;
- installation security policy;
- Client/Site/record visibility;
- item classification;
- item and collection grants;
- requested action;
- session and step-up freshness;
- device/risk policy where available;
- independent-approval policy;
- runtime Execution/Step authorization;
- external/customer scope.

Admin or Superuser status does not automatically imply routine secret reveal. Administrative policy access and secret-content access are separate.

When the locked durable candidate roster contains another Admin/Superuser identity, critical key,
recovery, broad export, destructive, and break-glass actions may require an independent approver who
is eligible for the mapped operation and decision-ready for that concrete decision. An ineligible
second candidate blocks sole-admin fallback rather than being ignored. A sole candidate may use an
exceptional self-approval path with fresh authentication, explicit reason, narrow scope, warnings,
enhanced audit, notifications, and mandatory verification. It cannot bypass a non-configurable
security floor.

The sole-admin candidate roster is durable current identity state: distinct human users whose
persisted status is exactly `ACTIVE` and who have protected Admin/Superuser identity, regardless of
Vault permission, TOTP, login, or step-up. Approval-eligible means candidate plus the exact live/
mapped `vault.approval_decide` permission, confirmed TOTP, and relevant company scope or exact Client
visibility; it never requires an active proof. `vault.policy_manage` is not a general grant-approver
requirement. Governance-recovery-eligible adds live/mapped `vault.policy_manage`; readiness-floor,
recovery, unlock and policy-governance counts use this durable eligible set, never current proof
possession. Decision-ready means the eligible class mapped by the operation plus a fresh active,
non-revoked, same-session/actor/epoch-bound step-up proof referenced by the exact plan, request and
concrete decision. Standard step-up is reusable until TTL expiry or revocation and has no consumed
state; only the separate TOTP-enrollment proof is one-use and consumed. A second candidate
who is not decision-ready blocks expansion rather than enabling sole-admin fallback.
The exception applies only when the locked roster contains exactly requester. Changes to roles,
permissions, TOTP eligibility, or transition to/from exact persisted `ACTIVE` status use the locked Vault authority-mutation boundary;
emergency roster shrink uses the closed `emergency_security_deactivation` reason and creates a fail-
closed quorum lock rather than a silent sole-admin path.

### 8. Authentication and Vault sessions

Vault actions use a separate short-lived Vault authorization state layered on the ordinary Nexum session.

Policy may require:

- enrolled Nexum 2FA;
- fresh password authentication;
- WebAuthn/passkey step-up;
- recent phishing-resistant authentication for critical actions;
- a short Vault timeout;
- action-bound nonce and replay protection;
- rate limits and temporary lockout;
- fresh approval after material item/version/target changes.

Reveal, copy, export, recovery, key-management, broad grants, and break-glass operations require step-up according to policy. A prior page login is not sufficient forever.

Sensitive responses use no-store/no-cache behavior and must not be placed in URLs, referrers, flash sessions, browser history, analytics, DOM data attributes, or server-rendered error traces. Clipboard clearing can be offered as best effort but must not be presented as guaranteed across browsers and operating systems.

### 9. Technician workspace

Vault provides a dense operational workspace consistent with Nexum UI rules:

- left sidebar for Vault areas, Clients, item types, collections, review/rotation state, conflicts, and saved filters;
- center workspace for searchable lists, item details, version/lifecycle actions, grants, and audit;
- right sidebar for relationships, effective access, security warnings, review dates, dependent Connections/Automations, and recent safe activity.

Technicians can reach Vault Items directly from Client, Site, Asset, WordPress, Service, domain, software, and Connection views without moving ownership into those domains.

Normal workflows include:

- create and classify an item;
- generate a strong password;
- create/edit a staged immutable version;
- verify and activate;
- reveal/copy after required step-up;
- use an approved launch or Connection flow;
- inspect dependencies before rotation/revocation;
- review access;
- publish to a customer;
- rotate, revoke, archive, recover, or destroy;
- inspect safe audit history;
- resolve external-vault conflicts.

Advanced cryptographic and policy details remain available without making high-frequency access unnecessarily complex.

### 10. Customer portal

Customer access is a first-class Vault surface, not a generic internal Vault view.

A customer contact sees only items explicitly published to:

- that contact;
- an approved customer role or group;
- the Client organization;
- a narrower Site or service scope.

Customer grants may allow:

- view protected metadata;
- reveal;
- copy;
- use without reveal where technically supported;
- TOTP display;
- download an approved encrypted attachment/certificate;
- request access;
- request change or rotation.

Customers cannot browse internal technician items, service identities, machine-only credentials, Connection secrets, other contacts' restricted items, internal audit, key management, or unrelated Client data.

The portal requires strong authentication, action-specific step-up, rate limits, short sensitive sessions, no-cache responses, complete safe audit, and visible last-updated/review information. Customer users cannot silently edit or delete operational credentials. Requested changes enter a technician-owned review workflow unless an explicit item policy supports controlled customer updates.

### 11. Secure runtime use without reveal

Vault supports bounded server-side use of a secret without exposing it to the requester.

The runtime flow is:

1. A caller requests a declared capability.
2. Integration resolves eligible Connections through #270.
3. The shared execution runtime in #272 authorizes one exact Execution Step.
4. Vault evaluates the actor/workload, Connection, target, item, version, action, expiry, and policy.
5. Vault issues a short-lived, non-exportable secret-use authorization.
6. Secret material is decrypted only inside the trusted Connector/runtime boundary.
7. The Connector performs the exact operation.
8. Results and errors are sanitized and verified.
9. Plaintext runtime material is discarded.
10. Vault, Integration, and Execution record separate safe audit facts.

The Agent/model receives only safe capability/readiness metadata and opaque references. It never receives passwords, tokens, private keys, recovery codes, TOTP seeds, authorization headers, or decrypted payloads.

Queues contain stable references and authorization identifiers only. The runtime re-resolves and revalidates immediately before execution. Revocation or material drift fails closed. There is no silent fallback to a broader item, legacy credential, system account, Connection, target, or endpoint.

A Vault-owned secure reveal card may be shown inside AI Chat after authorization, but its value is rendered by Vault outside model context and is never added to conversation history or Memory.

### 12. API and MCP boundary

Vault exposes domain APIs for authorized Nexum workflows, metadata management, lifecycle actions, grants, relationships, and bounded secret use.

Plaintext reveal/export endpoints are default-deny, separately scoped, strongly authenticated, rate-limited, audited, and unavailable to general MCP/Agent workloads. API keys do not receive reveal authority merely because they can manage Integration or Vault metadata.

MCP and Agent surfaces expose safe metadata and use-without-reveal capabilities only. A future external secret consumer requires a workload identity, explicit scope, short-lived grant, audience/target binding, and separate RFC or approved Feature Slice.

### 13. Audit, security events, and notifications

Vault maintains append-only safe events for:

- item and version creation;
- classification change;
- grant and publication change;
- reveal, copy, and runtime use;
- verification and activation;
- rotation and review;
- export and import;
- revocation, archival, recovery, and destruction;
- key-provider and key-version operations;
- customer access;
- break-glass access;
- adapter synchronization and conflicts;
- denied, failed, suspicious, or rate-limited actions.

Audit identifies actor/workload, action, safe item/version reference, scope, policy/approval result, time, correlation ID, and sanitized outcome. It never stores plaintext, ciphertext, fingerprints that enable offline guessing, raw provider errors, or unrestricted parameters.

Security notifications may report unusual access, repeated denials, broad exports, break-glass use, key-provider failure, overdue review, compromised/revoked items, sync conflicts, backup failure, and restore-test failure. Notifications contain no secret values.

Vault audit access is itself permission-controlled and logged.

### 14. Reviews, health, and operational signals

Each item may define:

- owner/responsible technician;
- review date and frequency;
- rotation date and frequency;
- verification state;
- dependent Clients/Sites/Assets/Connections/Automations;
- compromise or incident status;
- external-sync status;
- last successful runtime use;
- last failed/denied use;
- next required action.

Lifecycle and adapter jobs use the verified scheduler/queue foundation. A scheduler outage must not block ordinary authorized reveal, but it must make overdue review/sync/rotation status visible and must prevent false healthy claims.

Changes to a Client contract, Site, Asset, Connection, service identity, responsible technician, permission, or external mapping may trigger a review. They do not silently stop critical operations unless an explicit policy requires fail-closed behavior.

### 15. Import, export, and migration formats

Vault supports controlled import from Passportal-style exports, Vaultwarden/Bitwarden-supported paths, CSV/JSON mappings, and provider-specific sources.

Import uses an isolated staging workflow:

- identify source and authority;
- validate schema and size;
- scan attachments where supported;
- classify fields;
- map Clients, Sites, records, types, groups, and relationships;
- detect duplicates without exposing secrets;
- preview counts and conflicts;
- require explicit authorization;
- create staged versions;
- verify and activate separately;
- destroy temporary plaintext and staging artifacts.

Bulk plaintext export is not the normal backup mechanism. Export requires explicit scope, step-up, approval policy, reason, audit, rate/quantity limits, and an encrypted recipient/recovery format where supported. Plain CSV export is disabled by default and may be prohibited by installation policy.

Portable packages never include secrets unless they use the explicitly encrypted Vault export channel.

### 16. Vaultwarden and external-vault adapters

Vaultwarden is the required first external-vault adapter for the completed Vault product.

Integration owns:

- the Vaultwarden Connector;
- endpoint and transport policy;
- adapter authentication;
- capability/version detection;
- connection health;
- provider communication.

Vault owns:

- item/organization/collection/user/group mappings;
- desired publication state;
- imported item provenance;
- version comparison;
- conflict records;
- resolution decisions;
- customer publication policy;
- sync audit;
- revocation/offboarding intent.

Nexum is authoritative after an item is adopted into Nexum Vault. External changes never silently overwrite a newer or divergent Nexum version.

The adapter supports:

- initial inventory and mapping;
- explicit controlled import;
- organization/collection mapping;
- user/group/contact mapping;
- item publication;
- version comparison;
- conflict detection and resolution;
- customer browser/mobile availability;
- health/compatibility monitoring;
- removal/revocation/offboarding;
- safe retry and reconciliation.

Bitwarden's organization Public API and item-oriented Vault Management API have different capabilities. The adapter therefore declares and verifies capabilities instead of assuming one universal API. Vaultwarden compatibility is versioned and fail-safe.

An external-vault outage does not make Nexum Vault unavailable. Sync lag and conflicts are visible, retryable operational states.

### 17. Backup, recovery, and disaster response

Vault backup and recovery are product features, not deployment notes.

The completed design provides:

- encrypted database and attachment backups;
- separate protected backup of key/recovery material;
- restore procedures for application data, item versions, grants, audit, and key references;
- scheduled restore verification in an isolated environment;
- recovery-package versioning and custody records;
- recovery without silently weakening access controls;
- documented loss scenarios;
- emergency lock and provider disablement;
- compromise triage and mass rekey/rewrap procedures;
- proof that retired/destroyed material behaves as intended;
- retention and legal/audit review before destructive purge.

A database backup alone must not be sufficient to decrypt Vault data. A key backup alone must not expose Vault content.

Recovery actions require the strongest configured step-up and approval. Every recovery attempt is audited.

### 18. Security floor

The following cannot be disabled:

- authentication and explicit authorization;
- Client/Site/record isolation;
- authenticated encryption;
- cryptographically secure randomness;
- secret filtering from prompts, logs, queues, notifications, analytics, search, URLs, sessions, audit payloads, and errors;
- TLS and SSH host-key verification at Connection boundaries;
- rate/quantity/time limits;
- stale-version and revoked-item denial;
- no silent fallback;
- no AI/model plaintext access;
- no customer access without an explicit publication grant;
- safe audit for reveal/use/export/recovery/destruction;
- key-provider and backup health visibility;
- migration preview, verification, read-back, and rollback;
- independent security review before production credential migration.

Settings may make policy stricter, but cannot weaken this floor.

### 19. Completion definition

Vault is not presented as complete until all of the following are delivered and verified:

- standalone Vault module and Admin settings;
- internal technician workspace;
- supported item types, structured/custom fields, encrypted attachments, and TOTP;
- immutable versions and complete lifecycle;
- relationship model and shortcuts from relevant domains;
- granular grants, flat collections, Vault access groups/memberships, step-up, approvals, and break-glass;
- customer-portal publication and access;
- runtime use-without-reveal through #270/#272;
- controlled import and encrypted export;
- Vaultwarden adapter with conflict-safe synchronization;
- key-provider setup, rotation, backup, recovery, and restore testing;
- operational monitoring, reviews, alerts, audit, and incident procedures;
- inventory and explicit migration or retirement of every existing Nexum credential store;
- no remaining runtime path that silently reads a legacy secret after cutover;
- automated tests, security tests, penetration/security review, Knowledge documentation, deployment runbooks, and completed human review.

Feature Slices are implementation controls, not permission to declare a partial Vault finished.

## Impact Analysis

### Modules and ownership

- Vault: new authoritative domain for secret storage, lifecycle, grants, flat collections, Vault access groups and memberships, authorization decisions, relationships, publication, runtime authorization, audit, import/export, recovery, and external-sync state.
- Integration: Connectors, Connections, endpoints, provider adapters, health, Connection Broker, and external-vault adapter transport.
- UserManagement: user identity, exact persisted `PENDING_INVITE|ACTIVE|DISABLED` state, password and 2FA/passkey enrollment, ordinary session security, and general roles/permissions. Only `ACTIVE` human Admin/Superuser identities are Vault approver candidates. Vault references those current facts but owns Vault access groups, their memberships, and approver decisions.
- Client/Site: record visibility, customer/contact associations, and portal scope.
- Asset, Service, Contract, WordPress, Domain, Network, Email, and other modules: authoritative related records and domain actions.
- Customer Portal: Vault-owned customer views and actions using Client/UserManagement identity.
- Notification: safe security and lifecycle notifications.
- Audit/operations: protected event review, scheduler/queue health, backup and recovery status.
- AI/Agent/Memory: safe references only; Memory and model context never receive secret values.
- Automation/Tools/Scripts: bounded use through #270/#272; definitions remain secret-free.
- API/MCP: scoped metadata and lifecycle contracts; use-without-reveal for workloads; plaintext default-deny.

### Permissions

New permissions must separate policy administration, metadata, secret content, customer publication, runtime use, audit, export, recovery, key management, migration, and break-glass.

Existing broad Integration/Admin permissions are not automatically mapped to reveal/export/recovery authority.

The central-authorization slice adds only `vault.grant_manage` and `vault.approval_decide` to the
existing four control-plane permissions. `vault.policy_manage` remains settings/policy authority,
not grant authority. Admin and Superuser receive the two new control permissions through an additive
migration; Tech and Viewer receive none. No content permission or content operation is introduced
by that slice.

### Routes and UI

All Vault routes live in app/Modules/Vault/routes.php. Controllers and Views follow Tech/Admin/Client separation. Vault receives its own workspace and Admin menu area. Related domains link to Vault rather than embedding a second credential editor.

Navigation clarification confirmed by Svein on 2026-09-10: the technician entry is
`Documentations -> Vault`, opening the Vault-owned workspace. Menu placement does not transfer
credential ownership to Knowledge/Documentation. Do not expose a placeholder entry before its
working, authorized UI exists. Request practical human review only when that workflow is usable.

### Queue and scheduler

Required for review reminders, rotation tasks, import/export, external sync, reconciliation, backup/restore verification, security alerts, and cleanup. Queue payloads contain references only.

### Security and operational risk

Vault concentrates high-value secrets and becomes a critical security boundary. Primary risks include key compromise, cross-Client authorization failure, secret leakage through secondary channels, malicious import, privileged insider misuse, unsafe recovery, external-sync overwrite, stale runtime grants, and incomplete migration. Threat modeling, ADRs, broad negative testing, and independent review are mandatory.

## Data And Migration Plan

### Logical records

The final schema is defined in Feature Slices after ADR approval, but must represent:

- Vault Item identity and safe lifecycle metadata;
- immutable item versions with encrypted payloads;
- encrypted attachment objects and versions;
- item types and field schemas;
- typed record relationships;
- flat collections, Vault access groups, group memberships, grants, and publication records;
- runtime secret-use authorizations;
- approval references;
- review/rotation schedules;
- safe audit/security events;
- key providers and key-version references without plaintext keys;
- recovery packages/custody state;
- import/export runs and items;
- external-vault connections, mappings, cursors, conflicts, and resolutions;
- migration runs/items for legacy Nexum sources;
- keyed/blind search index records where approved.

All primary IDs are stable and non-sequential where exposure would leak volume or relationships. Foreign keys and deletion rules preserve audit and prevent orphaned active dependencies.

### Migration sequence

1. Inventory every credential/secret path in authoritative Dev and classify owner, encryption, caller, rotation, queue exposure, and migration risk.
2. Approve threat model and security ADRs.
3. Add the dormant Vault module, permissions, key-provider contract, and health checks.
4. Implement encryption/versioning/audit foundations and prove backup/recovery before real migration.
5. Implement internal workflows, grants, step-up, relationships, and lifecycle.
6. Implement #270/#272 runtime integration.
7. Migrate one non-production credential source through preview, stage, verify, cutover, read-back, rollback, and separate purge approval.
8. Migrate existing sources provider by provider: AI, Email, BookStack, RMM, Nextcloud, CloudFactory, and every discovered source.
9. Enable customer portal only after isolation and authentication review.
10. Enable Vaultwarden import/publication and conflict-safe sync.
11. Remove direct legacy reads only after every dependent runtime and rollback path is verified.
12. Purge legacy ciphertext only through a separately explicit, human-reviewed operation with backup/recovery evidence.
13. Complete production security review and human-review checklist before declaring Vault complete.

Migration never performs a big-bang copy or silently merges items because usernames, hosts, titles, or fingerprints appear equal. Each source has explicit authority, version, status, rollback window, and safe evidence.

Existing behavior remains compatible until an individual source is cut over. After cutover, that source fails closed rather than falling back to its legacy secret.

Rollback switches the explicitly selected source back only while the declared rollback window and unchanged legacy evidence remain valid. New Vault versions, rotations, revocations, exports, customer publication, or external sync may invalidate rollback.

## Testing Plan

### Unit and cryptographic tests

- key-provider contract and failure behavior;
- envelope encryption/decryption and associated-data binding;
- tamper detection;
- nonce/IV uniqueness;
- key versioning, rewrap, rotation, and destruction;
- payload schema/version compatibility;
- field classification and serialization;
- blind-index normalization/leakage boundaries;
- password generation and TOTP correctness;
- redaction and sensitive-parameter handling;
- lifecycle state transitions;
- grant/policy evaluation;
- runtime authorization expiry and drift.

### Feature and permission tests

- internal and Client/Site isolation;
- Admin versus secret-content permission separation;
- create/version/verify/activate/reveal/copy/use/rotate/revoke/archive/recover/destroy;
- flat collections, Vault access groups/memberships, item grants, and deactivated technicians;
- independent approval and sole-admin exception;
- session timeout and step-up;
- customer-contact publication and recipient isolation;
- prohibited customer editing/deletion;
- import preview and explicit apply;
- encrypted export and blocked plaintext export;
- audit visibility and access logging;
- break-glass workflow;
- related-record shortcuts and inaccessible-item behavior.

### Security tests

- secrets absent from logs, sessions, queues, notifications, audit payloads, analytics, search, URLs, exceptions, Telescope/debug output, model prompts, chat, Memory, packages, and browser caches;
- no mass-assignment or serialization leakage;
- cross-Client ID substitution;
- stale/version-swapped item use;
- revoked/destroyed item use;
- replayed runtime grants;
- target/Connection substitution;
- malicious structured fields and attachments;
- CSV/JSON formula and parser abuse;
- export abuse and rate limits;
- external-adapter spoofing, replay, conflict, and downgrade;
- key-provider unavailable/compromised states;
- backup theft assumptions;
- recovery and break-glass abuse;
- penetration testing before production migration.

### Integration and migration tests

- #270 Connection Broker contract;
- #272 Execution/Approval contract;
- AI/provider runtime secret isolation;
- Automation standing authorization;
- Email provider lifecycle parity;
- each legacy provider migration;
- Vaultwarden capability detection, import, publish, update, conflict, retry, removal, and outage;
- customer-portal authentication and authorization;
- queue restart, duplicate jobs, idempotency, partial failure, and reconciliation;
- backup and isolated restore drills.

### Manual human review

Manual review must cover:

- technician workflows;
- customer portal;
- step-up and approvals;
- reveal/copy behavior;
- audit;
- migration preview/cutover/rollback/purge;
- Vaultwarden browser/mobile result;
- key rotation;
- backup/restore;
- incident lock;
- accessibility and responsive behavior;
- failure messages that do not leak secrets.

Passing automated tests never marks the Vault human-reviewed.

## Documentation Plan

Create and maintain:

- Vault user guide for technicians;
- customer-portal Vault guide;
- Admin policy and permission guide;
- item type and relationship guide;
- lifecycle, rotation, revocation, destruction, and recovery guide;
- key-provider setup and rotation runbook;
- backup, restore, and disaster-recovery runbook;
- incident and compromise runbook;
- import/export guide;
- legacy Nexum credential migration guide;
- Vaultwarden adapter and conflict-resolution guide;
- runtime use-without-reveal contract;
- API/MCP security guide;
- operational monitoring and troubleshooting;
- threat model, RFC, ADRs, Feature Slices, and human-review checklist;
- Knowledge articles suitable for BookStack synchronization while Nexum remains authoritative.

Documentation must never contain real credentials, screenshots with secrets, recovery material, or reusable example tokens.

## Required ADRs

Implementation requires accepted ADRs for:

1. Vault domain ownership and relationship boundary.
2. Envelope encryption, key hierarchy, algorithms, and key-provider interface.
3. Key backup, recovery, break-glass, and compromise response.
4. Vault authorization, step-up authentication, approvals, and sole-admin exception.
5. Item/version lifecycle, destruction, retention, and audit.
6. Runtime secret-use boundary across Vault, #270, and #272.
7. Customer-portal publication and authentication boundary.
8. Protected search and blind-index leakage policy.
9. Import/export and legacy migration safety.
10. Nexum authority and Vaultwarden synchronization/conflict behavior.
11. Encrypted attachments and malware-scanning boundary.
12. Deployment, backup, restore, and production security gate.

Existing ADRs that assign final credential ownership to Integration must be marked compatibility-only or superseded when their sources migrate. Their endpoint, verification, and staged-migration security controls remain reusable.

## Feature Slice Outline

All slices are required for product completion:

1. Threat model, ADR set, credential inventory, and completion matrix.
2. Vault module, permissions, key-provider health, encryption, versions, and safe audit.
3. Technician workspace, item types, relationships, search, grants, collections, and step-up.
4. Lifecycle, verification, review/rotation, dependency impact, revocation, destruction, and recovery.
5. Runtime use-without-reveal through #270/#272.
6. Existing Nexum credential migrations, one provider/source at a time.
7. Customer-portal Vault and customer publication.
8. TOTP, encrypted attachments, controlled import, and encrypted export.
9. Vaultwarden adapter, browser/mobile publication, conflict resolution, and offboarding.
10. Backup/restore, key rotation, incident response, monitoring, penetration/security review, Knowledge documentation, and human review.

A slice may be divided further for safe implementation. No slice may weaken the final completion definition or be treated as optional merely because AI can already consume Vault references.

## Security References

The security ADRs and implementation should align with:

- OWASP Secrets Management Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html
- NIST key-management guidance: https://csrc.nist.gov/projects/key-management/key-management-guidelines
- NIST SP 800-63B authentication and reauthentication guidance: https://pages.nist.gov/800-63-4/sp800-63b.html
- Bitwarden API capability documentation: https://bitwarden.com/help/bitwarden-apis/

These references guide controls but do not replace Nexum-specific threat modeling, testing, or approval.

## Open Questions

No product question blocks RFC review.

The ADR process must select the concrete cryptographic library, key providers, protected-search design, recovery custody, and Vaultwarden transport after threat modeling and authoritative Dev inspection. Those decisions may not weaken the goals, security floor, migration safety, or completion definition in this RFC.

## Approval

Approved by Svein Tore on 2026-09-04 after reading the complete RFC and ADR set in Discussion #279.

Implementation may proceed through the approved Feature Slices. Production credential migration remains separately gated by independent security review and the Vault human-review checklist.
