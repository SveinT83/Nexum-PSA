# RFC: Complete Mail Browser And API Parity

Status: Approved
Date: 2026-10-20
Owner: Codex; product reviewer and promotion owner: Svein Tore
Level: 3
Parent: 2026-07-04-mail-module-full-email-client.md
Evidence: ../plans/2026-10-20-mail-domain-api-plan.md and gap matrix JSON/Markdown

## Context

Svein explicitly requests full API support for everything that can be done in the browser,
including writes, actions, administration and updated Swagger. The 73-route baseline leaves
27 missing and six partial capability families. The approved inventory is the full outcome map.

## Goals

Complete all implemented Mail browser capabilities through typed module APIs. Share queries,
actions, authorization, validation, side effects and independently readable results. Document
every registered method/path with request, response, ability and errors in generated Swagger.

## Non-Goals

No Main commit/push/deployment, provider rollout, automatic mail sending, permission bypass or
activation of existing default-off capabilities. Existing unimplemented product slices keep their
owners and are not implemented merely by registering placeholders.

## Current Behavior

See the verified 2026-10-20 plan/matrix. Mail includes personal/shared accounts, provider folders,
conversations, local unread state, drafts/send/Sent, rules, AI and operator maintenance.

## Proposed Change

Execute the complete plan using six independently verified slices: read/shared queries and selected
state; folder/preferences/refresh; rules/AI/signatures; admin/accounts/access/templates; operator
maintenance and Ticket handoff; complete Swagger/parity acceptance. Existing APIs remain compatible.
New routes live in Email/routes.php with the existing API loader retaining a compatibility bridge.
Only implemented abilities are registered. Account administration never grants content access.

The legacy Delete discrepancy is resolved by Svein: ordinary Delete in browser and API moves to
provider Trash. It never soft-deletes the source or directly expunges IMAP. Permanent deletion stays
under its existing distinct guarded product slice and runtime approval; it is not enabled here.

## Impact Analysis

Email owns all Mail behavior. Integration owns the ability catalog and AI policy, Ticket its own
case/audience actions, Taxonomy definitions and Notification delivery. Preserve dirty work and
coordinate additive shared catalog/route edits with the existing Ticket/Task implementation.

## Data And Migration Plan

Reuse current models, epochs, guards, remote-operation and draft/submission ledgers. No blanket
schema migration. Record any specific new schema requirement before that slice proceeds. Passwords
are encrypted/write-only; no credential or body dump in audit. No runtime backlog is released.

## Testing Plan

Isolated SQLite feature tests on Dev for every new contract, writes/read-back and permission denial;
existing affected Email/Integration/Ticket regressions; schema-to-route checks and generated Swagger.
Provider fakes do not establish live provider behavior. Named human mailbox/browser review remains.

## Documentation Plan

Update Email/Integration Knowledge, module README, gap/slice evidence and human-review checklist.
Publish exact changed sources through configured BookStack integration and verify employee scope;
record a concrete blocker/owner if publication is unavailable. Public website handoff is descriptive
and explicitly withheld from publication until the feature's availability is verified.

## Open Questions

None blocks non-destructive implementation. Ordinary Delete-to-Trash was explicitly chosen.

## Approval

Svein Tore approved full browser/API read-write-action-administration parity and Swagger in chat
on 2026-10-20. In a separate answer the same day he approved ordinary Delete moving to Trash.
Human review HR-2026-10-20-MAIL-API is Reviewed. Svein Tore approved the delivered Mail API scope on 2026-10-21 in chat: "Godkjenner".
This clears the delivered scope review; Main promotion/deployment/release remain separate actions.
Individual provider/manual scenario results were not supplied. Existing runtime reviews still apply.


## Dev Delivery

All six slices are Done On Dev. The domain has 175 documented HTTP operations, including 109
write/action operations, with explicit token ceilings and shared browser actions. All 72 browser
routes are mapped. See ../plans/2026-10-20-mail-api-verification.md for tests, handbook read-back,
remaining human review and promotion gates. No provider runtime is enabled by this delivery.

## Production Delivery (2026-10-21)

Application release 11cf40227e9dfa52747b96cdf58e5f64ae706f43 was merged and deployed under Svein Tore's explicit request.
All 175 Mail API contracts are published in production, including 109 write/actions. Trusted HTTPS,
source hashes and an authenticated Mail context read were independently verified. Existing runtime
flags remain false; no new migration, provider write, token widening or recipient delivery test.
See docs/plans/2026-10-21-mail-api-production-deployment.md for backup and deployment evidence.
