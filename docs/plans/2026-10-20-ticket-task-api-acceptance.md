# Ticket and Task API parity: Dev acceptance and handoff

Date: 2026-10-20. Branch: Dev. Authoritative root: /var/Projects/tdPSA.
Parent RFC: 2026-10-20-ticket-task-full-api-parity (approved by Svein Tore).
Historical Dev acceptance snapshot dated 2026-10-20. Human-review checklist: **HR-2026-10-20-TICKET-TASK-API — Pending** for operational scenarios and production deployment/release. Svein Tore separately approved the scoped Main merge on 2026-10-28; [current promotion evidence](2026-10-28-ticket-task-api-main-promotion.md) supersedes the original Main gate below. The remaining 2026-10-20 statements describe that date, not current Main/production state.

## Delivered behavior

All ten approved slices and 41 audited functional groups are implemented. The live inventory is
130 browser route rows and 214 API route rows / **216 HTTP operations**: **113 GET, 62 POST,
22 PUT, 7 PATCH and 12 DELETE**. Both existing PUT/PATCH aliases are included.

- Full Ticket/Task create/edit/context, scheduling, tags, checklist, activity/hierarchy/dependency
  and time/cost read-back; explicit conversation, notes/notifications, solution, files and receipts.
- Ticket correction/reservation/release/pick, automatic assignment, lifecycle/classification/delete
  and cross-client merge without preview, as explicitly approved. Tasks/reservations follow the
  target context; booked financial customer/contract identity and actual-time evidence are preserved.
- Task actual versus cumulative billable time, domain completion, template graphs/application,
  generation receipts, recurring schedules/manual runs and defaults/billing settings.
- Ticket policy/catalog/profile/assignment administration with atomic existing-journal audit;
  complete workflow/quote/planned-line/review/evidence APIs and full Rules lifecycle/history/retry.
- Selected Mail reply preparation with native draft/send/submission/reconciliation links, exact
  documentation follow-ups, Relationship escalation receipts, SLA report and own-membership Portal.
- All legacy APIs retain explicit module route ownership and current actor/context checks. Fixed
  full-operation input fields prevent successful-looking ignored writes across shared validators.
  Native quote/review/evidence results use narrow projections without public tokens/raw snapshots.

Current actor permissions, token grants and object/field/context/membership policy remain distinct.
No new login/token-issuance/MFA flow or role widening is introduced. Existing Rules/Custom Field/AI
runtime switches remain unchanged. A status value is not Task completion; accepted/queued is not
provider reconciliation or customer delivery. Existing legacy creation/publication commands without
receipt identities must be read back before an uncertain retry; idempotency is documented per action.

## Verification evidence

The final broad Dev run covers **66 affected-domain files: 560 passed / 15,569 assertions**.
It includes all Ticket/Task feature/unit tests, native browser/workflow/Portal/template behaviors,
Mail drafts/Ticket links and Economy generation. External effects use isolated/faked boundaries.
The final legacy internal-owner Task edge case failed before its narrow scope fix. After that
fix, all 12 affected Task/read-back/lifecycle/Swagger files passed **106 tests / 11,079 assertions**.
The broad and focused counts overlap and must not be added as unique tests. The fix accepts a
valid internal Task context when its legacy internal Ticket has no stored context, while preserving
all current non-null owner/customer/private checks and leaving the Ticket unchanged. Earlier failing fixtures/contract assumptions were corrected rather than
relaxing current permissions or customer context.

The Swagger contract test checks every actual method/path, parameters, success/error schemas,
all references, cumulative grants, complete input fields, multipart/binary responses and aliases.
A second inventory check requires an explicit parity audit for newly registered browser/API routes.
Generated Swagger JSON was independently fetched over trusted HTTPS with HTTP 200 and all 216
method/path pairs present. The server base is '/', so Try it out targets the current installation.
Live read-only schema preflight checked 57 relevant models and found no missing table/fillable field.
Final HTTPS smoke returned 401 for six unauthenticated Ticket/Task/Rules/Portal requests. Served
and generated Swagger SHA-256 hashes match. The actual Swagger UI contains SwaggerUIBundle.
PHP lint covers the entire scoped changed PHP set; formatting was restricted to this task's new
files and rewritten API controllers, preserving unrelated shared formatting.

SQLite :memory: tests prove functional validation, rollback and transport replay; they do not prove
MariaDB concurrency, operating-system scheduler execution, real provider acceptance or inbox delivery.
No live customer/provider sends, operational stock/billing writes or production activation occurred
in QA. Existing PHPUnit doc-comment deprecation warnings are not test failures.

## Files and architecture

See [scoped changed-file inventory](2026-10-20-ticket-task-api-files.json) and
[full functional/API matrix](2026-10-20-ticket-task-api-gap-matrix.md), with JSON route inventory.
The inventory distinguishes this task's scope; the complete Git diff also contains pre-existing
shared work and is not an attribution or staging list for unrelated changes.

Domain routes are in Ticket/routes.php and Task/routes.php. Existing api.php files are compatibility
loader entries. The API bootstrap only loads the approved module route files under their API guards.
Shared extracted domain actions retain browser authority; there is no unrestricted model/command
bridge. ADR: 2026-10-20-domain-api-authorization-and-audit-reuse.

No new dependency, database migration or frontend build is introduced. Configuration audit reuses
the existing activity_log journal on the same connection and fails atomically if unavailable.
Before snapshots and temporary verification evidence are outside the repository under
/tmp/nexum-ticket-task-api-20261020; no transient scratch file was added to the repository root.

## Promotion and operating prerequisites

Svein owns commit, Main promotion and deployment after the named review. No commit/push/merge or
Main/production deployment was performed. Verify the target installation's existing domain tables,
audit configuration, provider/member grants and already-required migrations independently.
After approved deployment, use the normal pipeline plus:

```sh
php artisan optimize:clear
php artisan l5-swagger:generate
php artisan queue:restart
```

Verify readable PHP files/directories and affected HTTP routes, then read back served Swagger.
Scheduler operation requires the existing external once-per-minute schedule:run runner; API routes
and schedule:list do not establish scheduler health. Source changes do not authorize runtime gates.
A code rollback must preserve actual time, billing/stock effects, provider attempts and audit receipts.

## Remaining gates and known limits

- Svein must perform the concrete operational checklist in HR-2026-10-20-TICKET-TASK-API before production deployment/release,
  including real-database concurrency and same-account browser/API comparison.
- A Relationship crash after provider submission can leave a reserved/unresolved local receipt.
  The owning operator must verify the provider record and reconcile the attempt before resending.
  The API deliberately retains uncertainty instead of generating duplicate external Tickets.
- Knowledge sources are updated. BookStack publication is blocked by missing supported Dev
  integration/access configuration. Svein owns the intended path; Codex owns exact-source publication,
  actual content read-back and employee access verification afterward. Ten unrelated pending articles
  must not be bulk-pushed. Employee assistant retrieval remains a separate unverified claim.
- Dev evidence does not establish Main/production availability or human acceptance. Existing Rules
  activation, scheduler/queue and Knowledge revision/publication work retains its separate owners.

Related existing review records remain open and were not marked Reviewed by this API work:

- HR-2026-10-05-WORKDAY-TRIPLETEX - Automatic Time Synchronization — In Review; initial connection save/company identity check confirmed by Svein Tore on 2026-10-05. The bounded own-employee pilot is now executable.
- HR-2026-10-01-WORKDAY - Workday Time, Simple Plans And Absence — In Review. Svein's calendar modal correction is implemented/tested on Dev
- HR-2026-09-27-WORKLOG - Controlled History And Commercial Time Export — Pending. Owner/reviewer: Svein. Added: 2026-09-27. Environment: Dev.
- HR-2026-09-17-EMAIL-MISSING - Trash An Already Missing Message — Pending. Owner/reviewer: Svein. Scope: Level 1 Mail stale-placement repair.
- HR-2026-09-17-EMAIL-SAVE - Email Account Save Schema Repair — Pending browser verification. Owner: Svein / Codex. Added: 2026-09-17.
- [HR-2026-10-20-TICKET-TASK-API] Complete Ticket/Task API parity — Pending

These records must be assessed for the exact promotion scope; this task does not silently approve
the rest of the dirty shared checkout.

Final served Swagger SHA-256: 10474f5259fe90ba6889e29a2d2d2995c3441777c586a6d934765689de3b8262.

Website handoff: one public-safe item added to the configured Content and Visibility handoff file
and independently read back in UTF-8. Status is klar for vurdering with an explicit Ikke publiser
gate until named human review, approved production deployment and production verification. No public
website/social publication was performed.
