# RFC: Full Risk Browser/API Coverage

Status: Verified on Dev / Merged to Main / Human Reviewed by Svein Tore, 2026-10-26.
Date: 2026-10-26. Owner: Codex.
Product/design approver and human reviewer/release owner: Svein Tore.
Change level: 3 - API, authorization, audit/workflow and additive command data.
Human-review checklist: HR-2026-10-26-RISK-API (Reviewed; separate from design approval).
Matrix: ../plans/2026-10-26-risk-api-gap-matrix.md.
Baseline: ../plans/2026-10-26-risk-api-route-baseline.json.

## Context

Svein requested the next domain lacking complete API support, then said
"Kjør på med risk...." after the recommendation to prepare the full coverage
matrix and RFC. This authorizes this planning work. Svein Tore approved the concrete RFC and its explicit approval/history rules
with "Godkjenner" on 2026-10-26. The approved implementation is now verified on Dev.

Risk already implements assessments, items, historical updates, current score
snapshots, approval, PDF reporting and configurable defaults in the browser.
Authoritative Dev has 17 browser route rows and eight API route rows representing
ten HTTP operations excluding HEAD. Existing APIs cover basic assessment/item
creation/read/update and appending item history, but not all operational behavior.

All Risk source is currently clean and matches the last verified Main version.
Clients has completed its scoped Main merge and does not leave an overlapping
implementation task. Existing Ticket/Task, Worklog, Calendar and other manual
review/operational gates remain their own workstreams; this RFC does not clear them.
No matching open Risk GitHub Issue or existing full Risk API RFC was found.
Existing module README/Knowledge, routes, controllers, actions, queries, models,
resources, settings, views, permissions, tests and migrations were inspected.
Baseline RiskSystemTest passed: 9 tests / 63 assertions on Dev.

## Goals

Deliver full current Risk workflow parity, not a read-only slice or an endpoint count:

- Cover every R01-R16 matrix row, including supported writes, deletion, approval,
  administration, PDF, context, exact result read-back and side effects.
- Keep one shared Risk workflow/authorization source for browser and API.
- Enforce current active actor, token, parent, role, owner and field authority.
- Preserve history/current-snapshot consistency, safe approval and exact scores.
- Make permitted options, defaults, constraints, capabilities and errors discoverable.
- Provide bounded lists, complete typed schemas, concurrency/replay protection,
  meaningful automated/native verification and verified employee documentation.

## Non-Goals

- A new risk methodology, scoring scale, dashboard, AI analysis or automatic acceptance.
- New reminders, recurring jobs, provider actions, notifications or external MCP server.
- New RiskItemLink management, bulk import, public/customer-portal access or sharing.
- Hard-purging assessments/items, a restore UI, retention policy or status backfill.
- Widening roles, assigning permissions, automatically widening issued tokens or
  enabling unrelated default-off runtime capabilities.
- Main commit/push/merge, production migration/deployment or publication of website copy.
  Those require their own later instruction and appropriate review.

## Current Behavior

Risk owns the workflow. Clients and WorkContext supply internal/customer context;
Taxonomy owns categories. Integration owns API key abilities and Swagger entry points.

Web route permissions are mapped in EnforceTechRoutePermission, not inferred from
an Admin URL or role label. Item descriptive edits additionally require Superuser
or the creator of the first remaining history row. Assessment/item soft deletion
requires current risk.delete and Superuser. Update deletion requires risk.delete
plus Superuser or the exact update creator. Do not replace these rules with a
generic risk.update token check.

RiskSettings supplies six configured defaults. Assessment creation resolves and
persists its Work Context. Item creation calculates likelihood * impact, adds an
initial history row and may move a new assessment to in_progress. Appending history
updates the current snapshot and can reopen an approved assessment.
Descriptive edits create an audit note when applicable and lock historical scoring
fields. API metadata PATCH preserves existing metadata by merging prior values.

Assessment/item deletion uses SoftDeletes. History update deletion is a real row
delete and resynchronizes the current item from remaining history. If no history
remains, the current action preserves the item snapshot. No new baseline is created.

Approval records status, approved_at and approved_by. PDF uses the same categories,
items, history, authors, approver and score thresholds shown by the browser.
The current API does not provide these explicit approval, delete, settings or PDF
operations. It also lacks browser-equivalent creator checks for descriptive item edits.
Some accepted inputs are silently ignored, and most OpenAPI responses are generic.

## Proposed Change

### Complete official contract

Keep supported existing URLs, methods, successful envelopes and valid payloads.
Move all Risk domain registration into app/Modules/Risk/routes.php using the
current domain-loader pattern. api.php may remain a compatibility shim.
Do not create new files under routes/ or change module/model ownership.

The proposed target contains 27 HTTP operations excluding HEAD: retain the existing
ten methods and add the seventeen methods below. This is a planned catalogue;
only final route/schema equality and workflow tests can establish completion.

| New surface | Proposed official method/path |
| --- | --- |
| Global defaults/filter/create context | GET /api/v1/risk/context |
| Bounded active-Client lookup | GET /api/v1/risk/context/clients |
| Bounded category lookup | GET /api/v1/risk/context/categories |
| Assessment edit/action context | GET /api/v1/risk/assessments/{assessment}/context |
| Assessment item list | GET /api/v1/risk/assessments/{assessment}/items |
| Assessment soft deletion | DELETE /api/v1/risk/assessments/{assessment} |
| Explicit approval | POST /api/v1/risk/assessments/{assessment}/approve |
| Binary report | GET /api/v1/risk/assessments/{assessment}/pdf |
| Item edit/history context | GET /api/v1/risk/items/{item}/context |
| Item soft deletion | DELETE /api/v1/risk/items/{item} |
| Item history list | GET /api/v1/risk/items/{item}/updates |
| Exact history read-back | GET /api/v1/risk/updates/{update} |
| History deletion | DELETE /api/v1/risk/updates/{update} |
| Settings read | GET /api/v1/risk/settings |
| Settings full/partial update | PUT and PATCH /api/v1/risk/settings |
| Own command receipt | GET /api/v1/risk/operations/{command} |

Context returns typed defaults, supported values/ranges, current permissions,
record revision, writable/locked fields, available actions and relevant read-back
links. A capability is not authorization to a later changed record.
Active Client and category lookup follow verified browser selection rules, use
minimal fields and bounded pagination, and do not expose unrelated customer/user data.
Preserve the current Risk-category preference and documented fallback when no
risk/risk_item categories exist; Taxonomy remains the category owner.

GET/context/report reads never create settings, Work Contexts, commands or
provider state, alter the technician session, dispatch business jobs or repair data.
Explicit scope/Client/Work Context filters must account for legacy null context
rows without silently creating context or changing existing filter meaning.
Reject conflicting filter identities. Bound page sizes at 100 and provide stable
tie-breaking, truthful totals and completeness; do not truncate lists silently.

Keep all persisted assessment/item/history fields and derived score/approval
information available for exact authorized read-back. Read children only through
an active, authorized parent; soft-deleted assessments must not leave an alternative
item/history API path into deleted assessment data. No cascade or hard purge is added.

### Current actor and token authority

Use shared DomainApiAuthorization and current Risk ownership policy. Refresh the
actor's active state, rights and record ownership on each request/replay. Fail
closed when permission/parent evidence is unavailable. Sanctum abilities are a
ceiling and do not grant roles, current permissions or ownership.

| Operation | API ceiling | Current actor/record policy |
| --- | --- | --- |
| List/show/context/PDF | risk.read | risk.view; context also checks the represented write capability |
| Assessment creation | risk.create | risk.create |
| Assessment metadata update | risk.update | risk.update |
| Add item | existing risk.create | risk.update, matching the existing browser item-store route |
| Descriptive item update | risk.update | risk.update and first-remaining-history creator or Superuser |
| Append history | risk.update | risk.view, matching the current browser update-store route |
| Approve | proposed risk.approve | risk.view and current nonempty/addressed-item approval rule |
| Assessment/item deletion | proposed risk.delete | risk.delete and Superuser |
| History deletion | proposed risk.delete | risk.delete and exact history creator or Superuser |
| Settings read/write | proposed risk.settings.read / risk.settings.update | risk.manage_settings |
| Own receipt read | proposed risk.operations.read | same actor/token ownership and current underlying operation/record authority |

The unusual existing browser permission distinctions above are deliberate parity,
not an unreviewed expansion to a new role model. A future decision to require
different actor rights for approval/history append is a separate permission change.
Add new ability names only alongside actual routes, tests and API Management copy.
Do not hard-code Admin-only access to settings when the current route uses
risk.manage_settings. Do not widen existing tokens automatically.

### Shared mutation semantics and strict validation

Extract/reuse shared actions, validators, queries and guard decisions from current
web controllers; retain browser redirects, supported fields and English UI copy.
Return actual persisted state, resulting revision and explicit affected IDs.

- Derive work_context_id, score, approved_by and approval time server-side.
- Require a valid Client for client scope; internal scope cannot silently retain
  a supplied Client. PATCH omission retains prior scope/fields.
- Validate assessment creation status against supported non-approved defaults.
  Reject status on metadata update because the action currently ignores it.
  Approval cannot be fabricated through generic create/PATCH input.
- Risk item scoring/status with existing history changes through Add Update.
  Reject changed locked fields rather than report ignored changes as saved.
  Same-as-current legacy hidden form values may be accepted for compatibility,
  after equality is verified under lock.
- Preserve legitimate existing API next_review_at support and its null/omission
  distinctions; do not remove an existing supported API field to match a smaller form.
- Never create a second initial/update/audit-history row on a replay or no-op edit.
- Reject unknown mutation keys and foreign/inactive parent references explicitly.
  Document any tightening of formerly ignored input with regression tests.

### Approval and history integrity decisions for approval

The current model checks only that no item is open, while the README says every
item must be mitigated or accepted. Resolve this mismatch explicitly in this RFC:

Recommended rule: approve only a nonempty active item set where every status is
mitigated or accepted. Unknown/legacy values remain visible, but block approval
until deliberately reviewed through the supported update workflow. Do not migrate,
rename or auto-accept old statuses.

Use a shared stable lock order for web/API assessment, item, history, approval and
deletion writes. Approval must use current item state under that lock; a concurrent
new open item/update must not leave a falsely current approved assessment.

Any change to a signed-off item/history must mark current approval inactive and
reopen the assessment to in_progress. Keep the last recorded approval stamp as
historical evidence; expose current approval validity explicitly, so an old stamp
is not presented as current sign-off. A repeated already-approved command must not
restamp the actor/time as a second approval.

History deletion selects the newest remaining entry deterministically by created_at
and id, replacing the relation's existing ascending order. When the final row is
removed, retain the existing current snapshot, report zero remaining history and
do not invent a baseline/creator. Re-evaluate actual edit capability using the
existing first-remaining-history rule; no receipt or API token grants new ownership.
Deletion must return exact snapshot, history/approval effect and durable evidence.

These are explicit correctness decisions to share between browser and API, not
silent data/permission changes made during planning. Approval of this RFC accepts
these decisions; otherwise the RFC must be revised before implementation.

### Revisions, replay and audit

Use content revisions covering the relevant assessment, active items/history,
settings and current state; same-second changes must still conflict.
Require If-Match and a stable Idempotency-Key for new destructive/action/settings
commands. Preserve existing ordinary CRUD compatibility, with documented optional
revision/key support on legacy entry points; do not promise replay protection to
a request without a supplied stable key.

Bind durable command identity to current actor/token context, operation, parent,
payload and expected revision. Same key with changed input conflicts; repeat of an
accepted command returns its recorded identity plus current authorized read-back.
Persist minimal outcome/IDs and encrypted sensitive result details; do not keep
unnecessary copies of risk notes or expose receipt internals in normal resources.

New Risk-owned command receipts and a mutex/unique constraint may be added where
the current shared domain patterns cannot provide these guarantees directly.
All local effects and receipt completion are atomic. Browser writes participate
in the same locking order so API approval cannot race a browser history edit.
No external transport, delivery queue or provider intent is introduced.

### PDF and settings

Share the report query/summary/render action between browser and API. Keep the
current template, scoring thresholds, grouping and permitted audit information.
Document application/pdf success, content-disposition/filename and typed JSON
errors. Do not accept an arbitrary URL/template/path from the caller or introduce
a new outbound destination. Render a consistent permitted data snapshot.

Settings use the existing six keys/options/ranges and RiskSettings persistence.
GET does not initialize a missing row. PUT validates the full supported form;
PATCH merges omitted values before validation. Changes affect defaults for future
records only and do not backfill existing risk state.

## Impact Analysis

Risk: route registration, API/web adapters, shared actions/policy/query/report,
resources, model approval predicate, settings validation, tests and module docs.
Integration: add only matching API ability catalogue entries; keep all unrelated
dirty abilities/loaders. Reuse its existing current-actor guard and typed helpers.
Clients/WorkContext: verify scope/identity and minimal authorized references;
WorkContext writes happen only during supported mutations.
Taxonomy: reuse category selection/validation, no new taxonomy mutation ownership.
Report: Risk continues to own its existing PDF; do not add an unnecessary new hub.
No front-end build, package, recurring job, worker or provider activation is planned.

Risks: losing history integrity or original ownership, stale approval, races,
deleted-parent bypass, ignored writes, misrepresenting old approval stamps,
large embedded timelines, permission divergence and overlapping shared edits.
Risk source is clean; shared TODO, HR, route loader and ability catalogue remain
dirty and must be changed by scoped delta only. Preserve every unrelated contributor.

## Data And Migration Plan

Reuse existing Risk/WorkContext/Taxonomy/settings tables. No risk-data rewrite,
status normalization, historical deletion or provider call is part of planning.
After approval, document the exact minimal additive Risk command/mutex migration
and deploy order in the implementation slice before applying it to Dev.
Native schema/row-lock/unique verification is required; SQLite alone is insufficient.
Production migration/deployment and Main promotion require separate authorization.

Preserve receipts, history, financial/customer references and encryption keys on
rollback. Do not drop nonempty command evidence to clear replay conflicts.
Release after human review: exact additive migration, relevant Laravel cache
refresh, Swagger generation and trusted HTTPS/PDF/authentication read-back.
No queue restart or scheduler activation is justified by this API change alone.

## Testing Plan

Run on authoritative Dev with umask 0002 and HOME=/tmp. Existing baseline:
RiskSystemTest, 9 passed / 63 assertions. This does not prove the proposed changes.

For every R01-R16 row, map exact route methods, schemas and named tests:

- Correct and missing scopes; current actor revoked/inactive/rights changed;
  creator/Superuser/foreign-record denial and replay after rights change.
- Pure reads, explicit session-independent filters, legacy null Work Context,
  bounded options/paging and truthful totals/aggregates.
- Configured defaults; Client/internal validation; mutation omission/null/false;
  ignored-field rejection; read-back of all supported current API fields.
- Atomic item + baseline history; locked historical fields; generated descriptive
  history once; next review dates, deterministic update order and exact scores.
- Approve empty/open/legacy/unhandled/fully addressed sets, no direct approval
  injection, repeated command, stale revision and concurrent child mutation.
- Soft deletion with preserved child history but closed alternate read paths;
  exact history ownership; deleting latest/nonlatest/last rows and equal timestamps;
  correct current snapshot, approval validity and current edit capability.
- Settings role/permission split, invalid bounds, missing row GET and partial merge.
- Shared PDF contents/headers, proper permitted audit data and refusal on deleted
  parents; inspect a rendered synthetic report rather than only HTTP 200.
- Native database lock/unique/rollback probes and controlled synthetic concurrency;
  exact trusted HTTPS JSON/PDF/Swagger/read-denial and temporary token cleanup.

Run Risk plus affected Clients/WorkContext/Taxonomy/Report/Integration API management
and typed contract tests; broaden after new shared changes or concerns.
Every actual HTTP method, response/error/PDF and schema must be present in Swagger.
Do not defer a failed check silently or mark a passed suite as human acceptance.

## Documentation Plan

Update authoritative Risk README and both Knowledge articles; add a complete API
guide covering scopes, current rights/ownership, context, settings, revisions,
replay, deletion, approval validity, PDF and troubleshooting. Correct stale README
claims about defaults/statuses and the old unavailable SQLite test environment.

Update the scoped API Management article and exact R01-R16 verification evidence.
Sync only affected Knowledge sources. Publish/read back actual BookStack articles,
rendered content and existing employee access through a verified personal/supported
path. Do not activate a missing native sync integration or bulk-push unrelated work.
Record a concrete publication blocker/owner in TODO if unresolved. Employee
OpenWebUI retrieval is separate and not implied by BookStack publication.

Maintain TODO and HR-2026-10-26-RISK-API in the same session as state changes.
Only verified completed behavior is eligible for the website editorial handoff,
with production/do-not-publish gates. Draft planning does not change the handbook.

## Proposed implementation sequence after target approval

1. Context/resources and exact current authorization/parent policy.
2. Shared assessment/item/metadata commands, revisions and strict field handling.
3. History/deletion/approval integrity, replay receipts and native locking.
4. Shared PDF and complete Risk Settings API.
5. Exact OpenAPI catalogue, affected regression/native/HTTPS checks and handbook.

Each becomes a small complete Feature Slice under this approved target, with
scope, data, permissions, named tests, documentation and done criteria.
Do not start with a reduced read-only delivery and call the remaining rows future scope.

## Open Questions

None blocking: the full target and explicit approval/history rules are approved.
All five Feature Slices are implemented and verified on Dev. Final evidence: ../plans/2026-10-26-risk-api-verification.md.
Human acceptance is recorded on 2026-10-26; Main promotion and production deployment remain separate.

## Approval

Approved by Svein Tore on 2026-10-26: "Godkjenner", in direct response to the
concrete RFC and question including the mitigated/accepted-only approval rule.
This authorizes full Dev implementation, tests and exact additive Dev migration.
That design approval did not mark human review complete. Separate human acceptance was
later received from Svein Tore: "Godkjenner", 2026-10-26, after the Dev handoff.
Main/production remain unauthorized by that review-only acceptance.

## Main promotion completed

Main merge separately authorized by Svein Tore: "Ja", 2026-10-26.
PR #321: https://github.com/SveinT83/Nexum-PSA/pull/321. Merge SHA: bf5c7d0c608895031eea04aa9a65cfd7c8a7ba98.
Merged tree equals the tested isolated candidate. Production was not deployed.
See ../plans/2026-10-26-risk-api-main-promotion.md for promotion/test/deployment evidence.
