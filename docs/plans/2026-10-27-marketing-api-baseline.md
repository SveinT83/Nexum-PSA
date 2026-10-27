# Marketing API Planning Baseline

Status: Planning complete / RFC Draft / implementation awaiting approval.
Date: 2026-10-27. Environment: authoritative Dev, /var/Projects/tdPSA, branch Dev.

## Live Inspection

- 28 staff browser rows and 3 public tracking/unsubscribe capability rows.
- 24 API route rows / 29 HTTP operations excluding HEAD, including PUT/PATCH aliases.
- Generated storage/api-docs/api-docs.json contains zero Marketing paths.
- Missing route workflows: dashboard, authoritative unsaved preview and WordPress pull.
- Missing form/detail coverage: bounded reference choices, sender/template/AI context,
  cached content, interest summary and independent recipient queue pagination.
- API middleware declares Sanctum and abilities without explicit native Marketing
  permission/admin middleware. Controllers have no equivalent general native checks;
  current API workflow test uses a user with scopes alone. The proposed tightening
  is therefore an intentional compatibility change requiring RFC approval.
- EnsureMarketingDefaults has role/agent configuration side effects on read flows.
- Preserve the existing campaign-view contributor change and unrelated shared Dev work.

## Existing Runtime Tests

Command: umask 0002; HOME=/tmp php artisan test app/Modules/Marketing/Tests
Result: PASS, 65 tests / 811 assertions, no failures or skips, duration 58.21 seconds.
Includes evergreen progression, durable delivery invariants, Marketing web/API and
migration safety. Providers/SMTP are mocked in these tests; no real customer send,
API provider activation, worker start or migration of the Dev application occurred.
These tests establish the baseline, not completion of the requested new API support.

## Planning Deliverables And Verification

RFC, proposed ADR, sequential slices, full Markdown/JSON coverage matrix, TODO and
planned human-review checklist HR-2026-10-27-MARKETING-API. All 31 browser rows and
seven embedded/API-only groups are mapped. JSON stores exact runtime routes/actions /
middleware, current head and 69 Marketing source SHA-256 fingerprints. Independent
read-back confirmed all fingerprinted Marketing source files remained byte-identical.
GitHub open Marketing issue search returned no matches; no external write occurred.

Implementation, exact migration, typed runtime/OpenAPI tests, provider success-path
checks and actual handbook publication are future work after concrete RFC approval.
No commit/push/Main promotion/production changes or public website publication occurred.
