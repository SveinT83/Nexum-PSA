# Feature Slices: Full Signal API Parity

Status: Done On Dev / Reviewed by Svein Tore / Merged to Main in PR #334 / Production unchanged.
Date: 2026-10-27
Parent: ../rfc/2026-10-27-signal-full-api-parity.md
Owner: Codex. Product/release owner and reviewer: Svein Tore.
Human-review checklist: HR-2026-10-27-SIGNAL-API (Pending).

## Goal And User-Visible Behavior
Every supported Signal browser workflow and its aliases has a typed official API
with equivalent actor/object authority, validation, context and operational results.

## Sequential Slices
1. Shared authorization, route ownership/compatibility, feed/detail/history and
   context/lookups; exact-parent Client/Contact coverage; pure discovery.
2. Encrypted commands, replay and opaque state guards, legacy ingest authorization,
   exact record/rule-processing results and own-operation read-back.
3. Rule list/detail/create/update, grouped/legacy definitions, full ten-action
   catalog, Intake prefill, shared validation and settings read/update/agent status.
4. Locked execution preview/retry failed/full rerun, stable identities, cross-entry
   concurrency and external scope/switch checks; truthful provider/outbox states.
5. Complete typed Swagger and coverage matrix, full affected/native/HTTPS checks,
   handbook publication/read-back and current TODO/review/deployment handoff.

Each slice includes meaningful tests and its source documentation. Verify one slice
before starting the next. This is sequential work, not delegated agent coordination.

## Scope And Data Touched
Signal records/rules/executions, existing Signal common_settings, stable downstream
actions and one additive command/lock schema. No rule rewrite or business backfill.

## Out Of Scope
New producers/actions, unsupported delete/edit workflows, real customer sends,
queue backlog recovery, role grants, AI activation, Main/production promotion.

## Permissions
Use the parent RFC native/token mapping. Preserve downstream ownership, Email
source trust, exact Client/Contact scope and default-off external API execution.

## Tests
Cover all matrix rows with real success/error schema checks, least privilege,
revocation, replay/stale state, browser/API parity, all supported definitions,
concurrent retry, pending/unknown effects, provider fakes and affected regressions.

## Documentation
README, Knowledge/BookStack, API guide, coverage/evidence, TODO and human review.

## Done Criteria
- [x] All 18 observed browser rows plus embedded related-history/forms are mapped.
- [x] Existing ingest remains supported with documented actor/action/provider guards.
- [x] Every exposed HTTP method has typed request/result/error contract tests.
- [x] Current actor/object authority, replay and concurrency are independently verified.
- [x] Handbook content/access is read back or concrete blocker/owner is recorded.
- [x] Dev, human review, Main, production and runtime activation are stated separately.


## Final Dev Evidence
All five slices are implemented and verified. Final Signal: 62 tests / 894 assertions;
all 19 HTTP operations / 42 schemas, affected regression, native MySQL, HTTPS and
actual handbook read-back are in ../plans/2026-10-27-signal-api-verification.md.
Human review is Reviewed; automatic checks never mark it Reviewed. Main source promotion is complete in PR #334; no production/provider activation is included.

## Verified Main Promotion (2026-10-27)

Svein Tore accepted the completed scope and authorized Main merge. PR #334 is merged:
https://github.com/SveinT83/Nexum-PSA/pull/334
Merge: be28f77d16f29e662b7b8585b27971e9c1e62438
Tested and merged tree: 6d2a4a6961b22d7a6ee44a20a8d7f7ab86d3c28b; exact equality verified by fresh fetch.
Candidate: 626 tests / 6271 assertions; final contract/source: 2 / 310, zero failures/skips.
All 27 tested PHP hashes match. Main action is complete; production/provider activation
remains separately authorized. Shared Dev work and its staged index are preserved.
