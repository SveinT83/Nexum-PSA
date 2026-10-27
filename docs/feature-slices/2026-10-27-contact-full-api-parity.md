# Feature Slices: Full Contact API Parity

Status: Done On Dev / scoped Main candidate verified. Date: 2026-10-27. Owner: Codex.
Parent: ../rfc/2026-10-27-contact-full-api-parity.md (Approved by Svein Tore).
Human review: HR-2026-10-27-CONTACT-API Pending; tested Main merge explicitly authorized.

## Goal and User-Visible Behavior
Complete all supported Contact browser workflows through the typed official API.
Keep canonical identity, actual native rights, stable bridge IDs and explicit delivery.

## Sequential Scope
1. Done: native authority, module-owned registration, baseline contracts.
2. Done: complete reads, discovery, bounded lookups, stats and related history.
3. Done: canonical create/edit/reuse and supported legacy addresses.
4. Done: #254 predefined role selector and guarded API invitation create.
5. Done: settings, repair, preview/stale/replay/encrypted receipts.
6. Done: exact Swagger/catalog coverage, regression, handbook and scoped Main promotion.

## Data Touched
Existing Contact/Client/Site/bridge/portal/settings/audit tables and additive Contact
command/serialization tables. No destructive migration or real customer repair/send.

## Permissions
Explicit token abilities and current native Contact/portal rights; no role grants.

## Tests
Per-row contract, native/scope denial, preservation, idempotency, queue/rollback and
cross-module tests; native database checks and trusted HTTPS smoke. Exact evidence
will be recorded in ../plans/2026-10-27-contact-api-verification.md.

## Documentation
Contact README/Knowledge, CustomerPortal guide, Integration API docs, exact matrix,
TODO and human review; supported BookStack publication with actual read-back.

## Out Of Scope
New merge/delete lifecycle, connector grant expansion, provider activation or production rollout.

## Done Criteria
Every matrix row is verified against runtime/Swagger/tests and exact read-back;
handbook is read back or concrete owner/blocker recorded. No unfinished code is hidden
by a numerical coverage claim. Main result and human-review status are reported separately.
