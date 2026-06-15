# Lead Intelligence Overview

Lead Intelligence is the settings-driven prospecting foundation for Nexum PSA. It is designed for Norwegian B2B lead discovery. The current slice stores policy, segments, research runs, scan ledger rows, source evidence, suppression entries, contact marketing eligibility decisions, and a safe promotion path into Clients, Contacts, and Marketing list members.

## Current Capabilities

- Admin settings under `/tech/admin/settings/lead-intelligence`.
- Segment management under `/tech/lead-intelligence/segments`.
- Planned research runs under `/tech/lead-intelligence/runs`.
- Scan ledger under `/tech/lead-intelligence/scan-ledger`.
- Segment scheduling and budget controls for pacing future research runs.
- AI-assisted segment field drafting when an active Lead Intelligence AI agent is configured.
- `Run Now` on segments for immediate Laravel queue dispatch even when the next scheduled run is in the future.
- AI-led discovery worker using editable planning prompts, configured source adapters, BRREG, AI-provider or endpoint web-search results, and shallow website email discovery.
- Grounded AI candidate review before automatic creation when an active Lead Intelligence AI agent exists.
- API access through dedicated `lead-intelligence.read`, `lead-intelligence.manage`, and `lead-intelligence.run` abilities.
- Contact marketing eligibility evaluation through `POST /api/v1/lead-intelligence/evaluate-contact`.
- Candidate promotion through `POST /api/v1/lead-intelligence/promote-candidate`.

## Policy Model

Settings control how automatic the future agent may be:

- Whether the module is enabled.
- Whether Clients may be created automatically as lead candidates.
- Which lead status should be used for automatically created Clients.
- Whether Contacts may be created automatically.
- Whether eligible Contacts may be promoted to Marketing lists.
- Which email types are allowed: generic company, role-based, named work.
- Whether private email domains are never auto-approved.
- Required target roles.
- Rescan intervals, page limits, token limits, and lead limits.
- Source URL and role requirements.
- Minimum company and contact scores.
- AI discovery planning policy and prompt.
- AI candidate review policy and prompt.
- Configured BRREG and web-search source adapters.
- Schedule period, run time, weekdays, run interval, target new leads, token budget, unlimited token mode, and max runs.

Defaults are conservative. The module stores policy and decisions but does not send email.

## Scheduling

Enabling a segment does not run by itself. Automation starts when the segment schedule is enabled, the planner command runs from Plesk Scheduled Tasks or cron, and the normal Laravel queue worker is running:

```bash
php artisan lead-intelligence:plan-due-runs
php artisan queue:work --queue=default --sleep=3 --tries=1 --timeout=1800
```

The planner command creates queued `lead_research_runs` for due segments and dispatches `ExecuteLeadResearchRunJob`. `Run Now` creates a queued run immediately without changing the future `next_run_at`, then dispatches the same Laravel queue job. The worker then executes the same pipeline used by scheduled automation.

`php artisan lead-intelligence:run-queued-runs --limit=5` remains available as a manual fallback/backfill command for old or stranded queued rows, but it is not the normal worker path.

The run summary includes the segment Description as `goal_prompt` plus structured metadata such as geography, industries, NACE codes, keywords, excluded keywords, and target roles.

Run summaries also include `target_new_leads`, `target_metric`, `target_progress`, `remaining_target`, `remaining_new_leads`, `target_reached`, and `completion_reason`. By default the target metric is `new_leads_created`. When a segment has Marketing lists and `auto_add_to_marketing_lists` is enabled, the target metric is `marketing_members_created`, so private or otherwise ineligible contacts do not make the run stop before the Marketing list actually grows. If a run completes with `sources_exhausted_before_target`, the worker used the configured sources but did not find enough eligible, contactable new leads for the active target metric.

For example, a weekly segment can target five new leads per week, run every day at 08:00 until the target is reached, and then wait until the next weekly period. Token budget can be a fixed period limit or unlimited until the target or max-run limit is reached.

NACE codes are industry classification codes. They are optional; a segment can rely on Description and other structured fields when NACE is unknown.

## Eligibility Evaluation

The evaluator classifies email addresses into:

- `generic_company`
- `role_based`
- `named_work`
- `private`
- `unknown`

Suppression and opt-out rules are checked before any marketing eligibility can be true. Suppression entries can match by email, domain, Client, or Contact. A Contact marked `do_not_email` is not eligible.

Named work addresses can require both evidence with a source URL and a matching allowed role. Private domains are blocked from automatic eligibility when `never_auto_use_private_email_domains` is enabled.

## Candidate Promotion

The promotion endpoint accepts a structured company candidate and optional contacts. It can:

- Reuse an existing Client by organization number, website host, or exact name.
- Create a new Client lead candidate when `auto_create_clients` is enabled.
- Create or reuse Contacts when `auto_create_contacts` is enabled.
- Attach Contacts to the Client through ContactRelation and the legacy ClientUser bridge.
- Store company and contact source evidence.
- Evaluate marketing eligibility with suppression and opt-out checks.
- Add eligible Contacts to the segment or payload marketing lists when `auto_add_to_marketing_lists` is enabled.

Suppression always wins. Suppressed contacts can be stored as Contacts/evidence, but they are not added to Marketing lists. Marketing list-level Contact exclusions also win; if an operator removes a Contact from a specific list, later Lead Intelligence runs do not re-add that Contact to the same list.

## Current Discovery Worker

The current worker is deliberately auditable:

- Starts with AI discovery planning when enabled.
- Uses the segment Description as the goal prompt and structured segment fields as metadata.
- Produces search queries, BRREG municipality targets, role priorities, and optional seed URLs.
- Resolves segment geography to Norwegian municipalities.
- Queries Brønnøysundregistrene Enhetsregisteret for companies.
- Calls web search when enabled. The default provider uses the active Lead Intelligence OpenAI agent with Responses API web search, so operators do not have to enter a technical endpoint. The active agent model must support Responses API web search. A custom endpoint adapter remains available for self-hosted search proxies that accept `q` and `limit`.
- Performs company-specific web search before skipping a BRREG candidate that has no website or public contact email, then scans the discovered website/contact pages for real contact evidence.
- Checks existing Clients by organization number, website domain, and exact name before website discovery and AI review. Known Clients are recorded in the scan ledger with `existing_client_skipped`, which prevents repeated token use on the same known company until it is due for rescan.
- Filters BRREG rows to B2B-oriented organization forms by default. Voluntary associations such as `FLI` and ideological/non-profit sector rows are skipped unless the segment overrides allowed organization forms.
- Uses BRREG organization number, company name, website, public email, industry, municipality, and source URL as evidence.
- Fetches a small number of website pages per domain, controlled by `max_pages_per_domain`, from BRREG websites, search result URLs, and explicit seed URLs.
- Extracts public email addresses from home, contact, about, team, and employee pages when linked from the homepage.
- Sends only verified BRREG/website evidence to AI review when enabled.
- Uses the editable AI review prompt in settings to decide whether a candidate should be promoted, skipped, or held for review.
- Ignores AI-returned email addresses that were not present in source evidence.
- Uses the existing eligibility policy before any Contact can be added to a Marketing list.
- Merges repeated eligibility evaluations for the same contact/email so recommended Marketing list mappings are not lost when the contact is evaluated again.
- Stores scan ledger rows and respects `next_scan_after` before rescanning.
- Skips Client creation when no public contact email is found, because this executor targets contactable leads.
- Does not send email.

BRREG is a configured discovery source adapter, not the AI prompt. Operators can edit `discovery_sources`, `brreg_base_url`, web-search provider settings, the AI discovery prompt, and the AI review prompt from Lead Intelligence settings.

## Worker Architecture

The production pipeline has one worker-owned execution path:

- Scheduled segments and `Run Now` both create queued runs.
- The worker loads segment prompt, settings, budgets, scan ledger, and source adapters.
- AI plans discovery from the segment objective and allowed sources.
- Source adapters fetch verifiable data from BRREG, websites, AI-provider web-search results, custom endpoint results, and later additional approved providers.
- AI reviews and scores only stored evidence.
- Promotion into Clients, Contacts, and Marketing lists happens only through the guarded promotion action.

AI must not invent leads, contacts, roles, email addresses, URLs, or facts. Automatic creation requires stored evidence.

## What Is Not Implemented Yet

- No deep website crawler.
- No deep search agent that can create facts directly from search results. AI web search can find candidate URLs, but automatic creation still requires fetched page evidence, suppression checks, and review policy.
- No email sending.

## Next Planned Slice

The next slice should deepen discovery while preserving this policy layer:

- Additional provider-specific search adapters with evidence storage.
- Deeper website evidence collection with stricter crawl controls.
- AI-assisted company/contact role extraction from evidence.
- Manual review queue and UI for AI `review` decisions.
- Use WordPress and other content integrations as future context sources when configured.
