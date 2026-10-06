# Nexum PSA Controlled History API Handoff

Status: implemented and verified on authoritative Dev; production export NOT verified.
Date: 2026-09-27. Scope approved by Svein in this conversation.

## Outcome by need

| Need | Classification and Dev result | Production / next gate |
| --- | --- | --- |
| Worklog OpenAPI | Existed without documentation; now both existing routes have parameters, schemas, abilities, workload/profile/limit/error contracts | Production OpenAPI still lacks worklog |
| External client access | Existing workload model reused; installation context mismatch repaired, source permissions enforced, no settings/grants enabled | Approved provider/model, workload-bound read-only token and matching NexumMCP connection required |
| Complete history | Silent cap repaired; total is uncapped, truncation is explicit, pages and disjoint date subdivision are documented/tested | A single capped day remains incomplete pending explicit finite policy review; no auto-widening |
| Time sources | Ticket/Task registered time plus separately scoped Commercial quick consumption; Task completion estimates explicitly labelled | No payroll/immutable accounting snapshot claim; only current authorized surviving source rows |
| Reports | Report API is metadata-only; only ticket.sla is registered. SLA browser report has no time-data export. Worklog and Commercial own this extraction | NexumMCP owns whether metadata report reads belong in its catalog |
| Contract joins | New Commercial contract-links and direct-consumption projections share permitted opaque aliases; inconsistent foreign links are null | Ordinary client_id/contract_id is not an alias; no inverse mapping or identified fallback |

## Exact target and evidence

- Dev: https://dev.nexumpsa.eu, branch Dev, base commit 36918edbcaae1ce7b1208ba34f84a3a1719f0e98
  plus the uncommitted working-copy files hashed in verification-manifest.json. Version file says
  0.2.0-beta; this static value alone does not identify the patch.
- 98 distinct tests / 855 latest assertions pass. The final focused API/contract suite passes
  28 / 307; broader Commercial/Task regressions, Ticket time and API-key tests also passed.
- Three overflow/date regressions failed before the maintenance repair. An isolated context
  diagnostic returned a customer row under internal_only before its repair; final scope tests pass.
- Generated OpenAPI with four operations and 13 schemas is read back from
  https://dev.nexumpsa.eu/docs?api-docs.json and matches the local generated operations.
- All four anonymous Dev API routes return HTTP 401. Positive data reads are full Laravel HTTP
  feature tests on this Dev code using an isolated SQLite database, not an activated live workload.
- Live Dev is still AI off / external off / aggregate maximum, no coordinator_api workload; all
  three source tables have zero rows in 2025-09-01 through 2026-08-31. This says nothing about
  production time totals. No real historical production export is delivered.
- Production https://portal.tronderdata.no/docs?api-docs.json returns 200, documents reports and
  has none of these four worklog paths. Code version/authenticated history is unverified.
- NexumMCP package 1.0.0 rejected worklog/time-entries and reports before upstream contact. This
  is a catalog restriction, not an upstream 401/403 or proof that data is absent. No catalog changed.
- GitHub #178 is already closed/completed. Its original HR-2026-07-29-012 was Reviewed by Svein;
  the new scope has its own pending review entry. No Issue/comment was posted.

## Contract and package deliverables

- openapi-worklog.json: verified route/schema subset with source hashes and Dev target.
- nexummcp-package-delta.json: exact method/path/key/ability/parameter/paging requirements for four
  operations, proposed package revision 1.0.1, and separate report-metadata catalog candidates.
  This is an uninstalled handoff, not an executable package or a permissions change. NexumMCP must
  map it to its native package schema while preserving connection/sensitivity/approval policy.
- verification-manifest.json: target, code hashes, contract hash, test counts, runtime limitations,
  HTTP readback and remaining production/human-review gates.
- report-api.md and commercial-worklog-api.md: complete operational contracts and extraction steps.
- human-review.md: exact pending HR-2026-09-27-WORKLOG checklist from authoritative Dev.
- RFC-controlled-history.md and ADR-time-facts.md: approved scope, provenance and ownership decisions.

NexumMCP owns the actual package/catalog revision, its missing report-read assessment, connection
selection, token-secret storage and Paperclip tool update. Do not point the existing ordinary
production token at these APIs and call it workload approval. Do not infer access from Ticket reads.
Use a separately approved bound coordinator connection to the exact target; revalidate the deployed
OpenAPI and source version. No domain-specific query logic should be invented in NexumMCP.

## Reconciliation and limitations

Extract 2025-09-01 through 2026-08-31 as 365 inclusive dates in nonoverlapping policy-sized windows.
Keep the same workload/target/profile; follow meta.next_page. Require total, available_total,
returned_count and truncated. Discard truncated parent-window data before splitting. A single
truncated date requires an explicit reviewed policy decision; stop with an incomplete manifest.

Registered Ticket/Task rows are not necessarily stopwatch-measured: registration_basis separates
recorded, estimated and unknown Task sources. Keep direct Commercial consumption as a separate
fact type. Billing-basis and allocation quantities must not be added to registered-time totals.
The tested reconciliation is 45 registered Ticket/Task minutes, 20 direct-consumption minutes and
70 separate billing-basis minutes, with valid alias joins and no duplicate work count.

No snapshot is frozen. Reconcile duplicates/count drift and re-read changed windows. Completeness
means all currently authorized, surviving records in the declared source set, not deleted records,
previous ownership, raw call/calendar activity, external timesheets or approved payroll. Broken
contexts/contract relations are not backfilled by guessing; report coverage gaps. Contract dates/
approval state are current metadata and do not replace historic signed customer documents.

## Changed source areas and rollout

Report controller, response schemas and tests; Integration shared context/window services,
coordinator middleware, explicit ability catalog, governance tests and Knowledge; Commercial
controller/schemas/tests and domain route declarations (existing api.php only delegates);
Ticket/Task stale controllers reuse the shared context predicate. TODO, RFC, ADR, three Knowledge
articles and human-review state are updated. No source time or contract data is mutated.

No migration, seeding, scheduler, queue action or asset build is required. Svein owns commit/Main
promotion/deployment. After review: deploy the listed source, run php artisan l5-swagger:generate,
refresh normal application cache/opcache and sync Knowledge for Report, Commercial and Integration.
Existing unrelated dirty Dev work was preserved. Scratch/backups and test logs are outside the repo
at /tmp/nexum-worklog-20260927-55zsp0od. No full application suite was run.

HR-2026-09-27-WORKLOG is a pending human-review checklist entry. It blocks Main promotion,
production release and external workload activation. Svein must check selected scope/recipient,
allowed/denied reads and audit, overflow recovery, source reconciliation and safe alias joins.
Automated tests do not mark it Reviewed. No production changes or public product announcement.
