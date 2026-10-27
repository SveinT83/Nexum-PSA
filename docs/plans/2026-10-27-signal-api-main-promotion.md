# Signal API Main Promotion

Date: 2026-10-27. Owner: Codex. Human reviewer and merge authorizer: Svein Tore.
Status: Reviewed / isolated Main candidate verified; commit and merge authorized; production unchanged.
Acceptance and explicit merge authorization: "Godkjenner og merge til main".
Human-review entry HR-2026-10-27-SIGNAL-API is Reviewed. Original scenario boxes
are retained without inventing individually witnessed executions.

## Scoped Candidate
Base: origin/main e94e3fe1261d3923fd7d6e5ee286ca38a751fd11.
Branch: codex/signal-api-20261027.
Isolated candidate: /tmp/nexum-signal-api-main-20261027.
The authoritative shared Dev working copy remains /var/Projects/tdPSA on Dev.
Only the 48-file Signal allowlist, generated Swagger and this promotion/evidence
record are packaged. Domain originals exactly match Main's pre-implementation
baseline; no newer Main Signal change is overwritten. The shared catalog includes
only eight additive Signal abilities. Knowledge has only one Signal source-sync
test replacement and the Signal API handbook section. TODO and human-review contain
only this scope's record. Other dirty Dev work and the shared index are preserved.

The candidate uses an independent vendor copy with identical composer.lock, verified
candidate App/Tests autoload paths, a synthetic APP_KEY and an in-memory SQLite
database. Production credentials, business data, real provider sends and runtime
activation are not used. All 19 Signal HTTP operations / 42 schemas are present.
Non-Signal Swagger operations and schemas are byte-equivalent as parsed data.

## Human Review And Independent Gates
The existing Signal recovery review HR-2026-07-15-002 is Reviewed. The older Knowledge
revision/sync entries HR-2026-09-04-001 and HR-2026-09-03-003 retain pending historical
checks in the authoritative register. Those workflows are already on Main and are
not changed or newly promoted by Signal. Their production/workflow gates are not
approved by this Signal acceptance. No Knowledge publication/sync authority, RMM
runtime activation or supplier processing activation is included in this candidate.

## Candidate Verification
Candidate verification passed 626 tests / 6271 assertions, with zero failures/errors/skips.
Final contract/source checks passed 2 tests / 310 assertions (overlapping totals).
All 27 scoped PHP files pass syntax/Pint. All 19 successful HTTP operations match
their typed schemas. Exact PHP hashes and scope evidence are in the adjacent JSON.
The post-merge record independently verifies the merge/tree identity.
No all-application or untested independent Tripletex provider-suite claim is made.
Evidence directory: /tmp/nexum-signal-main-20261027.

## Deployment And Recovery
This merge does not deploy production, migrate its database, enable API/provider/AI
execution, alter key grants or start workers/schedulers. A separately approved rollout
requires only 2026_10_27_030000_create_signal_api_commands.php, normal cache/Swagger
handling and review of affected existing workers. Preserve APP_KEY, encrypted command
receipts, immutable attempts and the webhook outbox; reconcile unknown external
outcomes instead of blind replay. No frontend build or package change is introduced.
