## HR-2026-10-27-WARROOM-API - Full Warroom Browser/API Coverage

Status: Reviewed / Done On Dev, 2026-10-27.
Owner: Codex. Named human reviewer: Svein Tore.
RFC: rfc/2026-10-27-warroom-full-api-parity.md (Approved, "Ja godkjent").
Evidence: plans/2026-10-27-warroom-api-verification.md and linked JSON artifacts.

This is the human-review checklist for all four browser operations, embedded
My Day/navigation behavior and seven typed API operations. Scope includes shared
source authority, private Calendar/mailbox boundaries and global settings concurrency.
Acceptance: Svein Tore explicitly approved the completed Dev handoff and its
human-review checklist on 2026-10-27: "Godkjenner". This clears this checklist's
human-review gate for the verified Dev scope. The checked items record his scope
acceptance; the agent did not independently observe each manual browser scenario.
Main promotion, commit/push and production deployment remain separately unauthorized.
Production-version and employee OpenWebUI retrieval are separate verification claims.
Historical HR-2026-07-27-003 and scoped HR-2026-08-11-003 approvals remain unchanged.

- [x] Compare every configured Dashboard panel, source-authorized counts/lists,
      Storage zero/nonzero states and available action destinations in web/API.
- [x] Compare personal open/unread/overdue totals, combined overdue pagination and
      8/8/12 previews; exclude other users and closed/done/cancelled records.
- [x] Verify denied source permissions, private/masked Calendar events, ordinary
      mailbox scope and unavailable sources reveal no protected data. Emergency
      mailbox use stays in Mail's audit workflow, not routine Dashboard metrics.
- [x] Save every setting through web/API; check exact values, stale browser tabs,
      retry/key conflicts and native concurrent-write outcomes.
- [x] Revoke native/token rights; replay and receipt lookup fail without leaks.
- [x] Check desktop/mobile views and preserve Workday/Absence shortcuts/navigation.
- [x] Accept the Swagger and actual BookStack guidance/employee access evidence
      documented for Dev. Production version and OpenWebUI retrieval are separate.

Expected: exact authorized workflow parity; no partial settings save or duplicate
receipt; no manufactured healthy/zero state for missing data and no privilege grants.
Automated evidence: final Warroom 31/619; affected regression groups 343/5934 and
189/1773; final mail boundary group 45/807; all pass without failures/skips.
Native MySQL race: 200/412, loser waited 705 ms; rollback/replay/encryption passed.
All seven operations passed trusted HTTPS and schema/read-back verification.
BookStack 443/444 exact read-back and existing employee-role scope verified.
Reviewer: Svein Tore.
Reviewed date: 2026-10-27.
Result: Explicit acceptance of the completed Dev handoff: "Godkjenner".
No checkbox is inferred from automated tests; this records the human approval.

Deployment: exact additive 2026_10_27_110000_create_warroom_api_commands.php
already applied on Dev only; future authorized targets need it plus optimize:clear
and l5-swagger:generate. No frontend build, queue/scheduler/worker/provider activation.
Preserve APP_KEY and receipts during recovery. This Warroom change has not been
committed/pushed, promoted to Main or deployed to production.

