# Lead Intelligence Module

Lead Intelligence owns settings-driven B2B prospecting policy for Nexum PSA.

The current slice adds the foundation plus safe candidate promotion:

- CommonSetting-backed Lead Intelligence policy.
- Lead segments.
- Planned and executable research runs.
- Scan ledger.
- Source evidence.
- Contact marketing eligibility records.
- Marketing suppression entries.
- API endpoints protected by dedicated `lead-intelligence.*` abilities.
- Simple admin/tech UI.
- Segment schedule and budget controls.
- Planner command for due segment research runs.
- AI-assisted segment field drafting when an active Integration AI provider/agent exists.
- Candidate promotion from structured research payloads into Client lead candidates, Contacts, source evidence, eligibility records, and Marketing list members when settings allow it.
- `Run Now` for a segment, even when the configured schedule is in the future. It creates a queued run and dispatches a Laravel queue job for the same worker pipeline used by scheduled runs.
- AI-led discovery worker using editable planning prompts, configured source adapters, BRREG, optional web-search endpoint results, and shallow website email discovery.
- Grounded AI candidate review before automatic creation when an active Lead Intelligence AI agent exists.

## What This Slice Does Not Do

- It does not run a deep or recursive website crawler.
- It does not let AI invent companies or contacts.
- It does not use AI search results as facts by themselves. Web search can find candidate URLs, but automatic creation still requires website/registry evidence and policy review.
- It does not invent companies, contacts, roles, or email addresses.
- It does not send email.

## Settings

Settings live in `common_settings` with:

- `type`: `lead_intelligence`
- `name`: `settings`

Important policy switches:

- `enabled`
- `auto_create_clients`
- `default_client_status`
- `auto_create_contacts`
- `auto_add_to_marketing_lists`
- `allow_generic_company_emails`
- `allow_role_based_emails`
- `allow_named_work_emails`
- `never_auto_use_private_email_domains`
- `allowed_roles`
- `default_rescan_days`
- `max_pages_per_domain`
- `max_tokens_per_run`
- `max_new_leads_per_run`
- `require_source_url_for_contacts`
- `require_role_for_named_contacts`
- `minimum_company_score`
- `minimum_contact_score`
- `ai_discovery_planning_enabled`
- `ai_discovery_planning_required`
- `ai_discovery_planning_prompt`
- `ai_candidate_review_enabled`
- `ai_candidate_review_required`
- `ai_candidate_review_prompt`
- `discovery_sources`
- `brreg_base_url`
- `web_search_enabled`
- `web_search_provider`
- `web_search_endpoint_url`
- `web_search_results_per_query`

Defaults are conservative. Automation is disabled until an operator changes policy.

## Email Eligibility

`LeadMarketingEligibilityEvaluator` classifies email addresses as:

- `generic_company`: for addresses such as `post@`, `info@`, and `firmapost@`.
- `role_based`: for addresses such as `innkjop@`, `it@`, `support@`, `booking@`, and `okonomi@`.
- `named_work`: for non-private, named business addresses.
- `private`: for Gmail, Hotmail, Outlook, iCloud, and similar private domains.
- `unknown`: for missing or invalid email addresses.

Suppression entries and `Contact.do_not_email` always win. The evaluator returns policy output and can persist the result in `contact_marketing_eligibilities`, but it does not add anything to Marketing lists.

`PlanMarketingListPromotion` builds the guarded promotion plan from a stored eligibility row. `PromoteLeadCandidate` uses that plan to create `MarketingListMember` rows only when the Contact is eligible, does not require review, has target Marketing lists, and `auto_add_to_marketing_lists` is enabled. List-level Contact exclusions from Marketing are respected, so a Contact manually removed from a list is not re-added by later Lead Intelligence runs.

## Candidate Promotion API

`POST /api/v1/lead-intelligence/promote-candidate` accepts a structured company candidate and optional contacts. It is protected by `lead-intelligence.run`.

The endpoint can:

- Reuse an existing Client by organization number, website host, or exact name.
- Create a new Client lead candidate when `auto_create_clients` is enabled.
- Create or reuse Contacts when `auto_create_contacts` is enabled.
- Attach Contacts to the Client through ContactRelation and the legacy ClientUser bridge.
- Store source evidence for the company and each Contact.
- Evaluate eligibility and suppression before Marketing promotion.
- Add eligible Contacts to Marketing lists from the payload or the related segment.

It does not send email and does not perform external discovery.

## Segment Scheduling And Execution

Segments can be scheduled and paced by period target, token budget, and max run count. The planner creates queued `lead_research_runs` when a segment is due and dispatches Laravel queue jobs for them. `Run Now` does not run a separate synchronous shortcut; it creates an immediate queued run and dispatches the same job so the Laravel worker uses the same final pipeline and quality gates as scheduled automation.

Schedule controls:

- `schedule_enabled`
- `schedule_period`: `daily`, `weekly`, or `monthly`
- `schedule_weekdays`: ISO weekdays `1` to `7` for weekly schedules
- `schedule_time`
- `run_interval_days`
- `target_new_leads_per_period`
- `token_budget_per_period`
- `token_budget_unlimited`
- `max_runs_per_period`
- `next_run_at`
- `last_run_at`

Laravel's scheduler registers this planner every minute. Run the normal scheduler cron and queue worker:

```bash
php artisan schedule:run
php artisan queue:work --queue=default,economy,email --sleep=3 --tries=3 --timeout=120
```

The scheduled `lead-intelligence:plan-due-runs` command creates queued runs until the segment reaches its period target, token budget, or max-run limit, then dispatches `ExecuteLeadResearchRunJob` to Laravel's queue. The Laravel worker executes queued runs, including runs created by `Run Now`. If `token_budget_unlimited` is enabled, tokens do not block planning before the lead target or max-run limit is reached.

`php artisan lead-intelligence:run-queued-runs --limit=5` remains available as a manual fallback/backfill command for old or stranded queued rows, but it is not the normal worker path.

The segment Description is stored as `goal_prompt` in the run summary and should be treated as the human objective for future AI/discovery workers.

Run summaries include `target_new_leads`, `target_metric`, `target_progress`, `remaining_target`, `remaining_new_leads`, `target_reached`, and `completion_reason`. By default the target metric is `new_leads_created`. When a segment has Marketing lists and `auto_add_to_marketing_lists` is enabled, the target metric is `marketing_members_created`, so private or otherwise ineligible contacts do not make the run stop before the Marketing list actually grows. A completed run with `completion_reason=sources_exhausted_before_target` means the configured sources were exhausted before the run found enough eligible, contactable new leads for the active target metric.

NACE codes are Norwegian industry classification codes. They are optional; segments can use Description, geography, industries, keywords, and target roles without NACE.

## Current Discovery Worker

The current worker is AI-led, settings-driven, and auditable:

- Starts each run with the editable `ai_discovery_planning_prompt` when AI planning is enabled.
- Uses the segment Description as the human goal prompt and structured segment fields as stronger metadata.
- Produces search queries, BRREG municipality targets, role priorities, and optional seed URLs.
- Resolves configured geography to Norwegian municipalities.
- Queries Brønnøysundregistrene Enhetsregisteret for companies in those municipalities.
- Calls web search when `web_search_enabled` is true. The default `web_search_provider=ai_provider` uses the active Lead Intelligence OpenAI agent with the Responses API `web_search` tool, so operators do not need to enter a search endpoint. The active agent model must support Responses API web search. `web_search_provider=endpoint` remains available for a custom proxy that accepts `q` and `limit` query parameters and returns JSON with `results[].url`, `results[].title`, and optional `results[].snippet`.
- If BRREG finds a valid B2B candidate without a website or public email, the worker performs a company-specific web search before skipping it. This lets the system find likely official homepages and contact pages for candidates whose registry row is incomplete.
- Checks existing Clients by organization number, website domain, and exact name before website discovery and AI review. Known Clients are written to the scan ledger with `existing_client_skipped` so later runs can continue past them without repeated token usage.
- Filters BRREG units to B2B-oriented organization forms by default. For example, `FLI` voluntary associations and ideological/non-profit sector rows are skipped unless a segment override explicitly changes allowed organization forms.
- Stores BRREG source evidence for promoted companies.
- Uses BRREG `hjemmeside` and `epostadresse` when present.
- Fetches only a small number of website pages per domain, controlled by `max_pages_per_domain`, from BRREG websites, search result URLs, and explicit seed URLs.
- Extracts public email addresses from the homepage and likely contact/about/team pages.
- Sends the verified company/contact evidence to the Lead Intelligence AI agent when AI candidate review is enabled.
- Uses the editable `ai_candidate_review_prompt` to decide whether the candidate should be promoted, skipped, or held for review.
- Ignores any email address returned by AI that was not already present in BRREG or website evidence.
- Classifies generic, role-based, named work, private, and unknown email addresses through the same eligibility policy used by the API.
- Merges repeated eligibility evaluations for the same contact/email so recommended Marketing list mappings are not lost when the contact is later evaluated again.
- Respects scan ledger `next_scan_after` before rescanning a company/domain.
- Creates Clients, Contacts, and Marketing list members only when Lead Intelligence settings allow it.
- Skips Client creation when no public contact email is found, because the worker targets contactable leads rather than a passive company registry.

BRREG is not a prompt. It is a configured discovery source adapter. `discovery_sources` controls which adapters are active, and `brreg_base_url` controls the BRREG endpoint. The discovery prompt controls search planning. Web search finds candidate URLs only; the worker still fetches those URLs and extracts public evidence before promotion. The review prompt controls how verified source data should be evaluated.

## Worker Architecture

The production pipeline has one worker-owned execution path:

1. A scheduled segment or `Run Now` creates a queued run.
2. The worker loads the segment prompt, settings, budgets, scan ledger, and source adapters.
3. AI plans the discovery strategy from the segment objective and allowed sources.
4. Source adapters fetch verifiable data from BRREG, websites, AI-provider web-search results, custom endpoint results, and later additional approved providers.
5. AI reviews and scores only evidence-backed findings.
6. Promotion into Clients, Contacts, and Marketing lists happens only through the guarded promotion action.

AI must not invent leads or contacts. Any company, URL, person, role, or email used for automatic creation must be backed by stored evidence.

Segment overrides can adjust the BRREG organization-form filter through `settings_json`:

```json
{
  "allowed_org_forms": ["AS", "ENK", "NUF"],
  "excluded_org_forms": ["FLI", "STI"],
  "excluded_sector_codes": ["7000"]
}
```

## API Examples

Read settings:

```bash
curl -H "Authorization: Bearer TOKEN" -H "Accept: application/json" \
  https://example.test/api/v1/lead-intelligence/settings
```

Update settings:

```bash
curl -X PATCH -H "Authorization: Bearer TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"enabled":true,"allow_named_work_emails":true}' \
  https://example.test/api/v1/lead-intelligence/settings
```

Create a segment:

```bash
curl -X POST -H "Authorization: Bearer TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"Steinkjer daily managers","description":"Find companies in Steinkjer and identify daglig leder contacts.","geography":["Steinkjer"],"industries":[],"target_roles":["daglig leder"],"schedule_enabled":true,"schedule_period":"weekly","schedule_time":"08:00","run_interval_days":1,"target_new_leads_per_period":5,"token_budget_unlimited":true}' \
  https://example.test/api/v1/lead-segments
```

Evaluate one contact:

```bash
curl -X POST -H "Authorization: Bearer TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"contact_id":123,"client_id":45}' \
  https://example.test/api/v1/lead-intelligence/evaluate-contact
```

Promote one structured candidate:

```bash
curl -X POST -H "Authorization: Bearer TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"lead_research_run_id":10,"company":{"name":"Local Prospect AS","org_no":"999888777","website":"https://localprospect.no","shared_email":"post@localprospect.no","source_url":"https://localprospect.no/kontakt","score":90},"contacts":[{"name":"Ada Manager","email":"ada@localprospect.no","role":"daglig leder","source_url":"https://localprospect.no/om-oss","score":90}]}' \
  https://example.test/api/v1/lead-intelligence/promote-candidate
```

## Next Slice

The next planned slice should deepen discovery and review:

- Provider-specific search adapters for Norwegian B2B discovery.
- Deeper website evidence collection with stricter crawl controls.
- AI-assisted company/contact role extraction from evidence.
- Manual review queue and UI for AI `review` decisions.
