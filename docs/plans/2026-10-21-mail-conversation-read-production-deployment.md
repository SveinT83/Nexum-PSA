# Mail Conversation Read: Main And Production Rollout

Date: 2026-10-21. Operator: Codex. Production verification completed 20:59:21 UTC / 22:59 Europe/Oslo.
Status: deployed and independently verified; human workflow/provider review remains In Review.
Authorization: Svein Tore explicitly requested production because Dev does not receive mail. That
instruction authorized the required scoped Main promotion and moved the remaining real-mail checks
to controlled production validation. It did not mark any manual scenario passed.
Review: HR-2026-10-21-MAIL-CONVERSATION-READ. RFC: ../rfc/2026-10-21-mail-conversation-read-command.md.

## Released Scope And Candidate Evidence

Main PR #305: https://github.com/SveinT83/Nexum-PSA/pull/305.
Application SHA: 71ce9ff10d2e04220b557e56dc206c2ddc1d883d.
Immutable tested tree: f4db8d6a21a095775b08e59a1840107c25a44703.
The merged Main tree equals that exact candidate. Its 25 selected files add the counted ordinary
conversation-read command, typed browser/API contract, tests and scoped documentation. The shared
Dev branch/head/ordinary index were independently verified unchanged. Unrelated dirty Dev work was
not promoted. Existing Main's reviewed Trash fix (PR #304) was included in the production delta.

All 105 affected tests / 2,263 assertions passed on Dev (76.80 seconds) and again in the immutable
Main export (68.39 seconds). PHP syntax and Pint passed for all 12 affected PHP files. Regenerated
OpenAPI references and scoped whitespace passed. Headless desktop/mobile/keyboard/touch checks
used synthetic mail and passed, with independent six-message read-back and zero provider work.
No test suite was executed against production mail or the production database.

## Protected Backup And Execution

Production started at application SHA 11cf40227e9dfa52747b96cdf58e5f64ae706f43.
Preflight verified both installed acknowledgement ledgers and database queue. All five existing
live/collaboration/advanced acknowledgement flags were false. Existing production Workday and
optional-provider corrections were preserved; no migrations, package or frontend build were needed.

Backup directory (outside webroot, private):
/var/www/vhosts/tronderdata.no/private/mail-conversation-release-20261021-71ce9ff/

- Fresh code/environment backup: code-before.tar.gz, 358,951 bytes; SHA-256
  a356bb80ac1036efd170969eb51bc4d2d7f4a2b18b5ecdb542155624069edbee.
- Fresh consistent database backup: database-before.sql.gz, 194,382,503 bytes; SHA-256
  e968958810902497f35adc9d72eab050e3466447e733ed235df60eb0aa89ba4d.
- The transaction-consistent dump and gzip integrity passed; the temporary credential file was
  removed. No restore rehearsal is claimed. Protected before/after/deployment and command evidence
  remain in that directory. The release package hash was verified before any application change.

After backup, maintenance and graceful queue restart waited for the one active reserved job to
finish. Forty paths were published: 38 source/documentation files exactly from Main, one generated
Swagger artifact and one additive overview merge. Production's larger TODO/human-review documents
were preserved byte-for-byte; the overview retained existing notes and received only this guide.
No unrelated production code or queued work was reset, retried, purged or drained.

Normal config/view caches, route clearing, Swagger regeneration and graceful worker restart passed.
Maintenance was lifted. No rollback was needed. The rollback path retains completed read-state,
audit and provider evidence; undoing code is not permission to restore the database.

## Independent Production Read-Back

A separate check verified all 39 non-generated paths against the locked release/merge content,
with zero mismatches. The new summary class is web-readable mode 0644 and owned by the subscription
user. A fresh application bootstrap loads it and confirms ordinary availability while the broader
acknowledgement and all other live/collaboration flags remain false. Maintenance is off.

Trusted HTTPS /up, /login, /api/documentation and /docs?api-docs.json return 200. Unauthenticated
Mail context returns 401. Authenticated context succeeds for the existing actor with three visible
accounts and reports conversation_read.available=true, scope ordinary_conversation_read,
preview_limit=100, active_account_only=true. No permission or token ceiling was widened.

Public Swagger independently shows the typed EmailConversationReadSummary, ordinary preview scope
and all existing 175 Mail operations / 109 writes. Actual cron inspection confirms schedule:run
and the queue runner every minute; its queue list includes default,economy,email. A later live
reserved job confirms normal background activity resumed; this does not prove a conversation
continuation/provider job completed.

At the live read-back there were zero ordinary conversation runs: deployment checks did not mark
real mail read or reserve provider writes. Provider Seen, a larger real continuation, recipient
mail delivery and employee OpenWebUI retrieval are not certified by these checks.

## Documentation And Remaining Review

Authoritative employee/API Knowledge sources were updated first. BookStack 495 revision 74 and
636 revision 6 were published and independently read back with production availability and the
human review In Review. Both pages retain inherited permissions and no page overrides; existing
chapter/book/internal-staff scope is unchanged. Page 636 retains its historical (Dev) title; its
body states production availability. The earlier title-only connector limitation remains separate.
Production Knowledge availability was synchronized through exact partial replacements, preserving
its existing additional notes; handbook-source-after.json records those hashes. The public-safe
website handoff states production availability but holds publication for real-mail review and
editorial approval.

Svein Tore must now verify the split button with the real six-message thread, count outside the
filter, the main single-message action, menu no-op and future-arrival exclusion. Use a controlled
personal mailbox to verify provider Seen and a larger thread to verify actual continuation; inspect
truthful partial/failure results and employee access. HR-2026-10-21-MAIL-CONVERSATION-READ stays
In Review until named human confirmation. The independent advanced acknowledgement review
HR-2026-08-16-012 remains In Review and its flag false. Selected-read/account-setup/private-live/
shared-draft/rules/compose/correlation/Not-Ticket/Ticket-Sent and older missing-mail/save checks retain
their own status; this deployment does not approve or activate them.
