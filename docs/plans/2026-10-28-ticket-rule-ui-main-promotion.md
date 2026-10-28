# Ticket Rules UI Main Promotion (#285)

Date: 2026-10-28
Authority: /var/Projects/tdPSA, branch Dev.
Authorization: Svein requested "Fix alt og merge til main" for the audited issues.
Human-review checklist: HR-2026-10-27-OPS-RULES-COORDINATOR, In Review.

The completed prior session fixed saved-draft summaries, the distinction between draft and
published authority, independent Workflow activity/publication status, and actionable publication
gate messages. These are tested UI corrections under the existing approved Rules/Workflow work.

All six code/view/test files and both affected guides match that session's final owned hashes.
The shared TicketSettingsController also contains a separate 371-line pre-existing difference
against Main. Promote only the owned eager-load of publishedVersion in workflows(), leaving
unrelated CRUD/API refactoring outside this package. The five other files have only the verified
#285 changes against Main.

Fresh authoritative Dev regressions and an isolated latest-Main Rules/Workflow matrix are required.
Keep existing published definitions, runtime capabilities, protected actor authority, workflow
versions, queue scope and production state unchanged. No migration, asset build or worker restart
is required for these UI corrections.

Issue #285 stays open for its explicit production match/non-match acceptance and manual UI review.
The workflow Implementering was already published in production; this promotion does not activate
the inactive Rule or its runtime capabilities. No production cutover or deployment is inferred.

## Fresh Verification

Authoritative Dev's four Issue #285 regressions pass: 4 tests / 30 assertions.
The exact isolated latest-Main candidate passes 76 tests / 798 assertions across the complete
Rules builder, Workflow v3, Workflow action contracts, creation parity, retry/full-rerun and
customer-notification suites. Existing runtime/authority gates remain independent and default-off.
Three owned PHP syntax checks and scoped whitespace pass. No full application suite or manual
desktop/mobile acceptance was performed.


## Completion And Independent Read-Back

Verified Main: PR #374, df33c58613b8537ec7b83489d727bcec8226b228; independent exact tested tree
9acb897481fab116b75296af90a5d8a9f40732fe. Fresh Dev 4/30; Main Rules/Workflow/customer-notification
matrix 76/798, all passing. Scoped Pint, three PHP syntax checks and whitespace pass.
Only one publishedVersion eager-load line was promoted from the shared controller; unrelated
CRUD/API refactoring and Dev branch/index/staged Storage work were preserved.
Actual BookStack owned Main sections 426/35 and 532/24 were independently read back with inherited
access preserved. Source guides identify Main availability and retain production warnings.
Issue #285 remains open. Four proven implementation criteria are checked; explicit production
cutover/match/non-match and manual desktop/mobile criteria remain unchecked. Comment 6461039725
and the updated Issue body were independently read back.
HR-2026-10-27-OPS-RULES-COORDINATOR remains In Review. Historical HR-2026-08-25-013 stays Reviewed.
No production setting, published Rule/version, capability, worker, cache or recipient was changed.
No full application suite or manual visual acceptance is claimed.
