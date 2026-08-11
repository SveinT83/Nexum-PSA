# RFC: Quote Billing Cadence Presentation And Customer Copy

Status: Approved
Date: 2026-07-29
Owner: Codex

## Context

GitHub issue #180 records that Sales quotes currently add one-time and recurring line values into one
customer-facing subtotal. A customer can therefore read a monthly service as part of a one-time price.
Quote versions already store customer-facing introduction, scope, assumptions, exclusions, and next-step
text, but the Tech quote editor does not expose those fields.

## Goals

- Store an explicit billing cadence on each quote line with backward-compatible classification.
- Group quote lines and calculate ex-VAT, VAT, and inc-VAT totals separately per cadence.
- Use the same presentation data in Tech preview, public quote, Customer Portal, PDF, and quote email.
- Let technicians edit the existing version-owned customer-facing text fields.
- Preserve customer copy and cadence when a sent quote is revised into a new version.

## Non-Goals

- Replacing the quote version's existing aggregate accounting fields or acceptance value semantics.
- Adding arbitrary recurrence schedules, proration, contract periods, or invoice generation.
- Introducing a rich-text/HTML editor; this slice keeps safe multiline plain text.
- Rewriting customized administrator email templates without an exact default-template match.

## Current Behavior

Quote lines store a section and downstream conversion type but no explicit customer billing cadence.
All lines are rendered in one table with one combined subtotal and VAT total. Existing explanatory fields
render in public, portal, and PDF views but cannot be updated through the quote editor, and all appear
before the line table.

## Proposed Change

Add `billing_cadence` to quote lines with supported values `one_time`, `monthly`, `quarterly`, and
`annual`. Existing `recurring_contract` or `monthly_services` lines are backfilled to `monthly`; other
lines become `one_time`. Older callers that omit the field continue to infer the same defaults.

A single Sales presentation service groups lines and calculates ex-VAT, VAT, and inc-VAT totals for each
cadence. Every customer surface uses that data and labels values as NOK, NOK/month, NOK/quarter, or
NOK/year. No combined customer-facing grand total is shown as though it were one-time. Internal aggregate
version totals remain available for established forecast, acceptance, and downstream behavior.

The Tech editor receives a version-details form for title, expiry, introduction, solution/scope,
assumptions, exclusions, and next steps. Introduction and solution text render before the groups;
assumptions, exclusions, and next steps render after them. Existing draft-copy behavior already preserves
these version fields and will also copy line cadence.

Default quote email templates receive separate presentation summary and customer-copy variables. An
upgrade migration updates only the exact old default template, leaving customized templates unchanged.

## Impact Analysis

- Module ownership: Sales; Email default-template data is affected at the Sales delivery boundary.
- Data: one new indexed string on `sales_quote_lines`; conditional default email-template update.
- UI: Tech quote editor/preview, public quote, Customer Portal quote, and PDF.
- Email: additional renderer variables and cadence-safe default content.
- Permissions: existing quote-manage and customer/public quote access remain unchanged.
- Queue: existing quote email job only; no worker contract or scheduler change.

## Data And Migration Plan

The migration adds `billing_cadence` with a `one_time` default, backfills known recurring lines to
`monthly`, and conditionally updates the unchanged default `sales_quote_send` template. Rollback removes
the column and does not attempt to reverse template content. Existing quote text columns require no
migration.

## Testing Plan

- One-time and monthly example renders as separate 5,200 and 551/month groups with separate VAT totals.
- Quarterly and annual labels/totals use their own intervals.
- Existing lines infer/backfill cadence without request breakage.
- Customer text saves only on editable drafts and is visible before/after lines on every surface.
- A revised version preserves all customer text and line cadence.
- Email rendering receives cadence-safe summary and copy variables.
- Existing quotes without optional text render successfully.

## Documentation Plan

Update Sales Knowledge, the quote/email operational notes, TODO, Feature Slice index, and human review.

## Open Questions

None. The issue defines the required surfaces and the user approved implementation of all open issues in
this Codex task.

## Approval

Svein Tore approved implementation of all remaining open issues in the Codex task on 2026-07-29.
The implementation is limited to the behavior specified in GitHub issue #180.
