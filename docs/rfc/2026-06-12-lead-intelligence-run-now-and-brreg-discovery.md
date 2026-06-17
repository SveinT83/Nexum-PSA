# RFC: Lead Intelligence Run Now And BRREG Discovery

Date: 2026-06-12
Status: Approved / Implemented
Owner: Codex

## Context

Lead Intelligence could store settings, segments, schedules, queued research
runs, evidence, eligibility, suppression, and candidate promotion. It still
needed a usable execution path so an operator could test a segment immediately
and so scheduled queued runs could do useful work.

The first executable slice must avoid hallucinated company/contact data. It
should use a public source of truth for Norwegian companies and keep the
automation governed by Lead Intelligence settings.

## Decision

Add a narrow research executor and UI trigger:

- Add `Run Now` on Lead Segment list/edit screens.
- `Run Now` creates an immediate queued `lead_research_runs` row even when
  `next_run_at` is in the future.
- Scheduled runs and `Run Now` runs must dispatch Laravel queue jobs that run
  the same final quality pipeline.
- Keep future `next_run_at` unchanged for manual runs.
- Add `ExecuteLeadResearchRunJob` for normal Laravel queue execution.
- Keep `lead-intelligence:run-queued-runs` as a manual fallback/backfill command
  for old or stranded queued rows.
- Use Brønnøysundregistrene Enhetsregisteret as the first company discovery
  source.
- Store BRREG as a configurable discovery source adapter, not as the AI prompt.
  The default adapter URL is editable through Lead Intelligence settings.
- Filter BRREG units to B2B-oriented organization forms by default. Voluntary
  associations and ideological/non-profit sector rows are skipped unless a
  segment override explicitly changes allowed organization forms.
- Use shallow website email discovery from homepage plus likely contact/about
  pages, bounded by `max_pages_per_domain`.
- Add grounded AI candidate review after source collection and before
  automatic creation. The review prompt is editable in settings.
- Add AI discovery planning before source collection. The planning prompt is
  editable in settings and may produce search queries, BRREG municipality
  targets, role priorities, and explicit seed URLs.
- Add an optional configured web-search adapter. The adapter is provider-neutral
  and calls an operator-configured endpoint with `q` and `limit`, expecting JSON
  search results with URLs, titles, and snippets.
- Ignore any AI-returned company, contact, email, URL, or fact that was not
  present in source evidence.
- Feed discovered candidates through `PromoteLeadCandidate` so settings,
  suppression, eligibility, evidence, and Marketing-list guardrails remain the
  single promotion path.
- Do not create a Client when the run cannot find any public contact email for
  the candidate.

## Implemented Worker Path

The implemented architecture is full worker-owned execution, not another
synchronous or temporary path:

- AI plans discovery from the segment prompt, settings, budgets, and scan
  ledger.
- Source adapters fetch verifiable data from BRREG, websites, explicit seed
  URLs, and an approved configured web-search endpoint.
- AI may interpret and score evidence, but may not invent companies, contacts,
  roles, email addresses, URLs, or facts.
- Any automatic Client/Contact/Marketing-list mutation must go through the
  existing guarded promotion action.

## Scope

Included:

- Municipality lookup from segment geography.
- BRREG company lookup by municipality number.
- BRREG source evidence, organization number, company name, website, email,
  industry, and municipality metadata.
- Default organization-form filtering for B2B-oriented entities.
- Public email extraction from a small number of website pages.
- AI JSON review with `promote`, `skip`, or `review` decision.
- AI JSON discovery plan before adapter execution.
- Provider-neutral web-search endpoint adapter.
- AI score/role updates only for evidence-backed emails.
- Scan ledger updates and `next_scan_after` respect.
- Client, Contact, source evidence, eligibility, and Marketing-list promotion
  through the existing policy action.
- Feature coverage for immediate execution and Marketing-list promotion.

Out of scope:

- Deep crawling.
- Built-in paid/provider-specific search integrations.
- AI enrichment of companies or contacts.
- Guessing missing contacts, roles, or email addresses.
- Email sending.

## Risks

- BRREG does not always include a website or shared company email. Those
  companies are skipped by the current executor until a later enrichment slice
  finds public contact evidence.
- Public websites vary heavily. The shallow extractor is intentionally bounded
  and conservative, so it will miss some valid contacts.
- Named work emails may require manual review unless the page context provides a
  role that matches configured allowed roles.

## Tests

- Lead Intelligence feature tests cover `Run Now` against fake BRREG/website
  responses, Client creation, Contact creation, Marketing-list membership,
  scan ledger persistence, and unchanged future `next_run_at`.
