# RFC: Storage Supplier Email Purchase Order Automation

Status: Approved
Date: 2026-08-04
Owner: Svein / Codex
Change Level: 3

## Context

The implemented Storage purchase workflow can register an externally placed supplier order, track
several shipments, and receive partial quantities through an immutable inventory-posting ledger.
It does not yet turn an inbound supplier order confirmation into a Purchase Order.

Supplier confirmations commonly contain a supplier order number, buyer reference, delivery method,
delivery address, product lines, supplier SKUs, quantities, prices, freight, discounts, and totals.
An Itegra confirmation demonstrates the workflow, but Nexum must not solve the problem with an
`ItegraParser` or PHP rules tied to one sender, one line number, or one HTML template. Different
installations use different suppliers, and supplier templates change without notice.

The intended product is exception-driven automation:

- Normal, trusted, validated messages should quietly become Purchase Orders.
- Deterministic profiles should handle known formats without AI.
- Governed AI should resolve unknown formats and uncertain fields when enabled.
- AI should create and improve reusable profile versions so the same uncertainty is not paid for or
  reviewed repeatedly.
- A technician should work only with genuine exceptions, not approve every successful import.

This is a Level 3 change because it adds database schema, cross-module automation, queue behavior,
permissions, AI data egress, automated Storage writes, and new Admin and technician workflows.

This RFC is a successor to
`docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md`. It does not rewrite the history
or non-goals of that implemented RFC. Nexum still records an order that was placed elsewhere; it
does not place the supplier order.

Related governing documents include:

- `docs/rfc/2026-07-08-email-ticket-signal-rule-alignment.md`.
- `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`.
- `docs/rfc/2026-07-27-ai-model-usage-and-cost-telemetry.md`.
- `docs/adr/2026-06-09-signal-active-rule-domain.md`.
- `docs/adr/2026-07-14-central-privacy-gate-for-ai-data-egress.md`.
- `docs/adr/2026-07-15-signal-rule-execution-recovery.md`.
- `docs/adr/2026-07-27-integration-owned-ai-model-execution-telemetry-boundary.md`.
- `docs/adr/2026-08-04-storage-procurement-documentation-carrier-ownership.md`.
- `docs/adr/2026-08-04-storage-immutable-receipt-ledger-and-atomic-posting.md`.

### Terminology

In this feature, **auto-register** means that Nexum records a supplier order already placed outside
Nexum and gives the Purchase Order status `ordered`. It does not mean that Nexum approves purchasing
authority, accepts supplier terms, performs checkout, or sends an order.

A **profile** is installation-owned, declarative configuration that converts one supplier document
family into Nexum's canonical purchase-order schema. It is data, not executable PHP or JavaScript.

An **import** is the durable Storage-owned record of one source message, its extraction attempts,
evidence, decisions, line resolution, and resulting Purchase Order or exception.

## Goals

- Convert selected inbound supplier confirmations into Storage Purchase Orders without
  supplier-specific source-code changes.
- Use one canonical purchase-order document schema across email HTML, plain text, and later approved
  safe attachment extractors.
- Support global defaults, supplier-profile overrides, and action-specific automation rules.
- Support deterministic-only installations with complete manual profile management and testing.
- Use AI as an optional, governed fallback for extraction, Item resolution suggestions, profile
  creation, profile repair, and profile version testing.
- Let an AI-enabled installation auto-register an order when Nexum-computed confidence and all hard
  validation gates pass.
- Create or update profiles automatically after validated fixture and shadow testing when settings
  allow it.
- Provide a searchable exception queue for unresolved or unsafe imports.
- Persist exact supplier-SKU mappings so a resolved line becomes deterministic next time.
- Allow settings-controlled creation of a distinct supplier-imported Item instead of incorrectly
  merging products based only on name similarity.
- Keep an immutable, sanitized source-email snapshot and automation trace available from the
  resulting Purchase Order.
- Let authorized users edit a safe Purchase Order manually or invoke **Repair with AI** from the
  source card.
- Make retries, duplicate messages, changed resends, provider failures, and worker crashes safe and
  explainable.
- Preserve source commercial facts such as freight, discount, delivery method, delivery address,
  and source totals without pretending they are inventory receipts or accounting postings.
- Keep receipt confirmation as the only boundary that changes on-hand inventory.

## Non-Goals

- Submit, transmit, or place a supplier order.
- Log in to a supplier portal, follow checkout links, or authorize payment.
- Infer delivery, receive goods, post Stock Units, create receipt Movements, or change on-hand stock
  from an order-confirmation email.
- Perform supplier invoice matching, accounts payable, landed-cost allocation, payment posting, or
  three-way matching.
- Fetch click-tracking URLs, product URLs, remote images, or arbitrary network resources found in an
  email or AI response.
- Let AI generate executable parsers, run tools from message instructions, write directly to domain
  tables, or bypass Storage actions and permissions.
- Fine-tune a model as a requirement for supplier support.
- Make an external AI provider mandatory. Manual and deterministic workflows remain first-class.
- Automatically rewrite a Purchase Order after shipment, cancellation, or receipt history has made
  commercial fields immutable.
- Add a generic **Analyze with AI** button to arbitrary Inbox messages in this implementation. That
  future feature may classify a message, propose a Signal rule, and create a profile, but it needs a
  separate RFC for purpose selection, permissions, AI egress, previews, budgets, and side effects.
- Expose any unfinished Inbox, AI, profile, or automation control before its behavior is implemented
  and tested.

## Current Behavior

- Email stores inbound message metadata, headers, sanitized HTML, normalized plain text, a raw EML
  path, and attachment counts before it runs inbound rules.
- Email deduplicates the same account, mailbox, and IMAP UID, but a resent confirmation can still
  arrive as a different message.
- Email rules can match sender, domain, recipient, subject, body, message identifiers, and related
  facts. They can explicitly emit an idempotent Signal.
- Email owns default ticket ingress. A supplier-order rule must explicitly stop ordinary ticket
  routing for only the messages it matches.
- Signal owns normalized events, ordered rule execution, stable action keys, immutable execution
  attempts, retry, and cross-module orchestration. It has no Storage purchase-import action today.
- The generic Signal AI classifier identifies a permitted signal type. Its confidence value is not
  a commercial extraction or Purchase Order authorization decision.
- Integration owns provider-neutral AI configuration, agents, governance, privacy policy, execution,
  and usage telemetry.
- Storage `StorePurchaseOrder` requires an active supplier, active destination warehouse, an
  internal unique PO number, an order date for `ordered`, and one or more resolved lines.
- Every Purchase Order line requires an existing active, orderable Item in the destination
  warehouse. Unresolved source lines cannot be stored as partially valid PO lines.
- Exact supplier mapping can use `storage_item_vendors`, but the current database does not guarantee
  that one supplier SKU maps to only one Item. The importer must handle zero, one, or several
  matches explicitly.
- Shipment, cancellation, and receipt history lock affected commercial line fields. Existing domain
  guards must also apply to manual and AI-assisted corrections.
- Purchase Orders currently have no source-email card, import ledger, versioned extraction profile,
  PO-specific AI workload, or `(supplier, external order number)` import idempotency boundary.
- The Dev environment has a known persistent queue/scheduler operational blocker. Active inbound
  automation cannot be declared operational until the authoritative worker and scheduler are
  configured and verified.

## Proposed Change

### 1. Domain Ownership

Email owns:

- Durable inbound-message ingestion and raw source retention.
- Sanitized body and attachment access.
- Trusted sender-authentication facts derived from configured receiving hops.
- Message-local matching, archive/tag state, default ticket suppression, and explicit Signal
  handoff.

Signal owns:

- The normalized `supplier_order_confirmation_received` event.
- Cross-module rule selection, action ordering, retry, loop protection, and immutable action audit.
- A new action that queues a Storage import with the stable Signal action key.

Signal does not own document parsing, supplier identity, Item resolution, profile configuration, or
the Purchase Order decision.

Storage owns:

- The canonical supplier-order schema.
- Global automation policy and supplier import profiles.
- Immutable profile and policy versions, fixtures, health, and activation.
- Import state, source snapshots, extraction, evidence, validation, Item resolution, exception
  handling, and final Purchase Order creation.
- The Admin profile editor, import queue, Purchase Order source card, manual repair, and AI repair
  workflow.

Integration owns:

- AI providers, agents, models, processing modes, privacy/data-egress enforcement, provider
  transport, execution telemetry, rate/cost evidence, and provider health.

Documentation continues to own canonical Vendor/Supplier records. If policy permits automatic
creation of an unknown supplier, Storage must call a Documentation-owned action rather than write
the Vendor table directly.

### 2. Canonical Document Contract

Every extractor returns one versioned schema independent of supplier layout. The contract includes:

- Document type and source identity.
- Supplier identity evidence and external order number.
- Explicit order date or a documented fallback with provenance.
- Currency and locale.
- Buyer reference and supplier PO reference when present.
- Delivery method, delivery address, and expected date when present.
- Lines with source row, supplier SKU, description, quantity, unit price when explicit, line total,
  tax facts when explicit, and evidence anchors.
- Goods subtotal, freight, discount, other charges, tax totals, total excluding tax, and total
  including tax when present.
- Unknown fields and validation warnings; missing values are never invented.

The normalization layer represents sanitized HTML as safe DOM/text blocks and tables rather than a
single flattened string. It also retains a plain-text fallback. An extractor must not depend on a
fixed line number. A profile may identify labels, table headers, and safe structural selectors.

The first active supplier slice uses stored sanitized HTML and plain text. Safe PDF or other
attachment extraction may be added in the hardening slice after attachment metadata persistence,
content limits, malware handling, and parser isolation are verified.

### 3. Versioned Supplier Profiles

A supplier profile is installation-specific and may be created manually, seeded from a safe profile
library, or created by AI. A profile container stores identity, supplier association, matching
scope, lifecycle, and its active version. Immutable versions store:

- Document signatures and priority.
- Email account, mailbox, recipient, exact sender, domain, and trusted authentication requirements.
- Locale, date/decimal formats, label aliases, safe selectors, table/column mappings, required
  fields, normalizers, and validators.
- SKU canonicalization rules that do not silently alter meaningful supplier identifiers.
- Default warehouse, currency, date fallback, PO-number strategy, and delivery mapping.
- Item identity order and new-Item defaults.
- Amount, quantity, line-count, currency, and variance limits.
- Automation mode and action-specific thresholds.
- AI extraction, consensus, repair, shadow, and activation policy.
- Version checksum, parent version, source, fixtures, test metrics, activation actor/reason, and
  timestamps.

The profile definition is a constrained, versioned DSL. It may use allowlisted selectors,
label aliases, bounded patterns, normalizers, and validators. It never contains executable PHP,
JavaScript, shell commands, provider tools, or arbitrary network calls.

Profile lifecycle is `draft`, `shadow`, `active`, `degraded`, `paused`, or `retired`. A profile
version is `draft`, `validated`, `active`, `superseded`, or `rejected`. Activating a new version does
not mutate the previous version or in-flight imports. Rollback selects an earlier validated version.

### 4. End-To-End Flow

1. Email durably stores the inbound message.
2. A configured Email rule matches the intended mailbox, recipient, source, and document markers;
   archives or categorizes it as configured; stops ordinary Ticket ingress; and emits
   `supplier_order_confirmation_received`.
3. A Signal rule invokes a new idempotent Storage import action.
4. A queued Storage job creates or locks the import and pins the source, action key, effective
   policy, and profile version.
5. The profile engine performs deterministic extraction first.
6. When extraction is incomplete or uncertain and policy allows it, Storage calls the dedicated
   Purchase Order Import Agent through Integration's governed execution boundary.
7. Storage verifies source evidence, schema, locale, required fields, arithmetic, source trust,
   uniqueness, warehouse, currency, limits, and every line identity.
8. Lines resolve to existing Items, settings-controlled distinct Items, or an exception.
9. The effective action policy selects `needs_attention`, `create_draft`, or `register_ordered`.
10. Finalization calls existing Storage actions in one transaction with a configured automation
    actor and stable idempotency identity.
11. The import links to the resulting Purchase Order and becomes immutable audit history.
12. Physical receipt remains a separate human-controlled workflow.

### 5. Settings And Effective Policy

The most restrictive applicable rule wins:

1. Non-configurable security and data-integrity floor.
2. Integration installation/provider/model/agent/workload maximum.
3. Global Storage Purchase Order email-automation policy.
4. Supplier/document profile policy.
5. Ordered automation rules, which may narrow but never widen higher layers.

Global settings include:

- Enabled state and runtime mode.
- Default import outcome and configured least-privilege automation actor.
- AI mode, named workload, retries, timeout, token/cost limits, and provider outage behavior.
- Default hard limits, tolerances, confidence thresholds, and high-risk consensus policy.
- Retry/backoff, retention, circuit breaker, exception notification, and optional daily digest.
- Whether normal success is silent.

Supplier/profile settings include:

- Trusted source scope and authentication requirements.
- Extraction version and defaults.
- Supplier bootstrap behavior: existing only, create a review candidate, or create an active
  supplier through a Documentation action when every configured gate passes.
- Item resolution order and behavior for zero or several matches.
- New-Item behavior: review only, create a distinct review-flagged supplier Item, or create a
  distinct active/orderable Item with profile defaults.
- Outcome and thresholds for deterministic and AI-assisted results.
- AI profile-repair mode: off, propose, or auto-activate after fixture and shadow validation.
- Changed-resend handling and commercial limits.

Runtime modes are:

- `off`: no import processing.
- `shadow`: parse, validate, and record the decision without writing a Purchase Order or Item.
- `review`: create an import proposal and require manual finalization.
- `auto_deterministic`: auto-register only when deterministic extraction and all gates pass.
- `auto_verified_ai`: allow AI extraction and AI-created profile versions, then auto-register only
  after Nexum verification and all gates pass.

The Admin UI provides these modes as understandable presets plus an advanced condition builder.
Supported decision facts include source trust, profile health/version, extraction method, AI usage,
per-field confidence, all-lines-resolved, new-Item count, amount, variance, currency, line count,
duplicate/revision state, and validation reason codes.

### 6. Confidence And Hard Gates

The model's self-reported percentage is never sufficient to authorize a write. Nexum stores and
evaluates separate dimensions:

- Source trust.
- Document identity.
- Extraction evidence for every critical header and line field.
- Item identity and ambiguity.
- Deterministic validation.
- AI result validity when AI was used.

The weakest critical dimension controls the outcome; dimensions are not averaged into false
certainty. Thresholds are action-specific and versioned. Initial thresholds must be calibrated in
shadow mode with real protected fixtures, not invented from one example.

No setting or confidence score may bypass these hard gates:

- Configured mailbox/recipient and trusted source policy.
- Authenticated and aligned sender when automatic processing requires it.
- One active supplier or an explicitly permitted supplier-bootstrap action.
- Unique supplier plus external order number and stable source fingerprint.
- Required fields and evidence present in the source.
- Quantities, line totals, charges, and totals arithmetically reconciled within explicit tolerance.
- Known allowed currency and destination warehouse.
- Line, quantity, amount, and newly created master-data limits.
- Every line resolved unambiguously or safely created under policy.
- Valid active automation actor with `storage.purchase_manage`.
- Idempotent finalization and no conflicting shipment, cancellation, or receipt history.
- No receipt or stock mutation from the email workflow.

### 7. AI Extraction, Learning, And Repair

Create a dedicated **Purchase Order Import Agent** scoped to Storage. It uses Integration's approved
provider/model/workload path and has `can_execute_actions=false`. It receives only minimized content
needed for the task and returns strict schema data, explicit `unknown` values, and source evidence.
It has no browser, URL fetch, filesystem, Email-send, Signal-rule, Item-write, or Purchase Order
tool.

AI may:

- Classify an intended supplier-order document within the already selected workflow.
- Extract a one-import canonical result.
- Suggest existing Item candidates without merging by name alone.
- Propose a new declarative profile or immutable profile version.
- Diagnose why an import/profile failed.
- Propose corrected field mappings, aliases, normalizers, and validators.

AI output always re-enters Storage's evidence checks, validators, Item resolver, and action policy.
An AI profile repair never directly creates or modifies a Purchase Order.

The **Repair with AI** action on an import or Purchase Order:

1. Compares the source snapshot, normalized import, decision trace, current PO, and active profile.
2. Identifies discrepancies with source evidence.
3. Produces a corrected import result and a separate draft profile-version candidate when needed.
4. Replays the candidate against protected golden fixtures and the current source.
5. Shadows it against the configured number of later samples.
6. Activates it automatically only when effective settings permit and every regression gate passes.
7. Keeps the previous active version available for one-click rollback.
8. Runs the corrected import through normal lifecycle guards before any allowed PO edit.

When a Purchase Order already has immutable shipment, cancellation, or receipt history, AI repair is
preview/proposal only for locked facts. It cannot rewrite history or bypass reversal/correction
workflows.

Without an AI provider, admins can create, edit, clone, test, activate, retire, export, and import
profiles manually. Template drift becomes an exception instead of a failed provider call. AI
controls are hidden or honestly disabled when no effective provider/workload policy exists.

### 8. Supplier And Item Identity

Supplier identity uses configured sender/authentication facts, source document evidence, and the
Documentation Vendor register. Display names alone never auto-merge suppliers.

Item resolution order is profile-configurable but conservative:

1. Exactly one valid `(supplier, supplier SKU)` mapping.
2. Other explicitly allowed unique identifiers such as GTIN/MPN plus manufacturer when the source
   and current schema can prove uniqueness.
3. A previously confirmed manual mapping.
4. A distinct new supplier-imported Item when policy permits.
5. Otherwise an exception.

Zero matches do not cause a name-only merge. Several matches are ambiguous and never select an Item
arbitrarily. The current supplier-SKU data must be audited before adding any stronger uniqueness
constraint.

A settings-controlled new Item uses explicit profile defaults for warehouse, currency, status,
orderability, tax, serial/batch/expiry behavior, asset behavior, warranty, and other required
fields. Unknown critical tracking facts use documented conservative defaults or block auto-create.
Provenance and catalog-review state remain visible without forcing per-order approval. Saving a
resolution also saves the supplier-SKU mapping so the next import is deterministic.

### 9. Import State, Idempotency, And Revisions

Use a compact status plus current stage.

Import status:

- `pending`.
- `processing`.
- `retry_scheduled`.
- `needs_attention`.
- `imported`.
- `duplicate`.
- `rejected`.
- `failed`.
- `cancelled`.

Stage:

- `detect`.
- `deterministic_extract`.
- `ai_extract`.
- `item_resolution`.
- `validate`.
- `policy`.
- `finalize`.

Every transition records a reason code, attempt, actor/service identity, and time. Each import pins
its source fingerprint, Signal action key, profile version, policy revision, parser version, and AI
execution UUID when applicable.

The domain idempotency boundary is supplier plus external order number, with source/action identity
as supporting uniqueness. Same order number and same source fingerprint is a duplicate no-op. Same
order number with changed content is a revision/conflict against the existing import and PO, never a
second PO. Automatic reconciliation may occur only when policy explicitly allows it and no immutable
shipment, cancellation, or receipt history exists.

Finalization locks the import and relevant domain rows. A retry after a worker crash returns the
existing PO instead of creating another. Signal retry, queue retry, and manual retry share the same
idempotency identity.

#### Approved 2026-08-07 Clarification: Manual And Email Orders Share One Identity

The Purchase Orders page is the canonical order list for both manually registered and email-created
orders. Source is provenance, not a separate kind of Purchase Order: the list distinguishes manual,
email-created, and manual-then-supplier-confirmed records with accessible badges. **Supplier Order
Imports** remains the audit, retry, and exception queue; it is not a second ordinary order list.

The exact normalized domain identity is `(supplier/vendor_id, supplier external order
number/vendor_ref)`. Normalization removes surrounding ASCII spaces and applies the active
database engine's case conversion while preserving leading zeros, punctuation, tabs/newlines, and
internal spacing. The same external number at a different supplier is a different identity. A blank
supplier order number has no automatic identity
and must not be matched from names, addresses, totals, or similar-looking content.

When an active manually registered PO already owns that identity, a trusted supplier confirmation
must compare its material facts with the existing PO, including explicit line totals and available
goods, freight, discount, other-charge, tax, and header totals. If they agree, finalization links the
immutable import/source evidence to the existing PO and records supplier confirmation. It preserves the
internal Nexum PO number, creator, lines, dates, warehouse, currency, status and lifecycle, shipment
and receipt history, and every inventory balance. Confirmation is not permission to overwrite the
manual record or receive goods.

Material disagreement, or an identity owned by a deleted or cancelled PO, enters
`needs_attention` with bounded candidate/conflict context. It never creates a second PO, silently
changes the candidate, or resurrects history. Existing shipment, cancellation, and receipt guards
continue to constrain manual and AI-assisted resolution.

The boundary is symmetric. Manual create and update must reject an identity already owned by an
email-created, manual, or soft-deleted PO. A nullable database-generated normalized key plus a
composite supplier/key unique index enforces the invariant after collision preflight. It recomputes
for raw inserts and updates and reserves soft-deleted history. Application hashing delegates
normalization to the active database so import idempotency and the PO guard do not disagree.
Ordinary edits cannot change the identity after vendor confirmation; the governed pre-history AI
repair may correct it only while holding and updating both the PO and import atomically.

### 10. Source Email And Automation User Interface

Add a searchable, filterable, sortable Storage **Supplier Order Imports** queue. Normal successes may
remain quiet; exceptions, retries, profile health, and optional digest results stay discoverable.
The queue shows supplier/source, external order, stage, status, profile, method, amount, blocking
reason, attempt age, and resulting PO.

The import detail page shows:

- Source and trusted-authentication facts.
- Canonical header and line extraction beside evidence.
- Totals, charges, delivery snapshot, and reconciliation.
- Item matches, new-Item provenance, ambiguity, and available resolution actions.
- Profile/policy versions, deterministic/AI attempts, retry state, and reason codes.
- Preview, retry, manual resolve, profile test, and permitted finalization actions.

An automatically created PO contains a compact **Source Email & Automation** card with:

- Sanitized snapshot of sender, recipients, subject, received time, HTML/text body, and attachment
  descriptors captured at import time.
- A link to the original Inbox message only when it still exists and the user has Email access.
- Supplier external order number, source totals, delivery facts, import result/time, profile/version,
  extraction method, effective decision, and blocking/warning reasons.
- Links to the import trace and allowed manual or AI repair actions.

The snapshot is immutable, permission-protected, and retention-controlled. It does not load remote
images or links and does not copy raw EML, unrestricted headers, credentials, prompts, or raw model
responses into Storage. If Email retention removes the original message, the safe source snapshot
and fingerprint remain available for audit.

Manual edits use existing PO lifecycle guards and write actor/history. Source evidence is never
edited. AI repair and manual editing produce visible revision/audit facts.

Admin settings live under the Storage/Inventory settings area and include compact Bootstrap pages
for General Policy, Supplier Profiles, Profile Versions/Fixtures, AI/Confidence, and Import Health.
Profiles can be created and edited without AI. The UI shows the effective inherited policy and why
a broader option is blocked by a higher layer.

### 11. Permissions And Automation Actor

Proposed permissions are:

- `storage.purchase_import_view`.
- `storage.purchase_import_resolve`.
- `storage.purchase_import_execute`.
- `storage.purchase_import_profile_manage`.
- `storage.purchase_import_policy_manage`.

Final PO creation still requires `storage.purchase_manage`. Opening the original Inbox message still
requires the appropriate Email permission. Integration provider/governance/usage access remains
separate. `signal.rule.manage` never grants Storage write access.

Automated finalization uses one explicitly configured active technical User with least privilege.
The actor must have the required Storage permission and be allowed by the effective policy. Nexum
must never choose the first administrator, last editor, or Signal-rule creator implicitly. A missing
or disabled actor fails closed and creates an operational exception.

### 12. Security And Privacy

- Visible `From` is not trusted by itself. SPF/DKIM/DMARC/alignment facts are derived only from
  `Authentication-Results` written by configured trusted receiving hops/authserv IDs.
- Both Email routing and Storage validation require the configured mailbox/recipient and source
  profile. Storage repeats hard trust checks even if an Email or Signal rule matched.
- Email HTML, text, attachments, and profile instructions are untrusted data. Prompt injection in a
  product description or footer is not an instruction to Nexum or the AI agent.
- Normalization and parsing make no outbound requests. Tracking and click URLs are stripped from AI
  context and never fetched.
- External AI receives only the minimized document content and local trust facts required by the
  approved workload data profile.
- Every external call must pass Integration's installation, provider/model, agent/workload, privacy,
  expiry, and governance gates. Failure is fail-closed; Nexum never sends raw data as a fallback.
- Strict output schema, evidence anchors, allowlists, size/time limits, and deterministic
  post-validation are mandatory.
- Mandatory AI usage/access audit remains sanitized metadata. It does not retain raw email, prompt,
  answer, credentials, headers, provider errors, or unrestricted content.
- Authentication, authorization, secret filtering, server caps, idempotency, immutable audit, and
  the no-receipt rule cannot be disabled by settings.

### 13. Exceptions, Recovery, And Operations

Transient queue/provider failures retry with bounded exponential backoff. Permanent security,
identity, schema, arithmetic, or policy failures do not spin; they enter `needs_attention` or
`rejected` with a clear reason.

A supplier profile circuit breaker trips after a configured number of consecutive template-drift,
validation, or unsafe-result failures. It pauses only that profile, keeps other suppliers running,
and exposes a health warning. AI may prepare a repair candidate while the active profile remains
pinned and reversible.

Manual exception actions include map Item, create distinct Item, correct an allowed extracted field,
reject source, test/activate/rollback profile, retry, and finalize when safe. A correction becomes a
fixture or deterministic mapping so the same case should not ask again.

Successful imports are silent by default. Admins may enable a daily summary. Immediate alerts focus
on stale backlog, circuit breakers, repeated failures, AI-policy denial, disabled automation actor,
and queue health.

Active inbound automation depends on a persistent queue worker. Scheduled digests, retention, and
health checks depend on the scheduler. These operational prerequisites must be proven on Dev and in
the deployment runbook before active mode is declared ready.

### 14. Deferred Inbox AI Bootstrap

A later approved RFC may add **Analyze with AI** on an Inbox message. That workflow may determine
whether the message should enter Signal and propose or create an Email rule, Signal rule, supplier,
and import profile. It must reuse the profile engine, governance, previews, permissions,
idempotency, and hard gates from this RFC. It is deliberately deferred so an arbitrary-message
button cannot create broad active automation before the underlying contracts are safe.

## Impact Analysis

### Modules

- **Storage:** primary owner of policies, profiles, imports, fixtures, extraction schema, Item
  resolution, jobs, exceptions, UI, source card, and final Purchase Order action.
- **Email:** trusted source facts, explicit supplier-order handoff, source access, archive/tag state,
  and default-ticket suppression for matched messages.
- **Signal:** normalized event vocabulary, Storage action type, stable idempotency key, orchestration,
  execution audit, and retry.
- **Integration:** Purchase Order Import Agent/workload, provider execution, governance, privacy
  gate, usage/cost telemetry, and provider health.
- **Documentation:** canonical Vendor/Supplier identity and any guarded supplier-bootstrap action.
- **User Management/System:** permissions, roles, and configured automation actor.
- **Knowledge:** Storage, Email, Signal, Integration, and operations documentation and BookStack sync.
- **Notification:** optional exception/digest delivery only in the hardening slice.

No new domain module is introduced. Routes, controllers, views, actions, models, and tests remain in
their owning modules under `app/Modules/{Domain}`.

### Risks And Side Effects

- A spoofed email could create a false order.
- Template drift or model hallucination could corrupt quantities or prices.
- Duplicate/resend handling could create multiple POs.
- Wrong Item merging could contaminate stock and later asset/serial workflows.
- Automatic separate Item creation can produce catalog duplicates that need later consolidation.
- Freight, discount, or delivery facts could be silently lost if not kept in the import/PO source
  presentation.
- AI data egress can expose personal delivery data and confidential purchase information.
- A bad auto-activated profile version could affect later imports.
- Queue downtime can leave messages pending or create delayed duplicates without strong locks.
- A disabled service actor can halt finalization.

The proposed trust gates, immutable versions, fixture/shadow replay, rollback, circuit breaker,
structured evidence, idempotency, and Storage action boundary mitigate these risks.

### Operational Dependencies

- Persistent queue worker and scheduler verification.
- Approved and active AI installation/provider/model/agent/workload governance before external AI.
- Integration-owned execution and telemetry boundary for every AI attempt.
- At least three to five protected real fixtures per first supplier, including multiple lines,
  quantity above one, freight/discount variation, missing optional references, and a template
  variant.
- An active least-privilege automation actor.

## Data And Migration Plan

Use relational Storage-owned records rather than one `common_settings` JSON row because profiles,
versions, fixtures, actors, health, imports, and audit require constraints and independent retention.
Exact names may be refined in Slice 1 after a final authoritative-schema inspection, without
changing ownership or safety rules.

Proposed records:

- `storage_purchase_order_automation_policies` and immutable policy revisions.
- `storage_purchase_order_import_profiles`.
- `storage_purchase_order_import_profile_versions`.
- `storage_purchase_order_import_profile_fixtures`.
- `storage_purchase_order_imports`.
- `storage_purchase_order_import_lines`.
- `storage_purchase_order_import_attempts` or events.

Import records retain Email/Signal/action identity, source fingerprint and safe snapshot, supplier,
profile/policy versions, external order, status/stage/reason, confidence dimensions, normalized
commercial/delivery snapshot, AI execution UUID, retry timing, actor, and resulting PO. Attempt
records are append-only start/completion events and retain sanitized input/output fingerprints plus
already minimized operational metadata, never raw prompts or model responses. Retention does not
rewrite or delete attempt history, and the parent import cannot cascade-delete it. Current-policy,
active-profile-version, profile-health, and queue-claim transitions use database constraints,
row locks, and compare-and-set updates appropriate to their ownership boundary.
A duplicate queue delivery cannot complete a claim whose first worker has already advanced it to
running.

The implementation may add a first-class Storage charge/source-total representation or keep an
immutable normalized commercial snapshot linked to the PO until broader commercial semantics are
approved. In either case, freight, discount, fees, delivery method/address, and source totals must
remain visible and must not be silently discarded or allocated into inventory cost without a
separate rule.

Add constraints/indexes for profile/version identity, source/action identity, one PO per import,
supplier/external-order conflicts, status/next attempt, profile health, and supplier lookup. Do not
add a destructive unique supplier-SKU constraint until existing collisions and warehouse semantics
have been audited.

Add a nullable database-generated normalized supplier-order key to `storage_purchase_orders` and
a composite unique index with `vendor_id`. Before adding the index, preflight every active and
soft-deleted PO with a nonblank supplier order number using the exact database expression and binary
comparison. Abort before schema mutation and report bounded identifiers when two rows normalize to
the same `(supplier, supplier order number)` identity. `NULL` continues to allow orders whose
supplier has not assigned an order number yet. Soft-deleted identities remain reserved, and raw SQL
cannot omit, forge, or leave the generated identity stale.

The supplier and supplier order number remain the readable source of truth. The implementation must
translate a database race into the same actionable domain/validation result as an application-level
collision and use a current locking read when recovering beyond an older REPEATABLE READ snapshot.
The compatibility migration is intentionally additive/forward-only because it cannot safely infer
whether an earlier fresh-install migration owns the same invariant. The obsolete application-written
hash remains unindexed until an explicit later cleanup after the human-review/rollback window.

Migration defaults are fail-safe:

- Global automation is `off` or `shadow`.
- No profile is active merely because a migration ran.
- External AI remains denied until existing Integration governance is explicitly complete.
- Existing POs, Items, Email messages, Signals, and rules are unchanged.
- Historical emails are not imported or sent to AI automatically.
- No migration calls an external provider.

Deployment order for implementation slices:

1. Deploy schema and disabled foundation.
2. Run migrations and permission/role seeders.
3. Clear caches and restart the queue worker after queue-bearing slices.
4. Verify scheduler, failed jobs, policy denial, and import health.
5. Install protected fixtures and run deterministic/profile tests.
6. Run one supplier in shadow mode.
7. Enable active deterministic or AI mode explicitly.

Rollback disables global automation and profiles before stopping jobs. Existing import, profile,
attempt, audit, Item, and PO history remains. A rollback never deletes a created PO. Schema rollback
is safe only before production reliance; otherwise use a forward fix.
The integrity migration refuses schema rollback before removing any guard once append-only
start/completion events can no longer satisfy the legacy uniqueness constraint.

## Feature Slices

Implement in this order:

1. `docs/feature-slices/2026-08-04-storage-supplier-order-import-foundation.md`.
2. `docs/feature-slices/2026-08-04-storage-supplier-order-profile-engine.md`.
3. `docs/feature-slices/2026-08-04-email-signal-supplier-order-handoff.md`.
4. `docs/feature-slices/2026-08-04-storage-supplier-item-mapping-and-auto-creation.md`.
5. `docs/feature-slices/2026-08-04-storage-supplier-order-auto-registration.md`.
6. `docs/feature-slices/2026-08-04-storage-ai-assisted-supplier-order-resolution.md`.
7. `docs/feature-slices/2026-08-04-storage-ai-profile-repair-and-learning.md`.
8. `docs/feature-slices/2026-08-04-storage-supplier-order-import-hardening.md`.
9. `docs/feature-slices/2026-08-07-storage-manual-email-supplier-order-identity-reconciliation.md`.

Each slice must be independently testable and documented. A later slice may not weaken a previous
slice's permission, privacy, idempotency, or no-receipt guarantees.

## Testing Plan

### Unit And Contract Tests

- Canonical schema, locale/date/decimal parsing, safe selectors/pattern limits, SKU normalization,
  evidence anchors, arithmetic/charges, confidence dimensions, and policy inheritance.
- Profile version lifecycle, checksums, fixture replay, shadow comparison, activation, rollback,
  degradation, and circuit breaker.
- Import state transitions, duplicate/revision decisions, retry classification, and idempotency.
- AI strict-schema validation, `unknown` handling, hallucinated/missing evidence rejection, and
  provider-independent result normalization.
- No-AI behavior and higher-layer policy denial.

### Feature And Integration Tests

- One Email rule creates one Signal and one Storage import; retry creates no duplicate.
- Matching supplier-order mail stops default ticket ingress only when configured.
- Itegra-style HTML/text fixtures cover one/many lines, quantity above one, Unicode, freight,
  discount, missing optional fields, and template variation.
- Exact supplier-SKU match, zero match, several matches, warehouse mismatch, manual mapping,
  distinct Item creation, and saved mapping.
- Shadow, review, deterministic auto, and verified-AI auto outcomes.
- Every hard gate blocks despite a high model confidence value.
- Provider/governance denial, invalid JSON, prompt injection, timeout, retry, consensus, and no raw
  fallback.
- AI creates a draft profile repair, replays fixtures, shadows, activates only under policy, and can
  roll back after a regression.
- Supplier-order identity normalization preserves leading zeros, punctuation, tabs/newlines, and
  internal spacing, ignores only surrounding ASCII spaces/database case, permits the same number at
  another supplier, and leaves blank supplier numbers unmatched.
- Manual-first plus a trusted, materially matching confirmation reuses one PO and attaches source
  provenance without changing its internal number, lines, lifecycle, shipments, receipts, or stock.
- A material mismatch or deleted/cancelled matching PO enters `needs_attention` with no second PO,
  overwrite, or resurrection.
- Email-first then manual create, manual update onto an occupied identity, a soft-deleted collision,
  and concurrent create/finalize attempts are rejected or reconciled to at most one PO.
- Migration collision preflight, nullable generated identities, active and soft-deleted composite
  uniqueness, raw insert/update enforcement, and current locking race recovery are verified against
  the Dev MariaDB database with two independent connections.
- The canonical Purchase Orders list renders accessible manual, email-created, and
  manual-then-supplier-confirmed provenance while Supplier Order Imports remains the audit/exception
  queue.
- Duplicate Email UID, resent message, same order/same hash, changed resend, concurrent jobs, Signal
  retry, and crash after PO creation.
- Source card rendering, safe HTML, remote-resource suppression, Inbox deep-link permission, Email
  retention fallback, and raw-data absence from audit/telemetry.
- Manual/AI correction respects shipment, cancellation, and receipt locks.
- No order-confirmation path creates a receipt, Stock Unit, Movement, or on-hand change.
- Admin and technician permissions do not widen each other across Email, Signal, Storage, and
  Integration.

### Security And Operations Tests

- Forged `From`, untrusted `Authentication-Results`, configured authserv identity, SPF/DKIM/DMARC
  alignment, malicious HTML, oversized body, unsupported attachment, and prompt injection.
- Server-side amount/line/quantity/new-master-data caps and service-actor disablement.
- Queue retry/backoff, failed job, scheduler/digest, circuit breaker, profile isolation, retention,
  and provider outage.
- Fresh migration, upgrade, rollback preflight, supplier-SKU collision report, indexes, seeds,
  permissions, and cache/worker deployment commands.

Run focused Email, Signal, Storage, Integration, Documentation, Notification, User/permission, queue,
and receiving suites on Dev. Run the broad Laravel suite before release handoff when practical.
Automated tests never complete the required human review.

## Documentation Plan

- Storage Knowledge: import modes, profiles, exception queue, Item mapping/creation, source card,
  manual repair, AI repair, and the no-stock-impact boundary.
- Email Knowledge: trusted sender facts, explicit supplier-order rules, source retention, and why
  ordinary mail remains ticket-first.
- Signal Knowledge: normalized supplier-order event, action, idempotency, retry, and ownership.
- Integration Knowledge: Purchase Order Import Agent/workload, processing modes, governance,
  privacy, usage/cost telemetry, and provider outage behavior.
- Documentation Knowledge: automatic supplier bootstrap behavior if implemented.
- Admin/operations runbook: fixture protection, shadow rollout, activation, rollback, circuit
  breaker, queue/scheduler, retention, disabled actor, and provider outage.
- Permission/API catalogs only for implemented surfaces.
- `docs/TODO.md`, Feature Slices, ADR, and `docs/human-review.md`.
- BookStack sync for every materially updated Knowledge source after implementation.

## Open Questions

No product decision blocks the approved direction. The accepted defaults are:

- Auto-register means recording an externally placed order as `ordered`, never placing it.
- Profiles are configuration and immutable versions, not supplier-specific PHP.
- AI is optional and non-writing, but may create and automatically activate profile versions after
  configured fixture/shadow validation.
- Deterministic/manual profile management remains complete without AI.
- Name similarity alone never merges Items; settings may create a distinct supplier Item instead.
- Every AI or deterministic result must pass the same hard Storage gates.
- The source email remains visible as an immutable sanitized card and original Inbox link when
  permitted.
- Repair with AI may correct safe data and improve the profile, but cannot rewrite immutable
  shipment/receipt history.
- The future generic Inbox AI bootstrap button is recorded but deferred to a separate RFC.

Exact first-supplier thresholds, fixture corpus, safe defaults for automatically created Items,
commercial charge representation, and retention periods may be refined in their Feature Slices as
settings, provided they do not weaken the security floor or change the accepted target behavior.

## Approval

Approved by Svein Tore on 2026-08-04 in the Codex task after the settings-based deterministic/AI
architecture, automatic profile creation and repair, source-email card, manual/no-AI administration,
and deferred Inbox AI bootstrap were discussed. Approval authorizes staged implementation through
the Feature Slices in this RFC. This documentation task does not itself implement runtime behavior.
