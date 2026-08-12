# Feature Slice: Sales CPQ Core

Status: Done
Date: 2026-08-11
Parent: `docs/rfc/2026-08-11-sales-cpq-completion.md`
Owner: Codex
Human review: `HR-2026-08-11-004`

## Goal

Complete the Sales-owned CPQ scope needed by Discussion #170: customer-selectable quote options,
required acknowledgements, internal approval before risky send, immutable accepted snapshots,
lifecycle records, reusable quote templates/bundles, and explicit downstream conversion plans.

## User-Visible Behavior

Sellers can mark draft quote lines as required, optional, recommended, grouped, selected by default,
and quantity-selectable. Administrators can manage active quote templates with default customer text,
seller checklist items, option groups, catalog-backed or custom lines, and acknowledgements. Fixed
template values use controlled choices instead of internal source IDs or free-typed variables, and
template editing uses a Ticket Workflow-style list plus focused create/edit screen instead of showing
every template editor at once. Trusted automation can manage the same templates through the Sales
quote-template API.
Customers can select available options on public and portal quote pages, acknowledge required
information, decline a quote, or accept it. Risky quotes must be approved before they can be sent.
Expired sent quotes are marked expired before customer acceptance.
Sent quote changes create superseding draft revisions, accepted Ticket quotes stay immutable, later
approved additions become separate additional quote drafts, and accepted Ticket quotes can be voided
only while their downstream delivery records are still safely reversible.

## Scope

- Add quote option groups, acknowledgement records, accepted snapshots, and conversion plans.
- Add reusable Sales-owned quote template and bundle records that copy into draft quote versions.
- Extend quote line metadata for required/optional/recommended/default selection and quantity bounds.
- Add Tech UI controls for line option behavior, template application, conversion-plan status, and
  approval actions.
- Add Admin Sales settings for approval policy and quote template maintenance.
- Add Sales quote-template API endpoints for automation-assisted template creation.
- Add public and portal quote selection, acknowledgement, acceptance, and decline behavior.
- Add immutable revision/addendum behavior for Ticket-origin quotes after send or acceptance, plus
  permissioned accepted-quote voiding with audit and safe reversal.
- Add Sales lifecycle activities for viewed, declined, expired, approval decisions, templates,
  conversion-plan updates, and accepted snapshots.
- Keep existing quote send, PDF, portal, email, question, revision, and Ticket acceptance behavior
  compatible.

## Out Of Scope

- Automatic Economy order creation.
- Automatic Commercial contract creation.
- Automatic ServiceVisit, Task, Project, Storage, or Asset creation.
- Payment collection.
- Arbitrary recurrence/invoice scheduling.
- Rich text quote editor.

## Data Touched

- `sales_quote_versions`
- `sales_quote_lines`
- `sales_quote_option_groups`
- `sales_quote_acknowledgements`
- `sales_quote_acceptance_snapshots`
- `sales_quote_conversion_plans`
- `sales_quote_templates`
- `sales_quote_template_option_groups`
- `sales_quote_template_lines`
- `sales_quote_template_acknowledgements`
- `sales_activities`
- `user_management` protected system actor for Ticket quote delivery automation
- Sales settings and permission seeders

## Permissions

Existing Tech quote actions remain behind `sales.quote_manage`. Internal quote approval uses the new
`sales.quote.approve` permission. Quote-template API routes use `sales.quote_templates.read` and
`sales.quote_templates.manage` Sanctum abilities. Public and Customer Portal actions remain
token/scope protected.

Ticket `Add cost/item` starts with the existing actual-cost or Storage-reservation guard. If a
Storage item is marked as requiring accepted quote approval, or the Ticket quote cost threshold is
met, the action creates planned quote scope instead of a cost entry or reservation. The Ticket Sales
quote panel is hidden until planned scope or linked Sales context exists. Accepted Ticket-origin
quotes are processed by the protected `ticket_quote_delivery_automation` system actor: available
Storage stock is reserved, orderable shortages become draft purchase needs, and custom lines become
pending Ticket costs.

## Tests

- Full Sales feature regression suite.
- New CPQ option/acknowledgement/approval/snapshot/template/expiry/conversion-plan tests.
- Ticket quote API cross-module regression test.

## Documentation

Sales and Ticket Knowledge, TODO, human review, RFC, and this slice are updated. Sync Sales and Ticket
Knowledge after deploy.

## Done Criteria

- [x] Migration is implemented, Dev-applied, and backwards-compatible.
- [x] Draft quotes can define required/optional/recommended grouped lines.
- [x] Admins can configure CPQ approval policy and reusable quote templates/bundles through
  controlled template selectors on focused create/edit screens.
- [x] Scoped automation can create, update, and delete quote templates through the Sales
  quote-template API.
- [x] Sellers can apply active templates to draft quote versions with copied template snapshots.
- [x] Public and portal customers can select options and see customer-safe totals.
- [x] Acceptance validates option groups, required lines, quantities, and acknowledgements.
- [x] Acceptance stores an immutable snapshot and conversion plan rows.
- [x] Accepted Ticket quote lines automatically create only safe delivery records: reservations,
  draft purchase needs, or pending Ticket costs.
- [x] Sent quote revisions supersede the old public acceptance link.
- [x] Additional quote-required Ticket scope after acceptance becomes a separate additional quote.
- [x] Accepted Ticket quotes can be voided only before downstream delivery becomes irreversible.
- [x] Risky quotes require internal approval before sending.
- [x] Decline/view/expired/approval/template/conversion/accept lifecycle activities are recorded.
- [x] Focused Sales and Ticket quote tests pass on Dev.
- [x] Sales and Ticket Knowledge and human review are updated.

## Verification

- `HOME=/tmp php artisan migrate` applied `2026_08_11_140000_add_sales_cpq_core` in batch 67 and
  `2026_08_11_141000_add_sales_quote_templates` in batch 68 on Dev. Ticket/Storage quote routing
  applied `2026_08_11_142000_add_customer_quote_policy_to_storage_items` in batch 69.
- `HOME=/tmp php artisan optimize:clear` passed after migration.
- `HOME=/tmp php artisan test app/Modules/Sales/Tests/Feature/SalesModuleTest.php` passed with 25
  tests and 418 assertions, including the Admin Sales Quote Templates split-editor regression, Sales
  quote-template API regression, and superseded sent-quote acceptance regression.
- `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketModuleTest.php` passed with 122
  tests and 903 assertions, including sent-quote superseding, additional quote after acceptance,
  accepted-quote voiding, draft purchase-need reversal, and accepted Ticket quote card placement
  regressions.
- `HOME=/tmp php artisan test app/Modules/Ticket/Tests/Feature/TicketWorkflowV3Test.php` passed with
  25 tests and 278 assertions.
- `HOME=/tmp php artisan test app/Modules/Storage/Tests/Feature/StorageModuleTest.php` passed with
  23 tests and 363 assertions.
- `HOME=/tmp php artisan test` passed with 1281 tests and 10865 assertions.
- `HOME=/tmp php artisan view:cache` passed.
- `HOME=/tmp php artisan knowledge:sync-docs --module=Ticket --module=Storage --module=Sales --push`
  reported 3 chapters, 19 articles, 0 skipped, and queued the BookStack push. A follow-up
  `HOME=/tmp php artisan knowledge:sync-docs --module=Ticket --push` after the accepted-quote card
  title change reported 1 chapter, 11 articles, 0 skipped, and queued the BookStack push. The
  accepted Ticket quote auto-processing update synced Ticket Knowledge again with 1 chapter,
  11 articles, 0 skipped, and queued the BookStack push. The sent-revision/additional-quote/void
  update synced Sales, Storage, and Ticket Knowledge again with 3 chapters, 19 articles, 0 skipped,
  and queued the BookStack push.
