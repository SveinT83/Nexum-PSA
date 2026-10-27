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

Enabling a segment does not run by itself. Automation starts when the segment schedule is enabled, Laravel's scheduler runs every minute, and the normal Laravel queue worker is running:

```bash
php artisan schedule:run
php artisan queue:work --queue=default,economy,email --sleep=3 --tries=3 --timeout=120
```

The scheduler runs `lead-intelligence:plan-due-runs` every minute. The planner command creates queued `lead_research_runs` for due segments and dispatches `ExecuteLeadResearchRunJob`. `Run Now` creates a queued run immediately without changing the future `next_run_at`, then dispatches the same Laravel queue job. The worker then executes the same pipeline used by scheduled automation.

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


## Full API Support

Availability: implemented on Dev and merged to Main in PR #343. Production has
not received this change. HR-2026-10-27-LEAD-INTELLIGENCE-API remains Pending for manual
scenarios and production release. API-origin external execution is disabled by default.

### Prerequisites and permissions

1. Use an active internal Nexum account with `sales.lead_manage`.
2. In **Admin > API Management**, grant only the required token abilities:
   `lead-intelligence.read`, `lead-intelligence.manage`, and/or `lead-intelligence.run`.
   A token does not grant its owner new user permissions.
3. Send the bearer token, `Accept: application/json` and JSON request bodies. Keep the
   token in an approved secret store. Never paste it into prompts or handbook examples.
4. Read `GET /api/v1/lead-intelligence/context` for defaults, schedule periods, weekdays,
   selectable Marketing lists/segments, AI availability and current execution gates.
   Optional `lead_segment_id` returns the selected segment. Reads do not create agents.

### Supported operations

All paths below are relative to `/api/v1`.

| Method | Path | Result |
| --- | --- | --- |
| GET | lead-intelligence/context | Form defaults, allowed values, lists and capabilities |
| GET / PATCH | lead-intelligence/settings | Read/update the same policy used by the Settings page |
| GET / POST | lead-segments | List/filter segments or create a segment |
| GET / PATCH | lead-segments/{segment} | Read/edit a segment, including schedules and budgets |
| POST | lead-segments/ai-draft | Suggest editable segment fields; does not save a segment |
| POST | lead-segments/{segment}/run-now | Create one immediate queued research run |
| GET / POST | lead-research-runs | List/filter runs or create a draft/queued run |
| GET | lead-research-runs/{run} | Exact status, summary and first 100 source evidence records |
| GET | lead-research-runs/{run}/evidence | Paginated evidence with matching Client/Contact IDs and names |
| GET | lead-scan-ledger | Filter scan history by domain, organization number, status or due_only |
| POST | lead-intelligence/evaluate-contact | Persist eligibility for the authorized contact/client/evidence |
| POST | lead-intelligence/promote-candidate | Preview or apply evidence-backed candidate promotion |
| GET | lead-intelligence/operations/{command} | Read your original token's saved command outcome |

There are 17 HTTP operations, excluding HEAD. Lists accept `page` and `per_page`
(1-100). Segment search uses `q` and `enabled`; runs use `status` and `lead_segment_id`.
Swagger at **/api/documentation** describes concrete fields, responses and errors.

### Create and edit a segment

1. Open **Lead Intelligence > Segments** to compare the existing workflow, or read API
   context. Create with `POST lead-segments`, including `name` and the desired filters,
   schedule, target roles, Marketing list IDs and budget values.
2. Use a unique `Idempotency-Key` header of 8-200 characters for every write/action.
   Save the response's `operation_id`, exact record ID and `ETag` when returned.
3. Read `GET lead-segments/{segment}` before editing. Send its exact `ETag` as `If-Match`
   with `PATCH lead-segments/{segment}` to prevent a stale change overwriting new data.
4. Omitted PATCH fields retain their values. Nullable fields can be cleared. Unlimited
   tokens clears the token budget; disabling scheduling clears `next_run_at`.
5. Read back the saved record. Invalid weekdays, missing list IDs, unknown fields and
   conflicting public/legacy `_json` field aliases return validation errors.

### AI draft and research runs

1. AI drafting requires an available Integration agent/provider and normal external-AI
   permission. Submit `prompt` and optionally `lead_segment_id` to `lead-segments/ai-draft`.
2. Review the returned fields and save them explicitly with the segment API or form.
3. For **Run Now**, call `lead-segments/{segment}/run-now` with a stable request key.
   It uses the same worker as the browser and scheduled runs. A queued response only
   confirms local acceptance, not completed discovery or successful promotion.
4. Poll the exact run and evidence endpoints for status/results. Source evidence shows
   which Client/Contact records were linked. A different run's evidence stays separate.

API-origin external AI/discovery/provider work requires the deployment owner's explicit
activation of `LEAD_INTELLIGENCE_API_EXTERNAL_EXECUTION`. A 409 reports that gate when
it is off. This is separate from Lead Intelligence's own enabled/auto-create settings.
The worker rechecks the initiating user's current native rights and original token
before effects. Revoked rights or token stop the API run. Scheduled native runs keep
their established policy. No new worker/scheduler is started by enabling API access.

### Evaluate and promote a candidate

1. Use existing Contact and Client IDs. An unrelated contact/client/evidence pair is
   rejected. Eligibility respects `do_not_email`, suppression and private-address policy.
2. Submit a structured `company`, optional `contacts`, run ID and Marketing list IDs
   to `lead-intelligence/promote-candidate` with `dry_run: true` first. This does not
   create business records; preview `client_created` describes the predicted outcome.
3. Review the preview, then submit the exact intended apply request with a unique key.
   Read back returned Client, Contact, evidence and membership IDs.
4. Existing company matches are reused. Client creation and numbering remain owned by
   Clients; active Tripletex customer sync uses one persisted UUID and provider authority,
   never a caller-supplied customer number. Unknown provider attempts need reconciliation.
5. For a company without an organization number, only explicit `company.billing_email`
   becomes the invoice address. A shared/company/contact email is not inferred as billing.
   Existing data is not backfilled. Marketing exclusions and suppression still win.

This workflow does not send email or add new crawling/review/deletion products.

### Retries and troubleshooting

- Repeat the same actor/token/key and identical request only to retrieve its saved
  outcome. Different input with the same key returns 409. A revoked permission/scope
  also blocks saved-result access. Receipts are encrypted and survive process restarts.
- 412: read the current record, review the change and submit with its new ETag/key.
- 422: correct the named fields or relationship. 428: supply a valid request key.
- A command in `running`, `dispatching`, `dispatch_pending` or `unknown` requires checking
  the exact operation and run/provider state. Do not issue a new key to repeat uncertain
  AI charges, provider customer creation or queue submission.
- If dispatch is uncertain, retain the exact `dispatch_run_id`. An operator must reconcile
  the queue/run before using the native stranded-run recovery command; it is not an
  automatic blind retry. Queued rows are not proof that workers are running.
- Scheduled discovery additionally needs a verified external every-minute scheduler runner
  and the existing worker. `schedule:list` alone does not prove those services are healthy.
- A 503 requires the release owner's exact API migration/cache verification. Do not run
  blanket migrations or change role/provider policy to bypass an error.

Manual employee scenarios and actual provider delivery remain separate checks from
automated tests. See the verification record for the precise Dev/Main/release status.
