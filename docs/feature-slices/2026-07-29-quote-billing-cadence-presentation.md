# Feature Slice: Quote Billing Cadence Presentation And Customer Copy

Status: Done
Date: 2026-07-29
Parent: `docs/rfc/2026-07-29-quote-billing-cadence-presentation.md` and GitHub issue #180
Owner: Codex

## Goal

Make every customer-facing Sales quote distinguish one-time and recurring money while allowing the
technician to maintain the explanatory copy stored on the immutable quote version.

## User-Visible Behavior

Quote lines appear in separate one-time, monthly, quarterly, and annual groups. Each group has its own
ex-VAT, VAT, and inc-VAT amounts with an explicit interval. Technicians can edit safe multiline quote
copy, and customers see it consistently in public, portal, PDF, and sent-email presentation.

## Scope

- Add and backfill line billing cadence.
- Add backward-compatible cadence inference for existing callers.
- Build one shared presentation contract and use it on all quote surfaces.
- Add an editable draft-only customer-copy form.
- Keep pre-line and post-line explanatory text in consistent positions.
- Extend default quote email variables and safely upgrade the unchanged default template.
- Preserve cadence and customer copy when revising a quote version.

## Out Of Scope

- Arbitrary recurrence rules, proration, billing dates, or invoice scheduling.
- Changing immutable sent/accepted versions.
- Treating a recurring period as part of the one-time payable total.
- Replacing plain text with trusted customer-authored HTML.

## Data Touched

`sales_quote_lines.billing_cadence` is added and existing known recurring lines are classified as
monthly. Existing Sales quote version text columns and Email template rows are reused.

## Permissions

Only users who can manage Sales opportunities/quotes reach the Tech update route, and only draft quote
versions are mutable. Existing public token and Customer Portal scope checks remain in force.

## Tests

- Cadence inference, validation, grouping, totals, and labels.
- Draft-only customer-copy update and revised-version preservation.
- Tech, public, portal, PDF, and email presentation consistency.
- Existing no-text and legacy-line compatibility.

## Documentation

Update Sales Knowledge/API or delivery notes, TODO, Feature Slice index, and human review. Sync the Sales
Knowledge module after deployment.

## Done Criteria

- [x] Migration and compatibility inference are implemented.
- [x] Shared grouping/totals feed every quote surface.
- [x] Customer-facing text is editable and placed consistently.
- [x] Email uses cadence-safe summary and customer copy.
- [x] Focused and complete Sales tests pass on Dev.
- [x] Knowledge and human review are updated.
