# Marketing API Verification

Status: Done On Dev / Merged to Main in PR #350 / production unchanged.

- All 28 staff browser routes, three public capabilities and seven embedded/API-only
  groups are mapped; 37 API rows / 42 HTTP operations / 62 typed schemas.
- Actual successful HTTP payloads cover 42/42 methods and validate against published
  frozen request/result/error contracts, including consumed fields and nullability. Public tracking/unsubscribe semantics are preserved.
- Dev Marketing: 88 passed / 2018 assertions, no failures/skips. Candidate affected
  batch: 488 passed / 7469 assertions, no failures/skips; final Marketing candidate: 88 / 2018, no failures/skips.
- Exact additive migration installed only on Dev. Native MySQL uniqueness, two-connection
  row locking, ciphertext/decryption and rollback probes pass with no retained test rows.
- Seven trusted HTTPS reads, anonymous 401, exact Swagger and disposable-token cleanup
  verified. Dev's existing data has no campaign record for an extra real-data detail probe;
  campaign detail/queue/content/preview/commands have full synthetic feature-test coverage.
- Three authoritative Knowledge article bodies read back exactly, internal/published.
  Full guide published/read back in actual BookStack 449 using its supported append tool.
  Page/chapter inheritance, book overrides and Editor/Viewer global view rights verified;
  permissions unchanged. New page creation has the existing connector pattern-validation
  defect. Native pending_push is not publication evidence. OpenWebUI retrieval untested.
- Shared Dev's first broad snapshot had 24 non-Marketing failures from concurrent
  UserManagement/Contact/Email work. Nine selected failures now pass (51 assertions)
  after contributor fixes. Contact is now merged upstream and passed in the later isolated batch of 233 tests /
  3687 assertions. Another affected batch passed 406 / 10418. The earlier shared Dev
  snapshot is not a current whole-Dev pass claim.
- API provider execution remains default-off. No real SMTP/AI/WordPress calls, production
  rollout, worker activation, role grants or human scenario verification are claimed.

See JSON for exact evidence. Human-review checklist HR-2026-10-27-MARKETING-API is
Pending. Svein Tore explicitly authorized Main promotion with that status; it remains
a production/release gate. Preserve APP_KEY, receipts and historical delivery claims.

Final integration against Main e6a7c8ad: Marketing, Relationship and Lead Intelligence
pass 168 tests / 5292 assertions, no failures/skips. All 33 scoped PHP files pass
syntax/Pint; all 49 changed paths match the explicit promotion manifest.

Main PR #350 merged as 1fc7bd9d88cef74ccede84cb1f067afcb8eac079. Fresh GitHub
fetch confirms merged tree cef2b084d278543cfc381dcddd989260b7ddcf61 equals the
final tested candidate tree. Production remains unchanged; manual scenarios Pending.
