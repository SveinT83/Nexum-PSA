# Full Main and API production rollout - 2026-11-05

Status: Production Main Installed / Complete Catalog Published / Operator Acceptance In Review.
Owner: Codex; authorization/reviewer: Svein Tore.
Authorization: "Aksepterer så kan du ta å implementere hele main og alle nye API'er".
Target: VM169, /var/www/nexumpsa/app; Main9270d2f19413506483717b48b1b241c658e20cb7.
Gateway: existing nexumpsa registration fa01d642-eb40-4149-a370-43634a71dfb1 on the existing gateway.

## Preflight
Trusted stored guest SSH host key and DPAPI owner credentials verified; no bypass or guessed login.
Production Git base81d1eb2a;4596 Main files already equal,186 new files,147 changed runtime/docs paths.
Production composer.lock exactly equals Main. All timestamped Main migrations already applied;
vendor.php is not a timestamped migration. No schema mutation is planned.
Existing background units disabled; preserve runtime flags, grants, identities, queue and scheduler
hold. Installing source/API definitions is not authority to drain old queues or activate providers.
Open operational review entries remain separate: Ticket/Task API, browser report, Workday and
intended-consumer/handbook checks. The user explicitly authorizes this rollout and accepts the
report API; do not fabricate individual scenario execution from that approval.

## Preserved production overlays
Byte-identical to authoritative Dev, retained without source commit:
- WorkdayReminders.php lazy read-only early stop (863d00346fc03e3aaf5a8f95c26bc1ca11bd3289f2b5c26e192a85ab2d1141cd).
- TripletexClient.php invoiceSendMethod EMAIL validation (c4e1d34b0c2777d1758ed6517f2e543b4546e13245fcb88e4a858f06908dd391).
- TripletexCustomerNumbers.php explicit invoice-email/no-org selection (cf81574a58ba179371938aec99aefa6d3d9086a9ea4e76f2a08d67956a38f7dc).
No customer/provider create, preferences, time or billing correction is part of deployment.

## Recovery
Protected VM /root/nexum-main-rollout-20261105 contains original environment/site config and fresh
single-transaction database export,3,693,456,501bytes, completion marker and SHA256 verified.
No SQL restore has been performed or claimed. Code rollback retains the entire old application
and shared live storage. No migration rollback is planned. The release switches only after tests,
Main runtime/Swagger preparation and unchanged-source/hash preconditions pass.

## Release and verification
Stage immutable Main archive and verified Git bundle; preserve only the three known overlays.
Reuse identical production dependencies and built assets (no JS/CSS sources changed).
Prepare own autoload, route inventory, Swagger and migration status before public switch.
Use bounded maintenance, preserve live storage and environment, atomic path switch, normal cache/
opcache/FPM refresh, trusted public HTTP/authenticated read-back, then confirm workers remain off.
Gateway update preserves legacy aliases, identity/validation/header overlays and native rights;
new tool exposure must not silently issue API grants or activate provider/runtime workflows.
All write APIs are verified by existing tests/contract/routes, not by destructive real-data probes.

## Runtime staging verified

Pinned Main boots with2233 runtime routes and1304 OpenAPI operations on production PHP8.3.6.
Main migration status has no Pending entries. Optimized autoload and route cache compile.
Protected activation/rollback scripts pass bash syntax checks; activation subsequently completed below.
Stored production preimage manifest covers3491 runtime files plus environment checksum.
Gateway candidate merges native Main with all existing IDs:1318 operations,14 legacy aliases
preserved using equivalent trailing-slash Laravel routes. Generator construction succeeded;
existing native actor/token controls and manager identity/validation patches remain unchanged.

## Completed production installation

CandidateMain+three unchanged overlays passes247 tests/1681 assertions on PHP8.3 (19:03).
Activation completed with short maintenance, FPM stop/switch/cache/start and automatic rollback
handler. Live app resolves to the pinned release; HEAD9270d2f1, all three overlay hashes exactly
match preflight. Environment bytes equal original. Main route/config/view caches compile.
Trusted public up/login200, unauthenticated report401, authenticated metadata/summary/items/entries
200. Customer54 period smoke shows36 objects and correct paging; actual XLSX/PDF content types
and ZIP/PDF signatures pass. No customer/time/financial write or provider test was performed.
Raw Swagger is /docs (1304operations, SHA25624ad6d85ab5f9054e42e0b3e09eb52132859ae0257a89ca385afa9588310fb2b);
the initially guessed /docs/api-docs.json returned404, not an application defect.
External active scheduler/worker scan finds none; both units inactive/disabled, maintenance off.

## Completed gateway update

Existing alias/server/spec path and all authentication, routing, group, credential and descriptor
fields remained identical across the official admin API update (202). Existing238 allowed names
are preserved; canonical names are added. Native Main1304 operations plus14 explicit compatibility
paths yield1318 spec operations. Actual live gateway tooling also includes91 existing compatibility
aliases, giving1409 SDK tools. The generic standalone converter initially omitted those runtime
aliases, so the final catalog intentionally retains original aliases plus canonical names.
No manager/generator/identity source modification or gateway restart was required.
Actual /mcp-rest/tools/list returns200 and1409tools. All four report tools have required client;
export additionally requires format. Existing createclient keeps Idempotency-Key/body required.
Current connected getreport(client.time) succeeds after the update and returns four API descriptors.
This chat's installed connector metadata still needs a tool reload/new chat to see new functions;
no new function call through the refreshed end-user catalog is falsely claimed.
Backup: /opt/ai-gateway/backups/full-main-20261105 (original spec and238-name list). Rollback restores
that spec and updates only allowed_tools through the existing official admin endpoint.
Personal bindings/keys, provider settings, queue/scheduler and old job history remain unchanged.

## Handbook and operational handoff

Authoritative source notices updated in Clients, Report and Integration Knowledge guides on Dev
and in the active production release. Independently read back exact source notices from BookStack
Client Domain Overview451 revision19, Report API506 revision5, API Management449 revision171.
Pages and chapters retain inherited scope; book339 existing PaperClip development/Nexum role
overrides unchanged. Employee OpenWebUI retrieval remains a distinct human check.
No permission expansion, broad repository sync or unrelated handbook publication performed.
The source record PR is documentation-only; application runtime remains pinned to9270d2f1 plus
the three preserved corrections and these three Knowledge documentation updates.
No further migration, build or deployment command is required for this delivery.
