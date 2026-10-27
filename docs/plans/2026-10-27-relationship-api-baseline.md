# Relationship API Baseline

Date: 2026-10-27. Owner: Codex.
Runtime: /var/Projects/tdPSA, branch Dev.
HEAD: 36918edbcaae1ce7b1208ba34f84a3a1719f0e98

## Inventory and current work
- Runtime route:list --json confirms 10 Relationship browser rows and 5 signed ingress
  operations; 5 Ticket-owned Relationship operations are reused.
- Existing Relationship files have one modified contributor action:
  Actions/EscalateTicketToRelationship.php (shared validateTarget extraction).
- Existing API catalog has relationships.read and relationships.sync labels without
  general staff management routes. Existing machine ingress is separate from Sanctum.
- Parent July RFC is Approved and its ownership ADR Accepted. No existing full staff
  management API RFC was found.
- TODO currently has Knowledge API implementation in progress; preserve that owner's
  work and coordinate overlapping Knowledge/Integration/Swagger files after approval.
  Signal and UserManagement parity drafts are awaiting approval independently.
- GitHub search repo:SveinT83/Nexum-PSA is:open relationship returned #265 and #275.
  Both concern Knowledge/Documentation foundations, not a Relationship parity Issue.
  No Issue or outbound comment was created.
- Source hashes and exact routes are in the companion coverage JSON. These baseline
  hashes are evidence for preserving contributor code during planning.

## Existing verification
Command on Dev: HOME=/tmp php artisan test
app/Modules/Relationship/Tests/Feature/RelationshipModuleTest.php
Result: 10 passed / 37 assertions, zero failures or skips; duration 36.45s.
This verifies existing behavior, not proposed endpoints.
Command on Dev: HOME=/tmp php artisan test
app/Modules/Ticket/Tests/Feature/TicketRelationshipApiTest.php
Result: 5 passed / 40 assertions, zero failures or skips; duration 33.96s.
Combined existing baseline: 15 tests / 77 assertions, zero failures or skips.
No proposed new endpoint was implemented or tested.
Scoped git diff --check passes for the updated TODO; planning artifacts are UTF-8.

## Planning boundary
Only RFC, sequential slices, coverage/baseline and TODO planning are changed.
No API code, migration, credentials, grants, provider sends, handbook publication,
Main, commit/push or production action is part of this planning result.
Next action: Svein Tore approves the concrete RFC; then implement sequentially on Dev.
