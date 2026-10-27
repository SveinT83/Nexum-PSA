# Intake Browser/API Coverage Plan

Status: Implemented and HTTP/schema verified on Dev; merged to Main in PR #340. Date: 2026-10-27. Owner: Codex.
Parent: rfc/2026-10-27-intake-full-api-parity.md.
Baseline: 17 browser rows (14 internal + 3 public), zero Intake API operations.
All rows map to implemented module routes; exact successful HTTP/schema evidence is in the JSON matrix and verification plan.

## Browser Route Map

| Browser route name | Implemented operations |
| --- | --- |
| intake.forms.show | IN22 |
| intake.forms.submit | IN23 |
| intake.forms.thanks | IN22, IN23 |
| tech.admin.system.intake.index | IN01, IN03, IN09 |
| tech.admin.system.intake.forms.create | IN02, IN04 |
| tech.admin.system.intake.forms.store | IN06 |
| tech.admin.system.intake.forms.edit | IN02, IN05 |
| tech.admin.system.intake.forms.update | IN07 |
| tech.admin.system.intake.forms.toggle | IN08 |
| tech.admin.system.intake.submissions.show | IN10, IN11, IN12, IN02 |
| tech.admin.system.intake.submissions.reviewed | IN14 |
| tech.admin.system.intake.submissions.outcome | IN15 |
| tech.admin.system.intake.submissions.link-existing | IN16 |
| tech.admin.system.intake.submissions.route-sales | IN17 |
| tech.admin.system.intake.submissions.route-ticket | IN18 |
| tech.admin.system.intake.submissions.route-task | IN19 |
| tech.admin.system.intake.attachments.download | IN13 |

## Implemented HTTP Operations

All paths use /api/v1. HEAD is excluded. Scope alternatives mean a specifically
authorized context/result branch, never automatic broad access.

| ID | Method/path | Scope | Native authority | Behavior | Test family (consolidated in IntakeApiParityTest) |
| --- | --- | --- | --- | --- | --- |
| IN01 | GET /api/v1/intake/context | intake.read | intake.view | Workspace counts, capabilities and links | IntakeApiContextTest |
| IN02 | GET /api/v1/intake/lookups/{kind} | intake.forms.write or intake.submissions.review | native management/review and related-record access | Paged eligible owners, clients, services and link targets | IntakeApiAuthorizationTest |
| IN03 | GET /api/v1/intake/forms | intake.read | intake.view | Paged/filterable forms and submission counts | IntakeApiFormsTest |
| IN04 | GET /api/v1/intake/forms/context | intake.forms.write | intake.manage | Create defaults, 10 field types, mappings, options and settings | IntakeApiFormsTest |
| IN05 | GET /api/v1/intake/forms/{form} | intake.read or intake.forms.write | intake.view or native edit context | Form detail and all ordered field definitions; manage-only context follows native edit rights | IntakeApiFormsTest |
| IN06 | POST /api/v1/intake/forms | intake.forms.write | intake.manage | Create form and fields atomically | IntakeApiFormsTest |
| IN07 | PUT /api/v1/intake/forms/{form} | intake.forms.write | intake.manage | Full validated form and ordered-field replacement | IntakeApiFormsTest |
| IN08 | POST /api/v1/intake/forms/{form}/toggle | intake.forms.write | intake.manage | Publish/pause with stale-state protection and exact result | IntakeApiCommandsTest |
| IN09 | GET /api/v1/intake/submissions | intake.read | intake.view | Paged review queue, statuses, form/client/date/search filters | IntakeApiSubmissionsTest |
| IN10 | GET /api/v1/intake/submissions/{submission} | intake.read or intake.submissions.review | intake.view or authorized action read-back | Exact submission, snapshots, normalized fields and safe related links | IntakeApiSubmissionsTest |
| IN11 | GET /api/v1/intake/submissions/{submission}/events | intake.read | intake.view | Paged audit history with authorized actor references | IntakeApiSubmissionsTest |
| IN12 | GET /api/v1/intake/submissions/{submission}/attachments | intake.read | intake.view | Safe attachment metadata without disk/path | IntakeApiAttachmentsTest |
| IN13 | GET /api/v1/intake/submissions/{submission}/attachments/{attachment} | intake.attachments.read | intake.view | Stream exact nested attachment; safe disposition, MIME and not-found behavior | IntakeApiAttachmentsTest |
| IN14 | POST /api/v1/intake/submissions/{submission}/reviewed | intake.submissions.review | intake.submission_review | Mark reviewed, preserve routed status, actor/time/event | IntakeApiReviewTest |
| IN15 | POST /api/v1/intake/submissions/{submission}/outcome | intake.submissions.review | intake.submission_review | Spam/duplicate/rejected/archive, reason and validated duplicate reference | IntakeApiReviewTest |
| IN16 | POST /api/v1/intake/submissions/{submission}/link-existing | intake.submissions.review | intake.submission_review and exact related-record access | Link Client/Contact/Ticket/Task/Sales opportunity, preserve canonical bridge | IntakeApiRoutingTest |
| IN17 | POST /api/v1/intake/submissions/{submission}/route-sales | intake.submissions.route | intake.submission_review and owning-domain policy | Sales routing including guarded optional Client/Contact creation | IntakeApiRoutingTest |
| IN18 | POST /api/v1/intake/submissions/{submission}/route-ticket | intake.submissions.route | intake.submission_review and owning-domain policy | Ticket routing, native defaults/rules/SLA/contact bridge | IntakeApiRoutingTest |
| IN19 | POST /api/v1/intake/submissions/{submission}/route-task | intake.submissions.route | intake.submission_review and owning-domain policy | Task routing, actor/form-owner fallback and matching context | IntakeApiRoutingTest |
| IN20 | POST /api/v1/intake/previews | matching eventual write/action scope | same native rights as proposed command | Non-mutating form/route/link/outcome preflight, target effects and revision | IntakeApiCommandsTest |
| IN21 | GET /api/v1/intake/commands/{command} | matching originating command scope | fresh originating authority and exact actor/token ownership | Encrypted durable command result, completed/skipped/unknown and exact read-back | IntakeApiCommandsTest |
| IN22 | GET /api/v1/public/intake/forms/{form} | public | published/legacy-active form only | Public-safe schema, conditional layout, upload rules and success text | IntakePublicApiTest |
| IN23 | POST /api/v1/public/intake/forms/{form}/submissions | public | published form; same throttle/honeypot/validation | JSON or multipart submission with generic safe acceptance and idempotency | IntakePublicApiTest |

## Embedded And Shared Behavior Acceptance

- Ordered field-array edits cover add/edit/delete/reorder, option rows, mappings, required/
  active flags, widths, all/any visibility, earlier-source restrictions and all 10 types.
- Form purpose/language/scope, lifecycle/legacy active, routing modes, owners, upload defaults/
  overrides, honeypot, success and button text all persist and read back.
- Public form and thanks share IN22/IN23; no public internal submission lookup is added.
- The current index initializes defaults when no form exists. GET API discovery must be
  read-only; move initialization to a shared explicit controlled initialization path or
  normal deployment seed with regression coverage, not hidden default creation by a GET.
- Submission detail includes raw/normalized values, immutable snapshots, safe event/actor/
  match/target metadata and attachments. Sensitive related fields follow current authority.
- Review/outcome/link/manual routes and automatic routing preserve native status, target
  defaults, Contact bridge, original files and direct-routing-before-Signal order.
- Public claims cannot become a staff-data capability. Generic acceptance does not certify
  routing, Signal execution, provider response or delivery.
- Staff claims include current actor/token authorization, preview/revision binding, one
  effect under replay/concurrency, exact completed/skipped/conflict/unknown outcomes.
- Four permission fallback corrections and legacy Client auto-create alignment are approved
  RFC decisions and are implemented with tests.
- Swagger/runtime equality, response schema validation, ability catalog, Dev native database/
  HTTPS, affected-module tests and actual BookStack content/access read-back close coverage.
- Signal rule administration stays in Signal; Telephony intake stays in Telephony.

## Evidence And Remaining Release Checks

The JSON matrix records runtime operationIds, native/token scope, passing tests and exact
schema-validated successful responses for every operation. Production deployment and
named human review remain separate gates; the old baseline alone is not parity proof.

## Verified Evidence\n\nAll 23 operationIds have schema-validated successful HTTP responses. The JSON matrix\nlinks each operation to the actual test method and success statuses. Existing baseline\nremains historical. Native HTTPS/MySQL and concurrent replay checks pass. BookStack\npage 553 contains both exact source articles with unchanged inherited access. Named\nhuman review stays Pending; tested Main promotion is authorized separately.\n