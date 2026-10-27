# Signal API Planning Baseline

Date: 2026-10-27. Authoritative runtime: /var/Projects/tdPSA.
Branch: Dev. Baseline HEAD: 36918edbcaae1ce7b1208ba34f84a3a1719f0e98.

- Runtime route discovery: 18 browser rows / 1 API HTTP operation, excluding HEAD.
- Existing Signal module is clean in git; broad unrelated Dev changes are preserved.
- Existing tests: HOME=/tmp php artisan test app/Modules/Signal/Tests.
- Result: 40 passed / 211 assertions / zero failures or skips; 44.60 seconds.
- Existing behavior includes ten action types, stable retry keys, immutable attempts,
  pending outbox recovery and no-blind-replay handling for external ambiguity.
- Fresh GitHub open Signal search found related #262 and #285; no direct Signal
  full-parity Issue was returned. No external Issue/comment was created.
- Existing approved ingest/builder/recovery plans do not cover the proposed new API.
- Related Knowledge parity is awaiting its own RFC approval, not active coding;
  this independent planning scope leaves that work and all its gates intact.

The baseline test run proves existing Signal behavior only. Planned endpoints,
Swagger contracts, commands and native/provider guards are not implemented/tested.
No application code, business data, migration, runtime configuration, queue, provider,
role grant, Main or production write was performed for this planning task.

RFC, ADR, slices, exact coverage and new planned human-review checklist were prepared
on Dev. All pre-existing Signal source hashes are in the coverage JSON and checked
again after these documentation writes. Human review and RFC approval remain pending.
No employee handbook publication is needed for an unimplemented planning proposal.
