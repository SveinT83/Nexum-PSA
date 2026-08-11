# RFC: Automatic AI Supplier Profile Bootstrap

Status: Approved
Date: 2026-08-11
Owner: Svein / Codex

## Context

Supplier Order Automation is intended to be exception-driven: a trusted, valid supplier
confirmation should not need routine human approval. The simplified Storage page already lets an
administrator choose active Supplier and Item creation plus AI fallback, but the complete unknown
supplier path is still blocked in four places:

- the current Dev policy uses deterministic-only automatic registration;
- the current new-Item cap is zero;
- the policy evaluator requires a pre-existing active supplier profile in every automatic mode;
- the ordinary policy preset disables AI profile learning.

The existing governed AI response can already contain a declarative profile candidate. Nexum can
validate that candidate, reproduce the current canonical order from the immutable source, replay
fixtures, and activate an immutable profile version. The missing work is to connect those existing
capabilities into one unattended bootstrap path.

## Goals

- Let a trusted import with no matching profile use the selected Storage agent as AI fallback.
- Create an active Supplier through the existing Documentation action when policy permits it.
- Create and activate a reusable declarative supplier profile without human approval when the
  candidate reproduces the current source and passes all machine-verifiable gates.
- Create distinct active/orderable Items for unknown supplier SKUs when policy and business limits
  permit it.
- Register exactly one editable Purchase Order with status `ordered` after all gates pass.
- Keep normal successful processing unattended while retaining an exception queue for genuine
  failures.
- Preserve the rule that only Receiving changes on-hand inventory.

## Non-Goals

- Trust a visible From address, an unaligned sender, invented evidence, or an invalid document.
- Let the model write directly to Supplier, Item, profile, Purchase Order, receipt, or stock tables.
- Remove duplicate, identity, arithmetic, currency, warehouse, quantity, amount, or master-data
  limits.
- Place or transmit a purchase order to a supplier. Nexum records an order already placed outside
  Nexum.
- Guarantee that malformed, ambiguous, untrusted, duplicate-conflicting, or provider-failed input
  can never become an operational exception.

## Pre-Change Behavior

AI fallback can register an order when an active profile exists and its extraction cannot finish the
document. Supplier and Item bootstrap actions also exist. With no profile, however, the evaluator
returns `active_profile_required`. AI profile learning is forced off by the ordinary form, and a new
candidate has no human-protected fixture with which to pass the activation gate.

## Proposed Change

The ordinary server-owned preset enables `auto_activate` profile learning whenever AI is enabled
and uses one verified bootstrap sample. AI-off policies keep learning off.

For a profileless import, Nexum first verifies the AI document and creates the permitted active
Supplier. Profile learning then creates a Supplier-linked profile container and immutable candidate
version. The current immutable, trusted, evidence-validated source becomes an
`ai_verified_bootstrap` protected fixture only for this new-profile path. The candidate must
reproduce the canonical document, pass the constrained profile-definition validator, replay the
protected fixture, validate, and activate before the first order may be finalized.

The profile-candidate contract belongs to Storage rather than to the selected agent instructions.
Storage supplies the constrained declarative schema, replaces every source-match selector with
server-owned account, mailbox, recipient, sender, and authenticated-domain facts, and rejects a
missing, malformed, executable, commercially inconsistent, or non-reproducing candidate.

Bootstrap is serialized on the canonical Supplier. While that row is locked, Nexum re-runs the
real profile matcher and reuses a matching active profile for the same Supplier. An ambiguous match
or a match owned by another Supplier fails closed. Selector lists are canonicalized, and retries may
bind a newly activated AI candidate to an originally profileless pinned policy only after verifying
the active version, Supplier link, protected bootstrap fixture, and exact source match. The pinned
policy snapshot and checksum never change.

The policy evaluator continues to require the existing active profile for deterministic automatic
imports. A verified-AI import without an original profile may proceed only when its linked AI
candidate version and profile were activated successfully for the same Supplier. Candidate failure
therefore remains a clear exception and cannot silently weaken the gate.

Correct the Dev policy to `auto_verified_ai`, AI fallback, active Supplier/Item bootstrap, and a
non-zero new-Item limit aligned with the configured maximum order lines. Saving the policy creates
an immutable revision but does not process old imports or create business data by itself.

## Impact Analysis

- **Storage:** processing order, profile learning, policy evaluation, the ordinary policy preset,
  explanatory UI copy, tests, Knowledge, TODO, and human review change.
- **Documentation:** the existing guarded Supplier creation action is reused; ownership and
  permissions do not change.
- **Integration/User Management:** the existing managed tool-free Storage workload and protected
  system actor are reused unchanged.
- **Email/Signal:** existing explicit supplier-order routing remains the entry boundary.
- **Receiving/Inventory:** unchanged; order processing still creates no receipt, Movement, Stock
  Unit, or on-hand quantity.

Risks are master-data noise from a bad candidate, an overly broad profile match, and duplicate
creation during retry. Exact authenticated-domain scope, immutable source integrity, evidence
validation, constrained profile definitions, protected replay, supplier/SKU identity, existing
idempotency, and business caps remain mandatory.

## Data And Migration Plan

No schema migration or backfill is required. New profiles, versions, fixtures, Suppliers, Items, and
orders use existing tables and actions. Existing imports retain their pinned policy revision.
Rollback selects a previous policy revision or disables AI; an incorrectly learned profile can be
paused or retired without deleting audit history.

## Testing Plan

- Add one end-to-end regression for no profile -> verified AI -> active Supplier -> active profile
  and version -> active Item -> ordered Purchase Order.
- Assert the bootstrap fixture is protected and the new profile matches the same source later.
- Assert no receipt, Movement, Stock Unit, or on-hand quantity is created.
- Assert a stale profileless import and a post-bootstrap retry reuse one Supplier, profile, version,
  Item, and Purchase Order identity without changing the pinned policy snapshot.
- Assert invalid candidates stop before Item or Purchase Order writes and that ambiguous, untrusted,
  duplicate, provider-failed, and business-limit paths remain fail-closed.
- Run focused policy, Integration structured-execution, AI automation, import-pipeline, complete
  Storage, formatting, Blade, Knowledge-sync, and controlled provider verification on Dev.

## Implementation Verification

Implemented on Dev without a migration. Policy revision 11 uses automatic profile-or-AI handling,
AI fallback, one-sample profile activation, active Supplier and Item bootstrap, a 250-new-Item cap,
warehouse 2, and the selected Storage agent on standard `gpt-5.5`. Existing imports remain pinned to
their older revisions; the policy save created no import, Item, Purchase Order, receipt, Movement,
or Stock Unit.

A controlled real-provider transaction completed in about 21 seconds and was rolled back after it
proved valid AI extraction, a valid Storage-owned profile candidate, active Supplier/profile/Item,
one ordered Purchase Order, and zero receipt, Movement, Stock Unit, or inventory delta. The complete
Storage suite passes 257 tests / 2,775 assertions with the existing opt-in MariaDB contract skipped;
the focused Integration/Storage run passes 94 / 971.

## Documentation Plan

Update the Storage Supplier Order Automation Knowledge source and sync it, update the existing
Storage automation row in `docs/TODO.md`, extend human review `HR-2026-08-10-003`, and amend the
existing unpublished nexumpsa.eu website handoff. Keep all public wording Dev-only and unpublished
until the named human review is complete.

## Open Questions

None. Trusted, canonically valid first-time confirmations are intended to run unattended; genuine
trust, identity, candidate, duplicate, provider, and business-limit exceptions remain visible.

## Approval

Approved by Svein Tore on 2026-08-11 in this Codex task. The product decision is that a trusted,
valid supplier confirmation without an existing profile may automatically create its Supplier,
reusable profile, Items, and editable ordered Purchase Order. Routine approval is not required, and
Receiving remains the only operation that changes stock.
