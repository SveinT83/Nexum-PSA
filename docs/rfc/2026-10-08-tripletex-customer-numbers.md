# RFC: Tripletex-authoritative customer numbers

Status: Approved
Date: 2026-10-08
Owner: Codex
Human review: HR-2026-10-08-TRIPLETEX-CUSTOMERS

## Context and current behavior

Svein approved shared numbers on 2026-10-07 and explicitly requested complete implementation
on 2026-10-08. Issue #239 and the completed #297 local allocation fix remain completed.
The existing integration supports company verification and Workday transfer, not customers.
Local five-digit allocation remains unchanged when customer synchronization is off.

## Goals and proposed change

When the integration and customer-number synchronization are active, Tripletex owns the
number and linked customers must have the exact same number in Nexum. Provide a read-only,
unreserved suggestion from the provider customer sequence, skipping numbers occupied locally
or by provider suppliers. Recalculate before creation, POST the proposed number, and accept
only a verified GET of the created customer. Never silently fall back locally on provider errors.
Tripletex has no next-number/reservation endpoint in the current public OpenAPI contract.

Keep customer-number synchronization separate from time synchronization. Preserve legacy
time enablement when introducing independent settings. Pausing preserves identities and numbers.
New linked records retain connection/company/environment identity and Tripletex customer ID.
Existing records are linked explicitly with expected local/provider numbers; differences require
explicit adoption of the provider number. No matching by name or customer number alone.
No automatic renumbering of existing customers. A reconciliation read shows drift/conflicts;
only an explicit reviewed adoption changes an existing local number.

## Scope and non-goals

This delivery covers customer-number synchronization, new-customer creation, explicit linking
and conflict reconciliation. Full profile/address/contact synchronization, deletion propagation,
bulk customer imports/exports and automatic historical matching remain future work.
Generic Data Exchange imports must not bypass provider authority: reject new provider-backed
creates inside its batch transaction; existing linked numbers cannot be overwritten.
No production customer is created, linked or renumbered during implementation.

## Impact analysis

Integration owns allowlisted provider transport and setup. DataExchange owns durable delivery
intent and customer bindings. Existing Clients entry points call the shared action; no legacy
Clients module rename is included. API/UI retain their existing create/update permissions.
Link/adopt/settings require the explicit integration management and client update permissions.
Changes affect UI/API create validation, linked-number edits and DataExchange imports.
No new queue or scheduler is required; number checks run on demand.
Risks: external outcome may be unknown, provider/local uniqueness conflicts, settings races,
legacy five-digit validation, and accidental activation of time transfer.

## Data and migration plan

Add a customer binding/delivery-intent table with unique connection/request, connection/provider
identity and local-client linkage. Store hashes and safe status, not tokens or raw request bodies.
Persist intent before external creation and never retry an ambiguous POST. Preserve an external
ID before read-back. Retry can finish local creation after verified external creation.
The scoped migration adds no customer data and does not activate synchronization.
Customer provider writes are authorized by the saved default-off customer synchronization setting in the GUI.
Rollback refuses to discard nonempty delivery evidence.

## Implementation slices

1. Done On Dev: provider contract, durable delivery and number authority with regression tests.
2. Done On Dev: independent settings, UI/API creation, explicit binding/adoption and import/edit guards.
3. Done On Dev: documentation, focused regression suite and migration/read-back. Human review is In Review under the named checklist.

## Testing plan

Cover both switches, six-digit numbers, stale forms, local/provider collisions, incomplete lists,
authorization, wrong company, unknown POST outcome, retry/read-back, local rollback recovery,
explicit linking/adoption, immutable numbers, paused preservation and legacy time behavior.
Use HTTP fakes for writes. Read-only live provider verification may check accessible numbers.
Human review must verify the UI and a separately authorized test-company write before rollout.

## Documentation and approval

Update Knowledge, API notes, TODO, verification and human review. No Main/production action.
Approved by Svein Tore in this chat: "Godkjent. Iverksett og kode ferdig dette nå".
This records the already approved scope; no additional product approval is requested.

## Completion evidence

See ../plans/2026-10-08-tripletex-customer-number-verification.md.
Linked numbers remain protected while paused to preserve identities. Explicit reviewed number
adoption is available while paused; activation refuses unresolved drift. Provider billing email
uses invoiceEmail. Unknown outcomes retain the saved company/environment binding. Full profile
synchronization remains future scope. No commit, push, Main or production action was taken.


## Settings feedback follow-up - 2026-10-08

Svein's runtime-off Save report is corrected on Dev. The form explains unavailable activation
and disables enabling while retaining a pause action for saved enabled settings. Expected runtime,
link-drift and stale-version failures return HTML users to settings; JSON callers retain 422/409
validation responses. Six focused cases pass across final runs. No activation, data migration,
provider call or provider customer write was performed. Human retest and the separately authorized
provider pilot remain open under HR-2026-10-08-TRIPLETEX-CUSTOMERS (In Review).

## Approved GUI authority revision - 2026-10-08

Svein explicitly requires enabling customer synchronization in the GUI to authorize customer writes,
without editing .env. This supersedes the separate customer-write runtime gate and the earlier
server-administrator activation requirement. Implementation is Done On Dev: 10 focused tests / 88 assertions passed.
Remove the customer-specific environment flag, rather than changing its default or editing .env.
The global integration runtime, verified company, active connection, saved customer setting,
permissions, account lock, freshness and provider read-back remain enforced.
Time-write configuration remains independent. No schema, queue, scheduler or permission changes.
The user performs the GUI activation; implementation does not toggle live settings or create
provider customers. Human review remains In Review and Main/production deployment remains gated.

## Approved profile extension - 2026-10-08

Billing Email and one bound Site address are now implemented on Dev as a separate approved
extension: RFC 2026-10-08-tripletex-customer-profiles and ADR
2026-10-08-tripletex-customer-profile-baselines. Earlier future-scope wording above describes
the original number-only slice. Names, recurring Contacts, other Sites and deletion remain excluded.
The extension adds its own state migration and five-minute scheduled command; the same GUI switch
controls it. Human review HR-2026-10-08-TRIPLETEX-PROFILES and the number review were approved by Svein Tore on 2026-10-08.

## Human approval and merge - 2026-10-08

Svein Tore explicitly approved the delivered customer scope and requested issue closure and Git
merge. HR-2026-10-08-TRIPLETEX-CUSTOMERS and HR-2026-10-08-TRIPLETEX-PROFILES are Reviewed.
This supersedes their earlier pending-review gate statements; automated/live-check limitations
remain recorded above without inventing additional test execution. Production deployment is separate.
Merge evidence: 2026-10-08-tripletex-customer-merge.md in docs/plans.
