Availability: Dev only, 2026-10-20; all approved Ticket/Task API slices are implemented on Dev; automated acceptance and named human review are tracked separately. Existing tokens and production configuration were not changed.

## Scope selection in API Management

1. Open **Admin > System > Integrations > API Management** with API administration permission.
2. Create a narrowly scoped token for the intended account. The account must independently hold
   the same domain rights as the browser operation. Never copy an administrator identity into
   another user's integration to defeat a denial.
3. Use tickets.read/tasks.read for implemented context/history reads; explicitly add
   tickets.time.read, tickets.costs.read or tasks.time.read only for the corresponding ledgers.
4. Test a bounded permitted GET and its exact record context. Verify an unrelated nested ID and
   insufficient user rights remain denied. Existing low-privilege message metadata stays limited.
5. Regenerate/read back OpenAPI when the authorized deployment includes these endpoints. A Dev
   route or successful token creation does not establish production availability or full parity.

The capability endpoints /api/v1/tickets/capabilities and /api/v1/tasks/capabilities identify the
complete registered domain API surface, including existing operations, separate token/user decisions and remaining object/runtime checks.
See the Ticket and Task API Context And Readback articles for current routes and troubleshooting.

## Full writes and Swagger

The Dev surface includes create/edit, comments/files, time/cost corrections, stock actions, merge/lifecycle, Task completion/time, templates/schedules, configuration, workflow/Rules, selected Mail reply preparation, Relationship escalation, reports and Portal operations. Select the exact write/action grants in Swagger; tickets.read/tasks.read alone authorize no general edit.

Swagger documents every registered HTTP method/path, including PUT/PATCH aliases, typed payloads, multipart uploads, binary downloads, errors and cumulative/conditional grants. Use the documented preview only where required; merge explicitly requires none. Read back exact records after a mutation. Stable request keys apply only to operations documented as idempotent. For legacy creation/publication commands without a retry receipt, inspect persisted results before repeating an uncertain call.

Publication blocker: Dev has no configured supported BookStack integration/credentials. Svein owns establishing the intended publication/access path. Codex must then sync only these reviewed source articles and verify their actual BookStack content and employee access. Ten unrelated pending articles must not be bulk-pushed. Local sources, BookStack publication and employee assistant retrieval are separate states.
