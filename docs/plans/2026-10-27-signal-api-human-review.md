# Signal API Human Review

Review copy of the authoritative reviewed register entry.

## HR-2026-10-27-SIGNAL-API - Full Signal Browser/API Coverage

Status: Reviewed.
Human reviewer: Svein Tore. Accepted: 2026-10-27.
Named acceptance: "Godkjenner og merge til main", in direct response to the completed
Dev handoff and its named review checklist. This explicitly authorizes Main promotion.
Production deployment and provider/worker/runtime activation require separate authorization.

Scope: approved rfc/2026-10-27-signal-full-api-parity.md; all 18 browser rows,
19 typed HTTP operations / 42 schemas, existing ingest, context, related history,
rules, settings, execution preview and failed/full retry. Owner: Codex.
The human-review gate is satisfied. Original scenario boxes are retained without
inventing individually witnessed executions; acceptance applies to the completed scope.
Existing HR-2026-07-15-002 remains Reviewed.

- [ ] Compare feed defaults/search/filter/sort/pagination and exact detail/history in web/API.
- [ ] Verify Client/Contact related history and narrow contexts do not disclose foreign records.
- [ ] Create/edit legacy/grouped rules; compare ten-action catalog, Intake prefill and saved audit.
- [ ] Read/update settings; confirm AI stays off unless explicitly changed and no credentials appear.
- [ ] Ingest record-only and controlled rule execution with exact actor/token/provider authority.
- [ ] Preview/retry failed and whole-rule rerun; confirm immutable links and no duplicate effects.
- [ ] Send stale, concurrent and repeated-key requests; revoke rights and verify denied receipt access.
- [ ] Confirm queued/failed/unresolved provider states are truthful and no unknown send is replayed.
- [ ] Review Swagger and actual employee handbook content/access; confirm deployment availability.

Expected: equivalent browser/API authority, validation, results and supported side effects.
Dev evidence: 62 Signal tests / 894 assertions, source sync 1 / 8, bounded Integration
52 / 267 and 2144 completed non-Signal cases. The independent Tripletex provider suites
are outside the bounded regression claim. All 19 successful HTTP methods match Swagger;
25 scoped PHP syntax/Pint, native MySQL uniqueness/encryption/rollback/concurrent retry,
trusted HTTPS and actual BookStack 530/449 content/access read-back pass.
See plans/2026-10-27-signal-api-verification.md/.json.

Exact migration: 2026_10_27_030000_create_signal_api_commands.php (Dev batch 31).
No broad migrate, role grants or production/runtime activation is included in this approval.
Preserve APP_KEY, encrypted commands, immutable history and the existing webhook outbox;
disable new entry points and reconcile unknown provider outcomes before retrying.
Main promotion is complete in PR #334 (be28f77d16f29e662b7b8585b27971e9c1e62438).
The merge tree equals tested candidate 6d2a4a6961b22d7a6ee44a20a8d7f7ab86d3c28b.
Candidate 626 / 6271 and final contract/source 2 / 310 pass without failures/skips.
No production deployment or runtime activation is authorized by this review.
