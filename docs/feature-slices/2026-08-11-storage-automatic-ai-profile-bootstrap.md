# Feature Slice: Automatic AI Supplier Profile Bootstrap

Status: Ready
Date: 2026-08-11
Parent: `docs/rfc/2026-08-11-automatic-ai-supplier-profile-bootstrap.md`
Owner: Svein / Codex

## Goal

Make a trusted supplier confirmation with no existing profile complete without routine human
approval, while teaching Nexum a reusable deterministic profile for later messages.

## User-Visible Behavior

With automatic profile-or-AI handling, active Supplier creation, and active Item creation selected,
Nexum uses AI when no profile can finish the order. A valid first message creates the Supplier,
profile, Items, and ordered Purchase Order automatically. Later matching messages can use the
learned deterministic profile. Genuine failures remain in the exception queue.

## Scope

- Enable automatic profile learning in the simplified server-owned preset when AI is enabled.
- Create the Supplier before the profile so the learned profile owns the canonical Supplier link.
- Create one protected machine-verified bootstrap fixture for a new profile only.
- Validate and activate the first candidate under the configured one-sample bootstrap gate.
- Permit verified-AI finalization only when the new candidate profile activated successfully.
- Serialize bootstrap on the Supplier, re-run matching under that lock, and make retry/stale-import
  reuse idempotent without rewriting pinned policy evidence.
- Correct the active Dev policy mode and new-Item cap.
- Add a combined regression for Supplier, profile, Item, Purchase Order, and no-stock behavior.
- Update UI copy, Knowledge, TODO, and human review.

## Out Of Scope

Direct model writes, automatic Receiving, supplier-order transmission, relaxed sender trust,
removing business limits, rewriting pinned imports, or production deployment.

## Data Touched

Existing Storage policy/revisions, supplier-order imports, profiles, profile versions, fixtures,
Items, supplier mappings, and Purchase Orders. Documentation Suppliers are created through the
existing guarded action. Receipt, Movement, Stock Unit, and on-hand tables are not written by this
workflow.

## Permissions

The protected system actor retains only the existing direct permissions for Supplier, profile,
Item, and Purchase Order actions. No human user, role, route, API ability, or model capability is
added.

## Tests

- Ordinary policy save conditionally selects automatic learning and ignores forged technical input.
- Profileless trusted AI import activates a Supplier-linked profile and creates an active Item and
  ordered Purchase Order.
- The protected `ai_verified_bootstrap` fixture replays, and the new profile matches the same trusted
  source for later deterministic extraction.
- A stale profileless import with an overlapping route scope and a retry after bootstrap both reuse
  the same Supplier/profile/version/Item identities without changing the pinned policy snapshot.
- Untrusted, invalid-evidence, failed-candidate, duplicate, and limit boundaries remain fail-closed.
- The ordinary request normalizes contradictory AI/runtime choices, enables one-sample learning only
  with AI, and rejects active Item creation with a zero new-Item cap.
- No receipt or inventory side effect occurs before explicit Receiving.
- Focused and complete Storage suites pass on Dev.

## Documentation

Approved RFC, Storage Knowledge, TODO, `docs/human-review.md`, and the existing unpublished public
handoff entry.

## Done Criteria

- [x] Missing profile is created, validated, Supplier-linked, activated, and protected by an
  `ai_verified_bootstrap` fixture without human approval.
- [x] Its first trusted AI import creates exactly one active Supplier, distinct active/orderable
  Items and mappings within limits, and one editable ordered Purchase Order.
- [x] The learned immutable active version safely matches later source and runs deterministically;
  deterministic automatic imports without an active match still stop.
- [x] Retry and stale/concurrent first-message regressions reuse the same Supplier, profile, version,
  Item, and Purchase Order identities without a priority tie or pinned-policy rewrite.
- [x] Untrusted source, bad evidence/candidate, duplicate conflict, provider failure, and business
  limits fail closed with no partial Item or Purchase Order write where bootstrap is incomplete.
- [x] No receipt, Movement, Stock Unit, or on-hand quantity is created by email processing.
- [x] AI-enabled ordinary saves use server-owned one-sample auto-activation; AI-off saves disable it;
  forged technical fields and contradictory automatic combinations are normalized or rejected.
- [x] Focused and full Storage tests, formatting, Blade compilation, Knowledge sync, and a controlled
  provider bootstrap smoke pass on Dev; no migration or frontend build is required.
- [x] The corrected Dev policy uses automatic profile-or-AI handling, fallback, active Supplier and
  Item creation, and a non-zero new-Item cap for future imports only.
- [ ] Named desktop/narrow-width and controlled real-email checks remain tracked in
  `HR-2026-08-10-003`; the slice is not human-reviewed until Svein Tore confirms them.

Verification: focused Integration/Storage tests pass 94 / 971; the complete Storage suite passes
257 / 2,775 with the existing opt-in MariaDB contract skipped. Pint passes after three automatic
style corrections. A rolled-back real-provider bootstrap completed in about 21 seconds and proved
active Supplier/profile/Item, ordered Purchase Order, and zero inventory side effects. Dev policy
revision 11 is ready; no migration, build, persistent worker restart, or old-import rewrite occurred.
