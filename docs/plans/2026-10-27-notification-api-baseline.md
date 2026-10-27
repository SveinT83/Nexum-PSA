# Notification API Planning Baseline

Date: 2026-10-27
Status: Read-only application investigation and documentation preparation.
Runtime: authoritative /var/Projects/tdPSA on Dev.
Head at inspection: 36918edbcaae1ce7b1208ba34f84a3a1719f0e98

Verified SSH used the dedicated current-user key, BatchMode, IdentitiesOnly and
strict host-key validation. Local Windows application code was not edited.
Runtime discovery: 14 Notification browser routes, five CustomerPortal routes,
three Notification API operations; related Workday has four existing API operations.
The matrix JSON contains full method/path/middleware evidence.

Inspected AGENTS, ai-team-process, module architecture, TODO, human-review,
RFC/ADR/slice processes, UI guidelines, Notification routes/controllers/Livewire,
models/requests/readiness, registry/docs/tests, CustomerPortal controller/context
boundary, Workday reminder controller/routes/preferences, API catalog and native
route permissions. Existing Notification/Workday dirty changes are preserved.

GitHub live search: #257 closed/completed. #205 Talk destination model, #258
Web Push activation wizard and #262 runtime reconciliation remain open.
#205 comments were fetched (none); no GitHub write was performed.
Knowledge planning is awaiting explicit RFC approval, not an active implementation;
its documents and pending review remain unchanged.

Planning artifacts: Draft RFC, Proposed ADR, Draft five sequential Feature Slices,
complete planned coverage matrix (.md/.json), TODO and planned human-review entry.
No new API/scopes/schema, migration, provider test, business data mutation, secret
setup, scheduler/worker activation, commit, push, Main or production change.

Notification baseline: 120 tests passed / 1372 assertions, no failures or skips;
121.55 seconds. Command: umask 0002; HOME=/tmp php artisan test
app/Modules/Notification/Tests --compact. Existing harness enforces SQLite :memory:.
These tests establish baseline only; they cannot certify future parity.
