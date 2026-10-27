## Full API Support

Availability: implemented on Dev; tested Main promotion is authorized. Production has
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
