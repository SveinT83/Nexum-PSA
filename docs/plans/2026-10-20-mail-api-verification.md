# Mail Full API Parity: Dev Verification

Status: Deployed and independently verified in production on 2026-10-21.
Human review: HR-2026-10-20-MAIL-API Reviewed by Svein Tore on 2026-10-21.
Authoritative implementation: /var/Projects/tdPSA, branch Dev.
The original Dev handoff performed no commit/promotion/deployment. Svein subsequently requested
Main and production explicitly; application release 11cf40227e9dfa52747b96cdf58e5f64ae706f43 is now deployed.
Operational evidence: 2026-10-21-mail-api-production-deployment.md.
Approved scope: RFC 2026-10-20-mail-full-api-parity, all six feature slices.
Architecture: docs/adr/2026-10-20-mail-browser-api-operational-parity.md.

## Delivered Behavior

The Mail domain now has **175 HTTP operations** across **144 path patterns**:
**66 reads and 109 writes/actions**. This adds **102 APIs** to the 73-operation baseline.
Every currently implemented browser read/write/action/admin outcome is covered. This includes
fenced drafts/send and shared drafts, source downloads, rules/publication, AI/signatures,
Ticket handoff, account/config/template administration, delegation/emergency access,
unread handover, provider folders and operator import/reconciliation/canonical workflows.

Ordinary Delete in Mail/Inbox and the API requests provider Trash. No local source deletion or
IMAP expunge fallback remains in that ordinary action. Rule/tag success and optional provider
Archive outcomes are separate in the selected-placement Spam action. Shared/delegate personal
read state remains local; personal-owner provider mirroring reports its own outcome.

All domain registrations live in Email/routes.php. Email/api.php only bridges the existing loader.
Browser and API reuse shared queries/actions/administration services. Token abilities are explicit
ceilings, with current active-human, domain permission and mailbox checks on each request.
New token scopes do not widen existing tokens. Default-off runtime switches remain unchanged.

## Maintained Contracts

- Email/Support/EmailApiDocumentation.php: full OpenAPI operations, parameters, request bodies,
  resource projections, errors, token ceilings, binary and multipart definitions.
- Email/Docs/email-api-contracts.json: every method/path/action/scope/request contract.
- Email/Docs/mail-browser-api-parity.json: all 72 browser routes mapped to APIs, and 155 public
  Livewire declarations registered for future parity review.
- Email/Tests/Feature/EmailApiDocumentationContractTest.php: route/schema/scope/interface drift
  and sensitive operator-metadata projection checks.
- Email/Tests/Feature/EmailMailboxApiParityTest.php: positive/negative HTTP, writes and read-back.

The JSON/Markdown gap matrix retains the original analysis and records all 44 groups implemented.
Collaboration, private live transport and advanced acknowledgement preserve their existing gates.
Unimplemented future Mail product Orders were not converted to placeholder endpoints.

## Automated Evidence

| Verification | Result |
| --- | --- |
| Full Email/Tests suite | 812 passed, 9,259 assertions; 8 optional MariaDB contracts skipped |
| Affected Mail/Integration/Ticket package | 231 passed, 3,256 assertions; overlaps the full Mail suite |
| Latest adapter/Swagger/interface/projection package | 22 passed, 1,485 assertions |
| Earlier source/raw/Ticket-boundary package | 26 passed, 1,277 assertions |
| PHP syntax | 70 touched/new Mail files passed the final syntax check |
| Formatting | Explicit task files passed Pint; unrelated dirty paths were not batch-formatted |
| Generated OpenAPI | 175/175 Mail operations, zero missing/extra; schema references resolve |
| Trusted HTTPS | Swagger UI and its actual /docs?api-docs.json both 200; no TLS bypass |
| Authentication smoke | New Mail context endpoint returns 401 without authentication |

The latest checks cover source grouping without acknowledgement; cross-user/revoked/token denial;
sanitized signature write/read-back; scoped folder preferences and queued refresh; both browser/API
Trash; template writes/audit and account secret masking; owner delegation/revoke; exact unread
handover and idempotent apply; exact attachment/raw bytes and source mismatch; connection-tested
account activation; personal rule creation; frozen canonical preview and confirmation; partial
provider read-state failure; atomic repeated Ticket creation; non-Inbox selected Spam; safe complete
operator progress and current source authority even with partial display relations.

Tests used the project's guarded isolated SQLite runtime. The eight skipped tests explicitly need
isolated MariaDB opt-in settings: live publication, Order 12/13 migrations, provider path,
provider reconciliation (three cases), shared drafts and unread schema repair.
No real IMAP/SMTP write or recipient inbox delivery was claimed.

## Live Schema Read-Only Preflight

81 Email models were surveyed. 79 existing model tables have all declared fillable fields.
Two historical models point to absent tables: EmailMailDraftLock and
EmailMailUserConversationAcknowledgement. Neither is consumed by the registered Mail HTTP/runtime
graph; the first appears only in the otherwise-unreferenced EmailPresenceService.
Their cleanup is recorded in TODO. Current drafts/presence/acknowledgement use installed models.
No migration or business-data repair was performed for this parity implementation.

## Documentation And Handbook

Authoritative guide: app/Modules/Email/Docs/knowledge/mail-api-operations.md.
Email overview, module README, approved RFC, six slices, parity matrix, TODO and this report updated.

BookStack:
- https://doc.tronderdata.no/books/nexum-psa/page/mail-api-operations-dev (page 636)
- https://doc.tronderdata.no/books/nexum-psa/page/email-inbox-overview (page 495)
- https://doc.tronderdata.no/books/nexum-psa/page/api-management (page 449; Mail addendum)

The Mail guide/overview were independently read back with draft=false and rendered HTML; guide content matched
the source after line-ending/trailing-whitespace normalization. Page/chapter inherit existing
permissions; staff-internal Editor has book/chapter/page view-all. No access was expanded.
The connector rejected valid non-empty text; the established own-user personal BookStack credential
path was verified and used. Dev's built-in BookStack Integration record is absent and was not changed.
An employee OpenWebUI retrieval is a separate pending human verification.

Approval-status update (2026-10-21): the guide, overview and API Management Mail addendum were
updated through the BookStack partial-edit connector after exact-anchor and stale-version preflight.
Independent reads verified all three approval texts, published state and rendered HTML in book 339.
The earlier connector rejection describes the implementation publication, not this status update.
No employee access permissions were changed.

## Human Review Checklist: HR-2026-10-20-MAIL-API

This is the human-review entry in authoritative docs/human-review.md. Svein Tore approved the delivered Mail API scope on 2026-10-21 in chat: "Godkjenner".
The explicit human approval clears this Mail API scope review. Automated tests did not supply the
approval. Individual manual scenario results were not reported; the unchecked scenarios below
retain the original checklist without inventing execution evidence. Independent Mail runtime
reviews and Svein's ownership of Main promotion/deployment/release remain unchanged.

- [ ] Compare browser and own-token lists, folders, filters, conversations and exact downloads.
- [ ] Shared/delegated personal read state, personal-owner provider outcome, opened history and revoked access.
- [ ] Controlled real-provider Trash in browser Inbox and API, retained source and no expunge fallback.
- [ ] Compose/reply/reply-all/forward, attachments, preview/send, interrupted-send recovery and Sent read-back.
- [ ] Folder mutations, refresh progress, personal preferences, Spam rule and optional Archive outcome.
- [ ] Personal/admin rules, draft conflicts/publication, Smart Inbox batch/prefill, AI and signature.
- [ ] Ticket create/link/suppression, source retention and TD-number correlation.
- [ ] Account/grants/test/activation, global configuration and template administration.
- [ ] Delegation/revoke, separate emergency powers/expiry and exact unread handover.
- [ ] Operator preview/start/cancel/status, cursor rebaseline and canonical review/apply/rollback.
- [ ] Swagger and handbook in the intended user/employee scope; employee OpenWebUI retrieval.

## Deployment And Remaining Limits

Svein owns commit/promotion/deployment. No new migration, package, build, scheduler or runtime switch
is introduced. After approved promotion, regenerate Swagger, refresh normal Laravel route/config/view
caches and restart relevant long-running workers. Existing Mail schema/runtime reviews remain
independent. Real provider behavior and delivery require their named checks; production application
availability is independently verified in the linked 2026-10-21 deployment report.

The working copy was already broadly dirty, including another Ticket/Task API stream.
Existing work was preserved; the shared ability catalog was extended additively.
Transient audit/build/test scripts and rollback snapshots are outside the repository under /tmp.


Public website handoff: one public-safe Mail item added and read back in the fixed Content and
Visibility queue. Status remains klar for vurdering. Svein Tore approved the Mail API scope review
and production deployment/availability were verified on 2026-10-21. Website editorial publication
still requires its own approval. No website or social publication was performed.

Final production documentation: BookStack 636, 495 and 449 production-status updates were read back
published with rendered HTML. The legacy Dev title on 636 remains a title-only follow-up in TODO;
its body is production-current. The unpublished public website handoff is updated with verified
production status. No employee OpenWebUI retrieval, real provider write or delivery claim is added.
