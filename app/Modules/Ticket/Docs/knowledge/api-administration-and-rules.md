Availability: Dev implementation verified on 2026-10-20; scoped Main merge approved by Svein Tore on 2026-10-28. Production deployment and BookStack publication are separate, unverified steps for this API delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks; Main approval does not mark them performed.

## Prerequisites and discovery

1. Open **Admin > System > Integrations > API Management** using your own active account and select the required operation scopes.
2. Open the API documentation/Swagger surface and GET /api/v1/tickets/capabilities. Both token grants and current user permissions apply, including cumulative grants and object/runtime checks.
3. Compare the matching **Admin > Settings > Tickets** page using the same account. Existing Admin/Superuser permission fallback and delegated operator permissions remain the browser authority. An API token does not grant a new role or field permission.

## Ticket settings, catalogs and assignment

1. GET /api/v1/ticket-settings and the relevant /context/{catalog} choices. PUT the explicit /default-email-account, /solution-policy, /portal-policy, /quote-cost-policy or /merge-policy endpoint using its Swagger payload. Use tickets.settings.read/update and ticket.manage_settings. Read back settings and /history.
2. GET, POST, PUT or DELETE the /ticket-queues, /ticket-types, /ticket-statuses and /ticket-priorities families. Use tickets.catalog.manage for writes. Native active/default, protected-system and reference-delete rules remain in force. Inspect Swagger for the exact supported catalog fields.
3. GET/PUT /me/ticket-assignment for your own availability and assignment preferences. Administrative technician profiles and assignment rules have their separate administration routes/scopes. Rule creation requires a stable request key; read back the exact created rule before retrying.
4. GET /ticket-settings/documentation-requests to inspect global documentation follow-ups. Optional statuses are open, draft_ready, in_review, approved, publishing, publication_failed and completed. The page is bounded and does not approve or publish Knowledge.
5. Administrative writes append content-free receipts to the existing activity journal in the same transaction. A failed audit write rolls back the setting/catalog mutation.

## Workflow definitions and operational results

1. GET /api/v1/ticket-workflow-catalog for persisted requirements, triggers, actions and available notification templates. GET never seeds templates.
2. GET /ticket-workflows and /ticket-workflows/{workflow}. POST a full workflow or PUT its mutable draft, including states, transitions, requirements, action/assignment/commercial policies and escalation paths. Every state needs a stable key, name and valid status; exactly one is initial.
3. POST /ticket-workflows/{workflow}/publish with the publication scope and ticket.workflow_publish permission. Published versions remain immutable. A new successful publish creates a new version, so read back after an uncertain result before publishing again.
4. POST /migration-preview, then POST /migrations with the selected target version and Ticket IDs. Native automatic placement and migration permission apply. Compatibility state_mapping input does not override automatic placement.
5. Ticket operational commands retain their existing endpoints: transitions/escalations, close outcome/reason, time/cost/planned lines, quote creation/send/acceptance/void, review requests/decisions and evidence classification. The native domain actions retain workflow, Sales, Storage and current actor checks.
6. GET /tickets/{ticket}/workflow-reviews, /workflow-evidence and /quote-versions, or a nested record, to read back persisted results. Quote read scope is tickets.quotes.read; workflow read scope is tickets.workflow.read. These projections exclude arbitrary requirement snapshots, disk paths and public-access tokens.

## Ticket Rules

1. GET /api/v1/ticket-rules/readiness before editing. Read the current capability/runtime reasons; exposing an endpoint does not enable Rules or Custom Field runtime switches.
2. GET /ticket-rules/context/{catalog}, the rule, its draft and versions with tickets.rules.read. Native rule administration requires ticket.manage_rules.
3. POST /ticket-rules/drafts or PUT /ticket-rules/{rule}/draft with the full supported definition and current checksum/fence data. Unsupported source-owned nodes are returned as preservation placeholders; retain their order and source checksum. Do not replace them with new arbitrary values.
4. POST /ticket-rules/preview to run the native synthetic preview. It does not activate delivery or writes. Preview and publication require their independent current user permissions.
5. POST /ticket-rules/{rule}/publish with the documented stable request key and draft/publication tokens. PUT /enabled sets the explicit enabled state. DELETE uses the native archive boundary. Read back the exact version/rule and receipt; keys are actor-bound and conflicting replays fail.
6. The /ticket-rules/legacy family preserves legacy conditions/actions behind the existing authority fence. It is not a bypass around schema-2 publication, target permissions or disabled gates.

## Execution history and retries

GET /api/v1/ticket-rule-executions and nested execution details expose the native sanitized evidence. POST the exact failed action's /retry only with tickets.rules.retry and ticket.rule_retry; current Ticket view and provider publication authority are rechecked. Full rerun has a separate default-off gate, preview token and full-rerun permission. Repeating an exact committed command returns its durable receipt; an unresolved external attempt requires reconciliation before any resend. Never equate queued, accepted, provider-reconciled and customer-delivered states.
