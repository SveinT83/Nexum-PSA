# Feature Slice: Work Context Documentation, Risk, And Calendar Alignment

Status: Done
Date: 2026-07-02
Parent: `docs/rfc/2026-07-01-work-context-organization-scope.md`
Owner: Codex

## Goal

Align modules that already have explicit internal/client or organization-owned behavior with the
shared WorkContext contract.

## User-Visible Behavior

Documentation and Risk keep their existing internal/client user workflows, but records now carry an
explicit Work Context for API output and future reporting. Calendar events are treated as internal
organization work unless a later Calendar-specific slice introduces client-linked event ownership.

## Scope

- Add `work_context_id` to Documentation, Risk assessments, and Calendar events.
- Backfill Documentation internal records and client-scoped records where the existing scope is
  unambiguous.
- Backfill Risk `client_id = null` assessments to the default internal context and client-scoped
  assessments to their Client context.
- Backfill Calendar events to the default internal context.
- Expose Work Context in API resources and list filters where the domain already exposes API data.
- Document Knowledge as visibility-controlled content, not WorkContext-owned work.

## Out Of Scope

- No NexumRelationship routing.
- No customer-facing Calendar event sharing.
- No migration of ambiguous Ticket or Task historical null-client records.
- No conversion of Knowledge `public` visibility into a Work Context.

## Data Touched

- `documentations.work_context_id`
- `risk_assessments.work_context_id`
- `calendar_events.work_context_id`
- Documentation, Risk, Calendar, Knowledge, and WorkContext documentation.

## Permissions

Existing module permissions and Sanctum abilities remain unchanged.

## Tests

- Documentation API creates and lists internal/client WorkContext records.
- Risk UI/API creates internal/client WorkContext records and filters by context.
- Calendar UI/API creates events with internal WorkContext and exposes it.
- Knowledge tests continue to prove visibility and client scope remain separate from WorkContext.

Verification on 2026-07-02:

- `HOME=/tmp php artisan migrate`
- `HOME=/tmp php artisan test app/Modules/Documentation/Tests/Feature/DocumentationModuleTest.php app/Modules/Risk/Tests/Feature/RiskSystemTest.php app/Modules/Calendar/Tests/Feature/CalendarModuleTest.php app/Modules/Knowledge/Tests/Feature/KnowledgeArticleTest.php`
- Covered again by `HOME=/tmp php artisan test` with 671 passing tests and 4955 assertions.
- Knowledge docs were synced with the final Work Context batch: 12 chapters, 39 articles, `skipped = 0`.

## Documentation

Update affected module Knowledge docs and WorkContext docs, then sync to BookStack.

## Done Criteria

- Migrations run cleanly.
- Module tests pass.
- API payloads expose `work_context_id` without removing existing fields.
- Knowledge docs clearly distinguish visibility from WorkContext.
