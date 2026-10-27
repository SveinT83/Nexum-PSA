# Feature Slices: Full Marketing API Parity

Status: In Progress on authoritative Dev / RFC Approved / Main merge authorized.
Date: 2026-10-27
Parent: ../rfc/2026-10-27-marketing-full-api-parity.md
Owner: Codex. Intended reviewer: Svein Tore.

## Goal

Complete all 31 browser route rows, seven embedded/API-only workflow groups and all
29 existing API HTTP operations in the coverage matrix. Work sequentially on Dev.

## User-Visible Behavior

Integrations can perform all supported Marketing reads, edits, actions and settings
with discoverable inputs, exact results and current actor/token authority. Existing
consent, suppression, approval and lifetime no-resend behavior is preserved.

## Scope

| Slice | Status | Independently reviewable result | Required verification |
| --- | --- | --- | --- |
| MK01 Authority and discovery | In Progress | Module route ownership; native/token mappings; bounded narrow form contexts and capability metadata | Old routes/aliases, native/token denials and authorized narrow success; no privilege/provider seeding on GET |
| MK02 Read parity | Draft | Dashboard, forms/lookups, campaign interest/content, recipient/event pagination and safe sender/AI summaries | Equivalent web/API datasets, exact-parent isolation, search/pagination, no credentials/capabilities disclosed |
| MK03 Shared local commands | Draft | Shared list/campaign/email/schedule/settings validation/mutations; encrypted replay/state boundary and exact receipts | Persistence/eligibility/history, unkeyed compatibility, keyed conflict/replay/revocation, native DB locking and stale/concurrent writes |
| MK04 Renderer and provider operations | Draft | Unsaved/snapshot preview, WordPress pull, AI assist, test-send and due-send provider boundaries | Positive provider fakes, default-off denial, TLS/URL policy, no preview writes/sends, queued current authority, unknown outcomes and lifetime no-resend |
| MK05 Contract and regression | Draft | Complete typed Swagger requests/results/errors and runtime-route matrix | Exact method coverage and actual success/error schema checks; complete affected Dev test set, lint/Pint, native DB and trusted HTTP |
| MK06 Handbook and handoff | Draft | README/Knowledge/API guide, actual BookStack read-back, TODO/evidence/human-review reconciliation | Content and employee access read-back, no fabricated retrieval/delivery; list exact migration/cache/worker actions and independent gates |

## Out Of Scope

New marketing features, repeat/resend, Google/social integrations, unsupported lifecycle
controls, role grants, real customer emails, Main commit/push/merge or production deploy.

## Data Touched

Existing Marketing lists/members/campaigns/emails/recipients/delivery identities/events/
content/settings; planned additive command schema. Existing Contact consent/suppression,
Email templates/bindings and Integration AI authority remain their owners' contracts.

## Permissions

Use exact parent RFC native/token mappings, settings admin boundary and API provider
execution guard. Context/read-back must reauthorize exact objects on every request.

## Tests

Each slice runs its narrow meaningful tests before the next; MK05 reruns complete
changed-contract regressions. Baseline 65 tests / 811 assertions passed before changes.
Public tracking/unsubscribe keep their existing capability and media-type tests.

## Documentation

Marketing README/Knowledge/full API guide, API Management, BookStack, matrix/evidence,
RFC/ADR/slice statuses, TODO and HR-2026-10-27-MARKETING-API.

## Done Criteria

- [ ] Every observed route and embedded/API-only workflow has verified coverage evidence.
- [ ] Every protected method has a typed request/success/error contract and real success test.
- [ ] Native/token/object guards, replay/stale/concurrent behavior and native DB are verified.
- [ ] Provider disabled and authorized enabled paths, queue state and no-resend are verified.
- [ ] Actual handbook content/access is read back, or exact blocker/owner remains recorded.
- [ ] Required deployment actions and Dev/manual/Main/production status are explicit.
