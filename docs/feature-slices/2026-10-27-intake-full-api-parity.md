# Feature Slices: Full Intake Browser/API Parity

Status: Done On Dev / Merged to Main in PR #340 / Production unchanged
Date: 2026-10-27
Parent: rfc/2026-10-27-intake-full-api-parity.md
Owner: Codex
Human review: HR-2026-10-27-INTAKE-API (Pending/planned)

Implemented sequentially under the approved RFC. Each slice includes its own typed contract,
meaningful Dev tests and documentation; do not leave advertised stub operations.

## INTAKE01 - Shared Authority And Form Administration

Status: Done On Dev.
Goal / user-visible behavior: configure every current form/field setting through the API.
Scope: shared validation/persistence, four review permission corrections, context/lookups,
form list/defaults/detail/create/update/toggle; scopes IN01-IN08 and shared command foundation.
Data touched: forms/fields plus additive encrypted command storage.
Permissions: native intake.manage/view/review and matching scopes; preserve narrow edit contexts.
Tests: 10 types, metadata, ordering/conditions/file settings, slug/foreign-field rejection,
active identity, view-only denials, create/update/toggle replay and stale revisions.
Documentation: API/schema, README, coverage and TODO.
Done: native/API parity and tested actual persistence; no unrelated grants/refactors.

## INTAKE02 - Submission Reads, Audit And Private Files

Status: Done On Dev.
Goal / user-visible behavior: discover and inspect the full authorized review workspace.
Scope: IN09-IN13, pagination/filtering, snapshots, event history, attachment metadata/download.
Data touched: read-only submissions/events/files and authorized related-record context.
Permissions: intake.view and read/file scopes; exact nested ownership; field-safe projections.
Tests: pagination/filter bounds, revoked/foreign access, matched targets, file bytes/headers,
cross-submission download denial, absent files and no storage-path/exception disclosure.
Documentation: typed read/stream schemas and employee inspection/download workflow.
Done: complete native review data and exact safe bytes without unintended state changes.

## INTAKE03 - Review, Linking And Full Target Routing

Status: Done On Dev.
Goal / user-visible behavior: process submissions entirely through the official API.
Scope: IN14-IN21, action previews/results, outcomes, duplicate reference, five link types,
Sales/Ticket/Task routes and Clients-owned optional customer creation.
Data touched: submission/events/claims and existing target-module rows/intent.
Permissions: native review and exact target/actor rights plus command scopes; fresh replay checks.
Tests: review/archive semantics, routed-state restrictions, duplicate ancestry, target isolation,
Contact bridge IDs, Ticket rules/SLA, Task creator fallback, Sales contact/customer behavior,
Tripletex-number and explicit-billing rules, conflicting/concurrent/replayed claims,
one target/event set and honest skipped/unknown results. Reconcile uncertain provider effects.
Documentation: cross-domain/API guide and troubleshooting.
Done: every action has exact authorized result evidence; files remain Intake-owned.

## INTAKE04 - Public Schema, JSON/Multipart Submission And Signal

Status: Done On Dev.
Goal / user-visible behavior: external callers can submit the same configured public form.
Scope: IN22-IN23, safe public schema/success contract, JSON and multipart uploads,
native matching/routing then one Signal; preserve browser CSRF and shared transport throttles.
Data touched: native submissions/snapshots/attachments/events/public idempotency claims;
existing configured downstream workflow only.
Permissions: published/legacy-active form, spam/validation/rate controls; no staff access granted.
Tests: paused/draft/archive denial, all field types and conditions, hidden values/files ignored,
MIME/count/size, spam generic response/no files/no Signal, snapshot/client scope, replay/
conflict/file cleanup, one target/Signal, Signal failure visibility to staff and no public PII.
Documentation: safe public examples, form upload and acknowledgement meaning.
Done: actual stored/effect read-back tested with no extra automation/provider activation.

## INTAKE05 - Coverage, Native Verification And Handbook

Status: Done On Dev.
Goal / user-visible behavior: a complete discoverable documented and verified Intake API.
Scope: exact browser/embedded/runtime/Swagger/scope/test/read-back matrix and final regressions.
Data touched: Knowledge source and actual approved employee BookStack articles after coding.
Permissions: existing staff publication/access only; no OpenWebUI/BookStack grant expansion.
Tests: schema-validated successes, every error envelope, native DB/HTTPS and affected modules.
Documentation: publish/read back exact articles/access, reconcile all artifact states and TODO,
record human checks, deployment/recovery commands and separate Dev/Main/production state.
Done: no untested/unmapped browser action; all automated checks pass and required documentation
is verified or a concrete blocker is reported. Human review remains Pending until explicit
named confirmation; no Main or production action is implied by Done On Dev.

## Verification

Dev 912 / 48,851; isolated Main candidate 760 / 9,773; final focused Intake 70 / 1,180
on both runtimes. All 23 operations have exact successful HTTP/schema evidence.
BookStack page 553 exact combined-source and inherited-access read-back verified.
HR-2026-10-27-INTAKE-API remains Pending; production deployment is separate.
