# ADR: Supplier Email Purchase Import Ownership And AI Decision Boundary

Status: Superseded
Date: 2026-08-04
Decision Makers: Svein / Codex
Superseded by: `docs/adr/2026-08-10-managed-domain-ai-and-system-actor.md`

The newer ADR changes the configured technical-User and capability-empty-agent decisions. It
reaffirms the remaining domain ownership, profile, validation, idempotency, audit, and no-receipt
boundaries recorded here.

## Context

Nexum needs to turn supplier order-confirmation emails into Purchase Orders without hardcoding one
PHP parser per supplier. The workflow crosses Email source retention, Signal automation, Storage
purchase and inventory invariants, Documentation supplier identity, and Integration AI governance.

The architecture must support:

- Different supplier formats in different Nexum installations.
- Manual and deterministic use without AI.
- Optional AI extraction, profile creation, profile repair, and high-confidence automation.
- Reproducible profile behavior, fixture testing, rollback, and complete provenance.
- Automated Purchase Order creation without giving an AI model direct write authority.
- A source-email card and repair workflow on the resulting Purchase Order.

Existing ADRs already establish that Signal is an active cross-domain automation layer, Integration
owns AI data egress and provider execution, Storage owns procurement and immutable receipt posting,
and Email/Ticket rules hand off to Signal explicitly. A specific ownership and decision boundary is
required so this feature does not create a second procurement domain or a domain-specific AI
transport path.

## Decision

### Domain Boundaries

Email owns inbound ingestion, sanitized/raw source retention, attachment access, trusted
sender-authentication facts, message-local rules, and explicit Signal handoff.

Signal owns the normalized supplier-order event, cross-module rule orchestration, stable action
keys, loop protection, immutable execution audit, and retry. Signal does not parse supplier orders
or decide whether a Purchase Order is valid.

Storage owns the canonical purchase-order document schema, supplier import profiles, immutable
profile/policy versions, fixtures, import ledger, source snapshot, extraction interpretation, Item
resolution, confidence dimensions, hard validation gates, exception workflow, source-email card,
and final calls to existing Purchase Order actions.

Integration owns AI provider/model/agent/workload configuration, installation and recipient
governance, privacy/data-egress policy, provider transport, execution UUIDs, sanitized usage/cost
telemetry, and provider health. Storage calls this shared boundary and never issues provider HTTP
requests directly.

Documentation remains the owner of canonical Vendor/Supplier records. Any automatic supplier
bootstrap uses a Documentation-owned action under Storage policy; Storage does not write the Vendor
table directly.

### Profiles And Learning

Supplier extraction behavior is stored as constrained declarative data. A profile has immutable
versions containing document signatures, safe structural mappings, aliases, normalizers,
validators, defaults, thresholds, and AI policy. Profiles never contain executable code or arbitrary
tools.

Every import pins an exact profile and policy version. Manual edits or AI repair create a new draft
version rather than mutating the active definition. A candidate must pass current-source and golden
fixture replay plus configured shadow checks before activation. Settings may auto-activate a passing
candidate. Previous validated versions remain available for rollback.

Manual profile creation, editing, testing, activation, export/import, and rollback remain fully
functional without AI.

### AI Boundary

The dedicated Purchase Order Import Agent has `can_execute_actions=false`. It may return a strict
canonical extraction, evidence, Item suggestions, or a declarative profile-version candidate. It
cannot write a Vendor, Item, profile, Signal rule, Purchase Order, shipment, receipt, Stock Unit, or
Movement directly.

AI output is untrusted input to Storage. Storage verifies evidence, recomputes arithmetic, resolves
identity, applies the effective settings hierarchy, and invokes normal domain actions only when all
hard gates pass.

Model self-reported confidence is non-authoritative. Storage keeps separate source-trust, document,
field/line extraction, Item-resolution, and deterministic-validation dimensions. The weakest
critical dimension and action-specific thresholds govern the outcome.

### Mutation And Idempotency Boundary

A configured least-privilege technical User is the actor for automated finalization. Missing,
disabled, or unauthorized actor state fails closed. Signal-rule management does not confer Storage
write permission.

Purchase Order creation goes only through Storage's existing validated action boundary inside a
database transaction. One Storage import owns the domain idempotency identity for the supplier,
external order, source, and Signal action. Queue, Signal, and manual retries return the existing side
effect.

Manual and email-created Purchase Orders share the database-enforced identity
`(supplier/vendor_id, supplier order number/vendor_ref)`. The readable fields remain authoritative;
a database-generated normalized key and composite unique index are the final write boundary across
active and soft-deleted history. Application hashing asks the active database for the same
normalization. Finalization validates that the canonical document, import header, and persisted
source-line projection agree before it creates or links a PO. Race recovery uses a current locking
read. Ordinary edits cannot split a vendor-confirmed identity; governed AI correction is allowed
only before shipment/receipt/cancellation history and updates the locked PO/import pair atomically.

Order-confirmation automation never posts a receipt or changes stock. Shipment, cancellation, and
receipt history keep their existing immutability guards for both manual and AI-assisted repair.

### Audit And Retention Boundary

Signal execution rows, Integration AI telemetry, Email source records, and Storage import attempts
remain separate because they have different ownership, content, permissions, and retention.
Storage retains a sanitized immutable source snapshot and fingerprints needed to explain the PO,
but not raw EML, unrestricted headers, credentials, prompts, or raw model replies.

## Rationale

- Preserves the accepted module ownership model.
- Avoids a brittle PHP adapter for every supplier while retaining deterministic fast paths.
- Makes supplier behavior installation-configurable and usable without AI.
- Allows AI to reduce recurring exceptions without granting opaque write authority.
- Keeps active profile behavior reproducible, testable, versioned, and reversible.
- Reuses one privacy and provider execution boundary instead of creating direct domain integrations.
- Keeps Purchase Order, Item, warehouse, lifecycle, and receipt invariants inside Storage.
- Makes high autonomy compatible with hard evidence, arithmetic, authorization, and idempotency.
- Keeps future source-card and repair decisions explainable to technicians and administrators.

## Consequences

Positive:

- Known suppliers can run deterministically and quietly.
- Unknown or changed formats can use governed AI and teach reusable profiles.
- Non-AI installations retain full manual/profile functionality.
- Every import is traceable to its source, profile, policy, attempts, evidence, and actor.
- Profile rollback and circuit breakers limit template-drift blast radius.
- The same pattern can later support shipment or invoice documents under separate approval.

Negative:

- The feature requires several relational records and lifecycle concepts.
- Protected fixture collection and profile health need operational maintenance.
- Queue and Integration availability become dependencies for active automation.
- A conservative identity policy may create separate Items instead of risky merges.
- Cross-module permissions, retention, and regression testing are substantial.
- Automatically created profiles and Items require visible provenance and later catalog hygiene.

## Alternatives Considered

- **One PHP parser class per supplier.** Rejected because every new supplier/template would require a
  deployment and would not scale across Nexum installations.
- **Parse and own the workflow in Email.** Rejected because Email does not own Item, warehouse, PO,
  or receipt invariants.
- **Parse and approve in Signal.** Rejected because Signal is orchestration and audit, not a second
  procurement domain.
- **Use the generic Signal AI classifier and one confidence percentage.** Rejected because document
  classification is not commercial extraction, identity resolution, or authorization.
- **AI-only extraction with no profiles.** Rejected because it is less reproducible, more costly,
  harder to test, and unusable without an AI provider.
- **Deterministic-only extraction.** Rejected as the only product direction because new suppliers and
  template drift would create recurring manual exceptions; retained as a complete supported mode.
- **Let AI generate executable parsers or mutate the active profile in place.** Rejected because it
  removes safe review, reproducibility, rollback, and code-execution boundaries.
- **Let AI write Purchase Orders or Items directly.** Rejected because model confidence cannot
  replace domain validation, authorization, transactions, and idempotency.
- **Send a webhook to n8n and call the Storage API.** Rejected as the product architecture because
  identity, authorization, audit, retry, and profile state would be split across systems.
- **Store all behavior in `common_settings` JSON.** Rejected because versions, fixtures, actors,
  health, imports, and constraints require relational ownership.
- **Auto-merge Items by name similarity.** Rejected because a distinct supplier Item is safer than
  contaminating stock identity.

## Follow-Up

- Implement `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md` through its
  ordered Feature Slices.
- Verify the authoritative Dev queue/scheduler before active inbound automation.
- Complete and verify applicable AI governance and execution-boundary reviews before external AI.
- Define the constrained profile DSL and canonical schema as versioned contracts.
- Collect protected multi-example supplier fixtures before threshold calibration.
- Update Storage, Email, Signal, Integration, Documentation, and operations Knowledge sources during
  implementation.
- Keep the generic Inbox **Analyze with AI** bootstrap flow behind a separate future RFC.
