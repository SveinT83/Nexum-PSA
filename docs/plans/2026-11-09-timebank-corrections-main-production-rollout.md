# Timebank Corrections Main Promotion And Production Rollout

Status: Authorized / Isolated Main candidate verified; merge and production rollout in progress.
Date: 2026-11-09. Owner: Codex; authorization/reviewer: Svein Tore.
Authorization: "ok. Merge til main og rull ut til produktsjon".
BookStack remains explicitly deferred by Svein: "Godkjenner. BookStack faar vente".

## Scope and gates

Only the approved timebank adjustment/reversal implementation, operational API, correction-aware
reports and corresponding documentation are included. The dirty Dev worktree is not committed,
reset or merged wholesale. Main baseline f74479ab5eaa2e155a35a5e6134a4dc4a7f9a388.
HR-2026-11-09-TIMEBANK-CORRECTIONS is Reviewed. Existing HR-2026-11-05-CLIENT-TIME-REPORT
(In Review), CLIENT-TIME-REPORT-API (Pending) and FULL-MAIN-ROLLOUT (In Review) remain preserved;
this scoped production installation is explicitly authorized without inventing their execution.

## Production baseline and preservation

Verified VM169 hostname nexumpsa, expected machine identity and current path
/var/www/nexumpsa/releases/20261105-main-9270d2f1. Application HEAD9270d2f1.
Retain the three reviewed Workday/Tripletex overlays and three prior Knowledge source notices,
current storage/environment, dependency lock and disabled queue/scheduler state.
New feature migration and exposure are authorized; no customer reset/reversal, employee work
change, provider activation, permission grant or old queue release is part of deployment.

## Candidate verification

Worktree on the Dev server based on current origin/main. Only task deltas are applied using
the recorded pre-change baselines. Documentation conflicts preserve Main history and append
only this feature's sections. Independent vendor/autoload and isolated SQLite test environment
ensure tests exercise the candidate, not the dirty Dev worktree. Verified with production PHP8.3: 301 tests passed / 3589 assertions (308.68 seconds).
Production-identical built assets were verified by aggregate SHA256; composer.lock is unchanged.
Fresh protected production backup completed: 3,693,023,255 raw bytes; 237,409,680 gzip bytes;
gzip SHA256 db64a3ca56548ebc6c77022738fe52d71991423a985f36676ce2d98e1c2de544.
Both raw completion marker and compressed archive hash were verified.

## Deployment and recovery

Take a protected, verified current database export and source/environment preimages.
Stage the pinned merged Main tree, unchanged existing dependencies/assets and preserved overlays.
Apply only 2026_11_09_120000_create_timebank_corrections, then verify foreign keys, source sequence
columns and native permission definitions without grants. Enable correction exposure only for
this authorized installation. Use bounded maintenance and an atomic release switch; refresh
normal config/routes/views/Swagger/FPM without starting queue/scheduler or providers.
Read back trusted HTTPS, authenticated read/preview operations where existing authority permits,
new Swagger operations and zero unintended correction/effect rows. Never perform a real reset
or reversal as a deployment probe. Gateway publication, if reachable, preserves existing names,
bindings, error/idempotency forwarding and current native rights.

Before any code rollback after exposure, check correction/effect rows. Never run destructive
schema down or serve old balance readers if posted corrections exist; disable writes and use a
compatible reader/roll-forward. Keep the prior application and database backup intact.
BookStack publication/read-back and employee retrieval remain deferred, not completed.
