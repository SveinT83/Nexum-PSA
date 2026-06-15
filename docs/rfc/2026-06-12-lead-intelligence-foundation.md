# RFC: Lead Intelligence Foundation

Status: Approved
Date: 2026-06-12
Owner: Nexum PSA

## Summary

Add the first Lead Intelligence / AI Prospecting slice for Nexum PSA. This slice creates settings, persistence, API endpoints, and simple admin/tech UI for configuring prospecting policy and storing planned research work.

## Approval

Approved by the product owner request on 2026-06-12. The request explicitly scoped this as a first foundation slice and excluded real web crawling, AI enrichment, marketing-list promotion, and email sending.

## Problem

Nexum PSA needs a controlled way to research Norwegian B2B lead candidates and evaluate whether discovered contacts may be used for future marketing workflows. The automation level must be settings-driven so legal, marketing, and operational policy can change without hardcoding.

## Goals

- Store Lead Intelligence settings in the existing CommonSetting pattern.
- Add segment, research-run, scan-ledger, evidence, eligibility, and suppression data models.
- Add an evaluator that classifies company, role-based, named work, private, and unknown email addresses.
- Respect suppression entries before any contact can become marketing-eligible.
- Expose API endpoints with dedicated Sanctum abilities.
- Add simple Bootstrap tech/admin UI for settings, segments, research runs, and scan ledger.
- Document this slice and the next planned slice.

## Non-Goals

- No external web crawling.
- No BRREG/search-provider integration.
- No OpenAI/AI agent execution.
- No automatic Client or Contact creation from external sources.
- No automatic promotion into MarketingListMember.
- No email sending.

## Impact

- New module: `app/Modules/LeadIntelligence`.
- New API abilities:
  - `lead-intelligence.read`
  - `lead-intelligence.manage`
  - `lead-intelligence.run`
- New production-safe additive migrations only.
- Existing Marketing and Contact modules are not refactored in this slice.

## Risks

- Policy defaults must remain conservative because this module can later affect marketing eligibility.
- Future crawler/enrichment slices must preserve evidence and suppression checks before any automated marketing-list promotion.
- UI must be honest that research runs are planned/stored only; no execution engine exists in this slice.

## Test Plan

- Feature tests for settings API, segment CRUD, scan ledger due filtering, API ability enforcement, and evaluate-contact.
- Unit-style feature tests for email classification, suppression override, named-work role requirements, and private email handling.
- Run:
  - `php artisan test --filter=LeadIntelligence`
  - `php artisan test --filter=Marketing`
  - `php artisan test --filter=Contact`

