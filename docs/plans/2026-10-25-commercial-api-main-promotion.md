# Commercial API Main promotion - 25 October 2026

Status: Tested Main candidate; merge explicitly authorized by Svein Tore ("Merge").
Human review: HR-2026-10-25-COMMERCIAL-API, Reviewed by Svein Tore after the completed delivery.
Base: origin/main 2e2c500506e48981198e7ce18778060c7716b95a.
Candidate: codex/commercial-api-20261025 on the Dev server.

All supported Commercial catalogue, Contract, public/Portal, settings and operational timebank
workflows are covered by 103 typed HTTP operations. Durable revisions, replay, frozen delivery,
current rights and exact parent scope are shared with browser actions. Delivery rollout stays off.

## Fresh candidate verification

- Commercial and complete Customer Portal suites: 145 tests / 2040 assertions passed, 72.44 seconds.
- Economy, Clients, Integration/API administration, Swagger production scan, Tripletex profiles,
  Sales, Calendar, Ticket and scheduled SLA: 356 tests / 4880 assertions passed, 339.72 seconds.
- All 68 changed PHP files pass syntax and Pint. Autoload reflection resolves candidate files.
- Generated Swagger covers all 103 Commercial operations. Other domain paths, schemas,
  security and tags are structurally unchanged. The generated artifact is included.
- Earlier candidate Swagger staleness was corrected by regeneration, then the full relevant
  suite reran successfully. A synthetic, ignored test .env avoids missing-file warnings;
  no production or Dev credentials were copied into the candidate.

The isolated candidate preserves original Dev HEAD/index and unrelated dirty work. Shared ability
and API-loader changes use the pre-implementation baseline; shared TODO, review and handbook files
receive only Commercial sections. The original files.json remains the Dev source snapshot, not a
claim that all shared candidate files are byte-identical. main-evidence.json records candidate code.

HR-2026-09-27-WORKLOG remains separately Pending for external workload activation/release.
Its existing export implementation is already on Main and is unchanged by this promotion.
No workload capability, token ability, provider operation or rollout switch is activated here.

## Deployment boundary

This is source promotion only. Production deployment, the exact additive receipt/delivery migration,
worker refresh, controlled delivery activation and actual provider/inbox verification remain separate.
Retain encrypted delivery evidence and the app key during rollout/rollback. No production action ran.
The GitHub PR and independent Main read-back are the authoritative merge result.
