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
