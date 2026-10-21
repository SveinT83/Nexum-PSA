# Mail Trash Command Regression - 2026-10-21

Status: Implementation verified / Human Review Reviewed. Level 1 correction under the approved Mail RFC.
Owner: Codex. Reviewer/promotion owner: Svein Tore.
Human-review checklist: HR-2026-10-21-MAIL-TRASH-TARGET Reviewed by Svein Tore on 2026-10-21.
Explicit chat approval: "Godkjent". Individual scenario results were not supplied; no provider
write, permanent-delete feature, Main promotion or deployment is inferred from this approval.
Main promotion was explicitly requested by Svein Tore on 2026-10-21. This scoped promotion carries
only the verified correction, its regression tests and documentation. Production code, mailbox
contents, provider permissions and runtime switches are unchanged.

## Evidence And Cause

The user's screenshot shows a selected message with a Trash folder badge and an enabled Trash icon.
Read-only production inspection confirmed a selectable/sync-enabled INBOX.Trash folder, so the
displayed missing-folder explanation was incorrect for the selected Trash placement.

The reader checked whether any cached folder had role Trash, including the current source folder.
The shared mutation instead excluded the source folder and resolved exact provider SPECIAL-USE or
canonical leaf roles. The mismatch also exposed controls for disabled targets and stale ancestor
roles, or hid legitimate provider targets whose cached role was stale.

A separate exact UID SEARCH confirmed the selected Trash occurrence exists at the provider and
the earlier Inbox source does not. No body fetch, mark-read, message move, expunge or send was run.
The older operation 11 remains ambiguous; this correction does not rewrite its immutable evidence,
claim it succeeded, or resend the move. Supported reconciliation remains a separate owner review.

## Changes

- PerformEmailRemoteOperation rejects an already-trashed source with the accurate
  "This message is already in Trash." validation reason before ledger creation/provider contact.
  Another Trash candidate does not move an already-trashed source between bins.
- MailWorkspace uses that exact shared resolver for Archive/Trash command visibility, including
  source exclusion, selectable/sync eligibility and authoritative special-use/name inference.
- Three feature regressions cover rendered reader controls, forced stale browser action, API 422,
  preserved source/selection, no provider connection/operation, and eight Archive/Trash target cases.
- Existing provider Trash semantics, actor/mailbox authorization, durable source and no-expunge
  behavior remain. No permanent-delete feature or new API operation/scope was introduced.
- Pint cleaned existing unused imports/spacing in the touched workspace class; behavior is unchanged.
- Source Knowledge, module README, TODO, this report and the review checklist are current.

Changed files:
app/Modules/Email/Actions/PerformEmailRemoteOperation.php
app/Modules/Email/Livewire/Tech/MailWorkspace.php
app/Modules/Email/Tests/Feature/EmailRemoteOperationRecoveryTest.php
app/Modules/Email/Docs/knowledge/email-inbox-overview.md
app/Modules/Email/README-email-system.md
docs/TODO.md
docs/human-review.md
docs/plans/2026-10-21-mail-trash-target-fix.md

## Verification On Authoritative Dev

All three new regressions fail before the correction after their required folder-name fixtures were
completed: rendered invalid button, API reaching forbidden provider execution, and reader/operation
target disagreement. After the correction: 3 passed / 47 assertions.

Affected package:
HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailRemoteOperationRecoveryTest.php app/Modules/Email/Tests/Feature/EmailRemoteOperationUndoTest.php app/Modules/Email/Tests/Feature/EmailMailboxApiParityTest.php app/Modules/Email/Tests/Feature/EmailConversationWorkspaceQueryTest.php --compact

Result: 81 passed / 715 assertions, 64.95 seconds. SQLite/synthetic provider only.
The outer SSH command's shell exit wrapper had a PowerShell quoting error after PHPUnit completed;
the retained PHPUnit report independently records all 81 passing tests. A subsequent directly
executed focused run after formatting returned exit 0: 3 passed / 47 assertions, 31.61 seconds.
Pint --test passes for all three PHP files; PHP syntax and scoped git diff --check pass.
The existing shared dirty Dev checkout and unrelated contributions were preserved.

## Handbook And Release State

BookStack page 495 revision 70 was partially edited after exact-anchor dry-run and read back
independently. It originally described the correction as Dev-only, pending human review and promotion.
Revision 71 records Svein Tore's explicit approval and was independently read back on 2026-10-21;
production status stays unchanged. The existing website handoff item was updated and read back
with the same approval status while preserving the deployment/publication gate.
Page and chapter inherit access; book permissions inherit ordinary access and staff Editor
(/staff-internal) has book/chapter/page view-all. No access grants changed.
Employee OpenWebUI retrieval was not tested.

Svein Tore cleared the human-review gate with explicit approval on 2026-10-21.
Original scenario checkboxes remain as scenario descriptions, without invented execution evidence.
Main promotion is authorized; production deployment requires its own explicit request.
No migration, package install, frontend build, token widening or default-off runtime activation.
After reviewed promotion, use normal application-cache clearing and long-running-worker restart.
The exact promoted commit and final merged Main state are independently recorded in the GitHub PR.
This correction has not been deployed to production; the live site still has the old UI.

## Main Promotion Verification

The current Main preimages of all five scoped code/Knowledge/README files exactly match the saved
pre-fix Dev files. Only the eight scoped files are selected. TODO and human-review additions are
spliced into current Main instead of copying unrelated dirty Dev documentation. The shared Dev
branch, ordinary Git index, unrelated changes and in-progress reviews are preserved. The immutable
Main candidate is tested on the Dev host with synthetic configuration and isolated SQLite.

Immutable Main candidate validation on the Dev host: 81 tests passed / 715 assertions, 56.60 seconds,
PHPUnit exit 0. All five scoped implementation/source files had exact Main preimages. The selected
PHP files are byte-identical to the reviewed Dev code. No production action is included.
