# Full Knowledge API Verification

Date: 2026-10-27. Owner: Codex. Named human reviewer/release owner: Svein Tore.
Status: Done On Dev; merged to Main in PR #331; Reviewed by Svein Tore, 2026-10-27; production unchanged.
Authorization: Svein Tore approved the concrete RFC and requested Main merge with
"Ja og merge til main". This does not mark manual scenarios Reviewed.

## Delivered Coverage
All 37 current browser rows and the embedded Livewire editor/tag manager and Integration
Sync Now workflow map to official typed APIs: 44 HTTP operations and 38 schemas.
Existing article/hierarchy URLs and PUT/PATCH remain compatible. API definitions now
belong to Knowledge/routes.php; api.php is its compatibility loader. Explicit scopes
narrow fresh native user/object rights. A narrow draft editor can read exact form context
without library listing; a Ticket-only technician can review only the linked eligible AI
revision. Portal uses the caller's active account/contact/membership and published audience.

Coverage includes full library/hierarchy navigation, bounded catalogs/lookups, article
proposals, immutable preview/diff/history, approve/reject/publish/retry/rollback, sync state,
candidate selection/outbound resolution, existing pull, live tag metadata and defaults.
Repository authority and the existing #266/#276 projection/sync workflows remain intact.

Local mutation and encrypted command receipt share a transaction. Replays recheck current
rights, actor/token, exact operation/input and opaque preconditions. Completed deletes
replay after binding disappears; stale decisions retain native conflict audit and a failed
receipt. Provider claims commit before execution. Running/unknown claims block new writes
to the canonical target, including alternate numeric URL spelling. Raw provider failures
are excluded; uncertain outcomes require reconciliation rather than another automatic call.
Default-off API provider execution additionally requires explicit scope and native sync rights.
Queue acceptance remains distinct from verified local/provider publication.

## Verification Evidence
Final Dev contract/parity: 16 tests / 1160 assertions, no failures or skips. Every one of
44 HTTP methods has actual successful response-schema verification, including uncertain and
failed-operation read-back. Tests include current actor/token/revocation, exact Ticket scope,
portal session/bearer/contact/membership isolation, source protection, hierarchy consistency,
provider ambiguity/gates, local/external publication/retry, rollback, shared Livewire tags,
defaults, encrypted replay, missing deleted binding and concurrency/state conflicts.

The earlier complete core batch passed 97 / 2663; the broader CustomerPortal, Taxonomy,
Notification, Documentation, Integration API/catalog/AI boundary and production Swagger
batch passed 252 / 3648. These groups overlap and must not be added. The final scoped Main batch passed **326 tests / 5246 assertions**, zero failures/skips.
The subsequent final Swagger/source-contract checks pass **2 / 694** after preserving all five
legacy article operation IDs; the final Dev contract check passes 1 / 692. Only OpenAPI
metadata/test assertions changed after the broad batch; executable domain code is unchanged.
All 33 scoped PHP files pass syntax/Pint and the final Main schema preserves every existing
non-Knowledge operation/schema. These totals overlap; do not add them.
The Main fixture intentionally excludes AiChatToolBoundaryTest because that unrelated
Dev-only test is absent on Main. No unrelated implementation was copied to add it.

33 scoped PHP files are syntax/Pint checked; scoped whitespace and UTF-8 are checked.
Native MySQL independently proved encrypted storage/read-back, replay-key uniqueness,
rollback and a second connection's mutex exclusion. No probe rows remain. Exact additive
migration 2026_10_27_020000_create_knowledge_api_commands ran on Dev only, batch 32.
Provider execution remains false; command and temporary read-token counts are zero.

Trusted HTTPS verifies seven authorized reads, anonymous 401, staff-without-portal-scope
403, and the exact published Swagger file. Independent Main autoload reflection and test
assertions require classes and application base_path to resolve inside the candidate checkout;
its dependency directory uses separate hardlinked files, not a vendor symlink back to Dev.
The shared Dev Swagger retains concurrent Signal additions and a Clients contract refresh.
The Main candidate overlays only Knowledge onto Main's own existing schema and source.

## Handbook And Coordination
Five native repository articles are exact-read-back internal/published sources. Actual BookStack
382/445/449/379 are updated and read back; the full guide is embedded in Knowledge Overview
382 because the existing connector rejects ordinary multi-character create/search values.
No malformed placeholder, credential change, permission expansion or backend bypass was used.
Native automatic sync is unconfigured and pending_push is not the proof of these publications.
Inherited page/chapter/book permissions were read without modification. Employee OpenWebUI
retrieval is not tested. API Management/BookStack integration updates preserve unrelated bodies;
previously tracked source drift and connector repair remain separate maintainer follow-ups.

Documentation's existing full current-browser parity is already merged in PR #329 and is
regression-tested here; it was not duplicated. #265/#261 and #275 remain separate future work.
Human-review checklist HR-2026-10-27-KNOWLEDGE-API is Reviewed by Svein Tore,
2026-10-27. The separate dependency reviews HR-2026-09-04-001 and HR-2026-09-03-003
remain Pending. Production/release/activation requires those gates and separate authorization.

## Deployment And Recovery
Production is not deployed. A separately authorized rollout needs the exact additive migration,
normal cache handling, updated Swagger, preserved APP_KEY/receipts and appropriate worker
restart only for changed deployed job code. Do not enable provider integration/policy/API switch
or workers implicitly. On failure disable new API entry points and preserve immutable revision
history and encrypted receipts; reconcile uncertain provider outcomes before further attempts.

## Promotion Scope
The dedicated Main checkout starts from origin/main. Three-way reconciliation includes only
this task's changes. The pre-existing Documentation source-sync test-count repair is a required
regression dependency. Unrelated shared Dev content/index is not staged, reset, committed or
pushed. An independently added Signal configuration was explicitly excluded from promotion;
it was not altered by Pint. Final merged-tree equality is verified after the GitHub merge.

## Final Candidate Readiness

The explicit 50-file manifest contains only Knowledge and its required shared contracts/docs.
Concurrent Signal code, scopes, source-guide sections and generated additions are excluded
from this Main candidate, while the shared Dev originals remain intact. All required Dev and
Main checks pass. The authorized merge is completed and independently verified. Production is unchanged.

## Independently Verified Main Merge

PR #331 is merged: https://github.com/SveinT83/Nexum-PSA/pull/331
Merge commit: 5dab62d56c7663368de11d0f5961e08d7e269bd3
Tested and merged tree: c134e03cc5cb5c1be5193f15993b5906d62f4102
A fresh fetch confirms exact tree equality. No Main implementation work remains. The
follow-up only reconciles documentation/status; application tests were not rerun.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Knowledge API handoff with
"Godkjenner og merge til main". HR-2026-10-27-KNOWLEDGE-API is Reviewed.
This records the named reviewer's acceptance of the presented scope; individual
scenario executions were not separately reported and are not invented. Main was
already merged in PR #331, with merge evidence reconciled in PR #332.
The older HR-2026-09-04-001 and HR-2026-09-03-003 reviews remain independent and
Pending. This acceptance does not authorize production deployment or provider activation.
This follow-up changes documentation/acceptance status only; application tests were
not rerun because executable code, configuration and OpenAPI are unchanged.

Acceptance wording was published/read back in native Knowledge article 45 and actual
BookStack page 382, revision 14. Existing permissions were retained. The website handoff
was updated/read back for this review decision; its production publication gate stays in place.
