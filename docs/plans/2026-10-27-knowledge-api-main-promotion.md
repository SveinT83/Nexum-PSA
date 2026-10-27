# Knowledge API Main Promotion

Date: 2026-10-27. Authorized by Svein Tore: "Ja og merge til main".

PR #331: https://github.com/SveinT83/Nexum-PSA/pull/331
Merge commit: 5dab62d56c7663368de11d0f5961e08d7e269bd3
Tested and merged tree: c134e03cc5cb5c1be5193f15993b5906d62f4102

A fresh fetch independently confirmed exact equality with the scoped tested candidate.
The implementation manifest is 50 files. Candidate verification passed 326 tests / 5246
assertions, followed by 2 final Swagger/source compatibility checks / 694 assertions.
33 scoped PHP syntax/Pint checks pass; all 44 HTTP operations match typed responses.
Existing article operation IDs are preserved. Main's other Swagger operations/schemas
are unchanged. Concurrent Signal code/scopes/guide additions were excluded from this
candidate and remain preserved on shared Dev. The shared Dev index was unchanged.

HR-2026-10-27-KNOWLEDGE-API, HR-2026-09-04-001 and HR-2026-09-03-003 remain Pending
for manual scenarios; Svein explicitly authorized this Main merge without claiming
those checks were performed. Production deployment/release/provider activation is
separately gated. The exact additive migration ran only on Dev, batch 32; no retained
probe commands or temporary read tokens. Native MySQL/HTTPS and actual handbook read-back
are recorded in knowledge-api-verification.md/.json.

No Main implementation action remains. This follow-up updates only documentation and
coordination status. Application tests were not rerun for this status-only reconciliation.
