# Relationship API Verification

Date: 2026-10-27. Owner: Codex.
Status: Done On Dev / Merged to Main in PR #349 / Human Review Pending / Production unchanged.

## Delivered scope
All ten supported browser rows map to 21 staff operations, five unchanged signed
ingress operations and five reused Ticket Relationship operations: 31 HTTP methods.
Typed schemas, native/token/source authority, all settings, rotation, health/history,
previews, guarded execution, encrypted command results, stale/replay/duplicate safety
and exact read-back are covered. Remote accepted identity is not remote content
read-back. Conflicts and unknown outcomes are recorded consistently in receipts,
links, health and audit before a success can be claimed.

## Authoritative Dev
Full affected run: 656 cases, 655 passed, one Documentation schema-read race while
another contributor regenerated shared Swagger; 48009 assertions. The exact failed
case was rerun and passed (1 / 24). An earlier in-flight UserManagement class change
caused stale-class failures and is superseded by this fresh run, not deferred.
Focused complete Relationship/Ticket contracts passed 29 / 2821, including all
31 successful HTTP operation bodies. Final numeric-route constraints are verified on Dev and the actual release candidate.
Only the exact additive Relationship migration ran on Dev. Native MySQL proves
encrypted storage, replay uniqueness, a competing connection blocked by row locking,
transaction rollback and the default-off API execution setting. No customer send,
role grant, provider/worker/scheduler activation or production operation occurred.

## Main candidate
The initial isolated Main affected set passed all 569 cases / 9664 assertions.
Missing candidate .env produced setup warnings; a minimal secret-free testing
environment fixed it and every affected warning class was rerun: 253 / 4390, no
warnings/failures. The final candidate incorporates newer Main independently.
Main initially lacked the five reused Ticket adapters. Only those bounded existing
Dev dependencies, two scopes, necessary resources/actions/envelopes and an idempotent
Ticket route loader accompany this change. The rest of Ticket/Task parity stays out.
Both current-domain routes and these five Ticket routes must exist on the release
branch; positive HTTP/schema tests enforce this instead of relying on Dev evidence.
The pre-existing validateTarget contributor change is reused byte-for-byte, not
rewritten or reverted on shared Dev. Shared Dev index and unrelated work stay intact.

## Handbook and access
Repository sync verified two exact Relationship source articles (61/62, revisions
150/151). BookStack 510/511 were published through the connected plugin and their
actual Markdown matched exactly. Existing page/chapter/book inheritance and Editor
employee view rights are verified; no access scope was expanded.
The native Dev BookStack integration is unconfigured. Native pending_push is not
called completed synchronization; actual plugin publication/read-back is the proof.
Search has a connector validation defect; exact container listing/read succeeded.
Employee OpenWebUI retrieval is not tested.

## Runtime and release gates
Trusted HTTPS anonymous probes return JSON 401 on staff and machine routes without
a TLS bypass. PHP files/directories remain web-readable. Swagger additions must
preserve unrelated current Main paths/schemas and include the exact 31-operation set.
Deploy requires the single additive migration, optimize:clear and l5-swagger:generate.
No frontend build or new queue/scheduler service is required.
Human-review checklist HR-2026-10-27-RELATIONSHIP-API stays Pending for browser and
real two-instance scenarios, production release/deployment and actual remote content
comparison. Main promotion is expressly authorized; no manual test execution or
production availability is inferred. Preserve APP_KEY and command/claim evidence.


## Final release candidate

Current Main base 7d8087e4851f823fc87eaeff2cd46ff84eb8b4b6 plus the complete bounded
Relationship/Ticket scope passes 338 tests / 7406 assertions, zero failures/errors/
skips. All 31 runtime HTTP methods and their successful typed bodies are verified.
Final authoritative Dev scoped run passes 29 / 2821. Main and production status
will be recorded separately after the authorized merge; human review remains Pending.


## Latest Main integration bridge

Main advanced independently with Intake/Lead Intelligence work. The scoped candidate
preserves those changes and is based on f91acc9045794ac4ede80a336f80974c17ab9bc4. Final bridge:
34 tests / 2910 assertions, no failures/errors/skips/warnings, including all 31
Relationship/Ticket successful methods, API key administration, both repository doc
counts and isolated production Swagger scanning. Non-owned Swagger bytes are preserved.


## Verified Main promotion

PR #349 is merged: https://github.com/SveinT83/Nexum-PSA/pull/349
Merge: ee2272b7bac566bfe57a3b61fada0ee0f7e67436
Tree: 860f4883b2ad4d7288583ff39d963b7fa1b980c3
All scoped runtime files and all 31 HTTP/33 schema contracts match the tested
candidate exactly. Main also contains concurrent unrelated Lead and Nextcloud work;
the entire repository tree is therefore not claimed identical to the candidate.
Additional rollback-retention checks pass on Dev and candidate (3 / 9 each) and on
native MySQL: recorded commands/outbound claims block destructive rollback. Production
remains undeployed and external execution off. HR-2026-10-27-RELATIONSHIP-API is Pending.


Published Main critical read-back was independently rerun: 9 / 1738, including all
31 successful HTTP operation bodies and API administration. Final guide Main status
was source-synced (revisions 187/151) and actual BookStack 510 was read back exactly;
511 remains unchanged and verified. Permissions remain inherited and unchanged.
The published-Main rerun also includes signed ingress success and retention checks.

## Diagnostic privacy follow-up

Connection requests, rotation receipts, credential-table queries and background signed
transport are excluded from Telescope diagnostic copies. Nested Nexum token/signature
headers and secret-bearing JSON are redacted outside that request context. Unexpected
API errors return generic no-store JSON and log only the exception class; browser
validation never flashes connection credentials. Encrypted canonical receipts/claims
remain authoritative. No historical telemetry deletion is performed. Five dedicated
Dev tests pass with 27 assertions; candidate regression verification is recorded below.

Privacy follow-up regression: authoritative Dev 37 / 2883; isolated Main candidate
42 / 2972, zero failures/errors, including API-key administration, Knowledge source
sync and production Swagger scanning. All 31 operation success contracts remain valid.

After integrating current Main fa6aeee942130d72673b809f4aacc6a9d67473e9, all 31
successful method contracts, retention, administration and five privacy checks pass
14 / 1765. Privacy follow-up PR #356 records its immutable GitHub merge state.
