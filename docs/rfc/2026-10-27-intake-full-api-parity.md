# RFC: Full Intake Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex
Approver / product owner: Svein Tore
Classification: Level 3 (API, permission alignment, public submissions and cross-module routing)
Implementation: Done On Dev, verified; scoped Main merge authorized separately.
Human review: HR-2026-10-27-INTAKE-API (planned, Pending).

## Context

The request is "Intake domain, lag 100% api støtte". Authoritative source is Dev
`/var/Projects/tdPSA`, branch Dev. Current Intake has 17 browser route rows
(14 internal, 3 public), no Intake API operations and 10 configurable field types.
Telephony call intake belongs to Telephony and is excluded from these counts.

Existing approved decisions remain: public inquiry forms, layout builder,
conditional fields/submit text, Signal post-submit automation, final routing/review.
The old human review HR-2026-08-11-001 is Reviewed by Svein (2026-08-25);
it does not approve this new API contract.

## Goals

Cover every supported browser read, write, action and administrative setting,
including public schema/submission, uploads, exact staff read-back, matching,
review, routing and audit. Include typed Swagger/OpenAPI, explicit token abilities,
native human/record rights, regression tests and verified employee documentation.
A route count or read-only interface is not acceptance.

## Non-Goals

No new booking, CAPTCHA, widget, deletion/purge, public submission-status listing,
public attachment download, direct public user creation or second automation engine.
No silent file copying, new permission grants, provider enablement, backlog processing,
real employee/customer sends or automatic runtime activation.
Commit/push, Main promotion and production deployment require separate authorization.

## Baseline Behavior And Approved Corrections

Internal routes use active internal-user middleware and EnforceTechRoutePermission.
Forms use intake.manage; workspace/files use intake.view; documented review actions
use intake.submission_review. Four implemented route names currently fall through
to intake.view: outcome, link-existing, route-ticket, route-task. This disagrees with
the approved final-routing slice and Intake handbook. Approval of this RFC explicitly
authorizes correcting those four browser routes and sharing the documented review
authority with the API. No behavior correction is implemented at this planning stage.

Form administration currently validates/persists in its controller; field normalization
is shared. Fields have keys, mapping, options, required/active flags, order, width
12/6/4/3, conditional all/any rules referencing earlier fields, and file overrides.
Unknown field IDs must not mutate another form. Updates preserve unrelated metadata;
submission snapshots retain historical field definitions.

Public routes check active/publication status, throttle reads at 60/minute and
submission at 10/minute, honeypot and server visibility/validation. Hidden input/file
values are ignored. Uploads belong to Intake. Storage precedes direct routing, then
one non-spam intake_submission_received Signal is recorded. Signal owns follow-up.
A failed Signal recording is persisted as an event; it must not become a false success
claim or trigger repeated target creation when a request is retried.

Ticket and Task routing invoke StoreTicket/StoreTask. Sales routing currently creates
SalesOpportunity/SalesActivity directly and can create Client/ClientSite through an
older local-number path. It also copies contact email to billing_email. This conflicts
with current provider-authoritative Clients rules when Tripletex customer sync is active.
This RFC includes replacing that legacy Client creation step with the existing
Clients-owned creation/intent boundary, preserving optional auto-create behavior.
Never invent a local customer number under active Tripletex sync, never copy primary
contact email into billing, persist one provider request key and reconcile unknown
attempts before retry. Provider-unavailable/unknown results must remain explicit and
reviewable; they cannot silently downgrade to local creation.

## Proposed Change

1. Domain routes live in Intake/routes.php; protected registration uses the established
   v1 Sanctum module loader and public routes use an explicit public module branch.
   Any api.php is a compatibility loader only; no domain routes in routes/api.php.
2. Implement the 23 proposed HTTP operations in the coverage matrix. Staff endpoints
   include workspace/context, eligible lookups, form list/defaults/detail/create/update/
   toggle, submission list/detail/events/files/download, reviewed/outcome/link and
   separate Sales/Ticket/Task routing. Add non-mutating command previews and exact,
   actor-owned receipt read-back. Public schema and JSON/multipart submission cover
   both public display and thanks behavior without exposing internal processing.
3. Extract shared form validation/persistence and review/routing authorization for
   browser/API; do not forward browser redirects or create parallel business rules.
   Preserve complete field/settings semantics, snapshots, English messages and native
   target actions. Apply precise parent/attachment/field/duplicate-target checks.
4. Use proposed scopes intake.read, intake.forms.write, intake.submissions.review,
   intake.submissions.route and intake.attachments.read only with real routes/tests.
   Native permission is additionally required. Scoped manage/review users must be
   able to discover their own required context and read the exact result without
   automatically receiving broad workspace/PII access. Recheck active/internal identity,
   token validity, target policies and record/field scope on every request and replay.
5. Staff writes require a durable idempotency key, canonical payload/file fingerprints,
   transaction/row locking and stale-revision protection. Toggle replay cannot toggle
   twice. Competing route actions cannot create different targets for one submission.
   Events, form fields and file storage must not duplicate. Store encrypted results
   with actor/token/operation/resource binding; redact storage paths and exception text.
   Reconcile unknown external outcomes before allowing another attempt.
6. Public schema returns only what the published form displays plus validation/layout/
   honeypot/upload/submit/success contract. Never return owner email, internal scope
   records, matches, target IDs, event errors or stored attachment paths. No public
   lookup/list of submissions. Public acceptance is a minimal generic thanks response,
   identical for normal and honeypot handling; it is not proof of routing or delivery.
   Public retries use a high-entropy form-bound idempotency key and a canonical
   fields/files fingerprint; same key/body repeats generic acceptance, conflicts
   return validation/conflict without data, and no public receipt exposes internal state.
   Apply shared web/API throttling identities so switching transports is not a bypass.
   Existing browser CSRF protection remains. Add no broad credentialed CORS access.
7. Keep matching, known-client/manual/auto-all modes, Task creator fallback, Contact
   canonical identity and legacy ClientUser IDs. Preserve native Ticket rules/defaults/
   SLA and Task rules, and direct-route-before-Signal ordering. No additional automation
   or provider capability is activated by exposing an endpoint.
8. Return typed created/updated/routed/already-linked/skipped/conflict/unknown results
   with fresh authorized state. Do not claim success for null routing, queued jobs,
   failed Signal recording, HTTP acceptance or unverified inbox delivery.
9. Update Swagger scan paths, schema/request/response/error/pagination contracts and
   ability catalog in the same slices. Final runtime method/path set must equal the
   documented set, excluding HEAD; validate successful responses against schemas.
   Every browser/embedded workflow must have real tests/read-back before claiming 100%.

## Impact Analysis

Intake owns form/submission/upload/event/command state. Integration owns ability catalog
and shared Swagger entry points. Clients owns number/provider creation intent; Contact
owns canonical identity and bridge. Sales/Ticket/Task own target records/workflows.
Signal remains the automation owner; CustomerPortal/Notification own any configured
downstream invitation/send. Tests must isolate and assert those side effects.

Security changes include the four documented permission fixes, fresh authority on replay,
private receipts, nested-file ownership, current related-record access and strict
public/internal response separation. Existing grants are not expanded.
Shared middleware/catalog/Swagger/Clients edits require fresh overlap checks because
Dev contains extensive unrelated work; never replace or revert another contributor's files.

## Data And Migration Plan

One additive Intake command/claim table is expected (exact migration name finalized
during approved implementation), with unique actor/form-operation-idempotency binding,
input hash, encrypted result, status, revision and timestamps. Preserve APP_KEY.
No destructive migration, historical backfill, target deletion or raw-upload duplication.
Public claim retention must not erase submission history or reopen duplicate effects.
Run only the exact additive migration on Dev after reading current human-review gates;
do not run all unrelated pending migrations. Verify native DB and test DB semantics.
Rollback disables the new API entry points and retains command/submission/audit evidence.
Future deployment: exact migration, normal optimize/cache handling and Swagger generation;
no new cron, frontend build or worker/scheduler activation expected. Changed queued code
would require the existing deployment worker restart, documented if implementation needs it.

## Testing Plan

Current native baseline: 21 tests / 168 assertions passed on Dev, no failures/skips.
This is existing behavior evidence, not API completion.
Add meaningful feature tests for every planned operation, 10 field types, field order/
deletion/metadata preservation, conditions, uploads, form scopes and lifecycle states.
Test missing/revoked/wrong scopes, inactive/external identities, four view-only denials,
authorized management/review-only contexts, related target access and nested ownership.
Public tests cover publication, JSON/multipart, limits, spam equivalence, hidden fields,
no PII leakage and shared throttles. Test actual replay/conflict/concurrent route claims,
file/event counts, stale previews, unknown provider and failed Signal outcomes.
Cross-module tests cover Clients provider authority and no inferred billing address,
Contact bridge, Ticket rules/SLA, Task actor fallback and Signal action idempotency.
Run affected Intake/Integration/Knowledge/Signal/Sales/Ticket/Task/Clients/Contact tests
on authoritative Dev, scoped Pint/syntax/diff, exact route/Swagger comparison, native DB
and trusted HTTPS smoke checks. No real customer sends/provider writes in tests.

## Documentation Plan

Update Intake README, intake-public-inquiry-forms Knowledge source, API guide, TODO,
ADR, slice states, coverage evidence and HR-2026-10-27-INTAKE-API.
After implementation publish/sync affected articles through the supported integration,
read back actual BookStack content and employee access scope. Local Markdown/queued
sync/API success alone is insufficient. Record any publication blocker and owner in TODO.
Planning alone needs no handbook publication. Keep Dev/Main/production claims separate.
Public website handoff, if warranted by completed work, is public-safe and do-not-publish
until production verification; resolve stale Windows profile paths before writing.

## Open Questions

No unresolved product decision. The concrete RFC and its permission/Clients authority
alignment were approved by Svein Tore. Manual production review remains separate.

## Approval

Approved by Svein Tore on 2026-10-27: "Godkjenner og merge til main".
This approves the concrete RFC including four permission corrections, Clients authority
alignment, Dev implementation and a verified scoped Main merge. It is not production
deployment or confirmation that the manual review scenarios have been performed.
Intake is this task's primary implementation; independent Knowledge/Signal work is preserved.

## Implementation Evidence

Completed and tested on Dev: 23 operations / 38 schemas, all 17 browser rows. See
plans/2026-10-27-intake-api-verification.md/.json for native HTTPS/MySQL, exact HTTP
contracts, affected suites, handbook read-back and the Pending human-review gate.
