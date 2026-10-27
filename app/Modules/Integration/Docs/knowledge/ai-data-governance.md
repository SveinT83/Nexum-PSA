AI Privacy & Coordinator Governance is available from **Admin -> Integrations**. It records the
organization's technical and governance decisions; it is not legal certification by Nexum.

## Credential rejection and safe failures (Dev, 2026-10-27)

Availability: implemented on Dev under verification for Issues #269/#278; production behavior
is unchanged until a separately approved rollout. Main promotion and human review are separate.

Never paste passwords, API keys, authorization headers, tokens, recovery codes or private keys
into either AI Chats or the right-bar assistant. The shared input gate rejects supported
credential-like forms before creating a chat/title/message, pending reply or response job.
It clears rejected text from the response/component and does not save it as validation old input.
The gate detects labelled credentials, known key/token formats, private-key blocks, authenticated
URLs and common equivalent text encodings. Arbitrary unlabelled random text cannot reliably be
recognized; this is not a way to submit secrets safely.

1. Open the full **AI Chats** workspace or the right-bar assistant for the current page.
2. Write the operational question without credential values. Describe the credential type or
   approved opaque reference instead of the secret.
3. If **Credentials cannot be submitted through AI chat** appears, the suspect input is discarded.
   Reword the question; do not retry by disguising or splitting the value.
4. For an AI provider key, users with the existing AI administration permission can use the
   encrypted key field in **Admin > Integrations > AI**. For another credential type, use its
   appropriate authorized secure credential form or ask an administrator. No Vault runtime or
   broader credential access is granted by this change.

AI failures now use fixed categories/messages and a **Reference** ID. The message explains
whether to wait, retry within approved limits or ask an administrator to check policy or secure
configuration. Give support the reference and approximate time; never paste credentials or raw
provider responses. A failed provider call retains local execution/attempt identity and safe HTTP
status/category, not arbitrary upstream error code, model, request-ID or reason text. Successful
approved calls and bounded endpoint fallback retain their existing behavior.

No old chat/telemetry data is silently rewritten. An already disclosed credential requires the
ordinary approved rotation/incident procedure. Provider egress policy remains in force and
rejection does not change actor permissions, runtime enablement or external processing approval.

## Privacy-first defaults

A new installation policy starts with AI and external processing disabled, the privacy gateway
enabled, direct external processing disabled, only `local_only` processing allowed, and `aggregate`
as the maximum data profile. Request, page, result, audit, and optional payload-retention limits are
finite.

Every update creates a revision snapshot with actor, time, and change reason. Provider, model, agent,
workload, and token settings can narrow the installation maximum but cannot widen it.

## Standard AI activation

AI Settings includes a compact **Activate AI** path for normal user-triggered assistants such as
Mail AI. An administrator selects an active provider and model, confirms that the organization has
reviewed and approves that provider/model for Nexum AI features, and Nexum records the required
installation policy revision, provider governance profile, and model governance policy.

For external providers this standard path enables direct external processing with `full_context`
because Mail AI sends authorized message text. It also sets the provider and model approval to expire
after one year so the organization can review it again. The record is an admin-confirmed
governance input for Nexum enforcement; it is not legal certification by Nexum.

The advanced **Privacy & Coordinator** page remains available for organizations that need narrower
processing modes, privacy relay, shorter expiry, provider-specific legal references, agent overrides,
coordinator workloads, or token bindings.

## Processing modes

- `local_only` keeps model execution on the configured local Ollama provider.
- `privacy_relay` minimizes structured fields, removes deterministic credential and personal-data
  patterns, optionally runs a local rewrite, and post-validates before the external request.
- `direct_external` bypasses privacy washing and is available only when every governing policy
  explicitly permits it.

AI redaction reduces disclosure risk but is not anonymization. A failed rewrite or post-validation
blocks the request; original data is never used as fallback.

## Provider, model, and agent governance

External processing requires an active, approved, unexpired provider record containing purpose,
recipient, regions, DPA and subprocessor review, transfer assessment, retention and training
declarations, DPIA decision and rationale, reviewer, and review time.

Each external model needs a separate approved policy. An agent must use the same processing route
and an equal or narrower profile than its model. Missing, incomplete, rejected, disabled, or expired
records fail closed with a stable reason code.

Identified technician activity additionally requires purpose and workforce-transparency references
at installation and workload level. Default coordinator profiles expose no employee names.

## Domain-managed structured workloads

A narrowly allowlisted domain workflow may own a managed structured workload instead of exposing
the generic workload and governance setup to the user. Supplier Order Automation in Storage is the
first such workflow.

The Storage policy selects an active Storage-domain agent and reuses only its provider, model and
instructions. Integration creates one internal-model workload marked `storage_supplier_orders`,
forces `privacy_relay` and `pseudonymized` for external providers (`local_only` for Ollama), stores
no abilities, accepts no token binding and always passes the request through the central privacy
gateway and strict schema executor.

For this managed structured path, commercial values that match personal-data patterns are replaced
with opaque request-local tokens before an external call and restored only in memory after the
response. Unknown tokens fail closed. Original values, token mappings and raw model output are not
persisted.

This exception does not grant the selected agent's tools, data sources, API scopes or action
capability to Supplier Order Automation. Those capabilities are ignored for this execution path and
are tested absent from the provider request. The domain policy revision is the explicit approval for
this bounded purpose; generic coordinator and manually configured internal workloads continue to
require the ordinary installation, provider, model and agent governance chain.

Turning the feature off or selecting another agent deactivates the prior managed workload. Other
domains cannot create a managed workload merely by supplying an arbitrary marker.

## Coordinator workloads and tokens

Create a workload with purpose, mode, maximum profile, finite approval, context restrictions, and
explicit read abilities, then create its short-lived token. Coordinator tokens:

- bind one-to-one to one approved workload;
- reject `*` and every write ability;
- enforce expiry, request rate, date range, page, result, context, and optional IP restrictions;
- never grant a capability absent from either token or workload;
- can be revoked without deleting historical audit metadata.

The ordinary API-key form also defaults to no scope. Empty selection is rejected, full access needs
a separate confirmation, and existing broad keys are flagged without automatic mutation.

## Minimal coordinator API

- `GET /api/v1/worklog/technicians` requires `worklog.read`.
- `GET /api/v1/worklog/time-entries` requires `time-entries.read`.
- `GET /api/v1/tickets/stale` requires `tickets.read`.
- `GET /api/v1/tasks/stale` requires `tasks.read`.

Worklog filters are `date_from`, `date_to`, `page`, and `per_page`. Stale endpoints use
`stale_days`, `page`, and `per_page`. Policy may impose stricter limits.

Responses use deterministic workload-scoped aliases and omit natural names, customer names, Ticket
or Task titles, messages, notes, descriptions, invoice text, attachments, rankings, credentials,
and secrets.

## Audit and retention

Allowed and denied requests record metadata: request ID, workload, actor, route, profile, decision,
reason code, status, result count, duration, and a safe filter subset. Authorization headers, raw
prompts, raw responses, notes, and secrets are not written to access events.

Optional encrypted payload retention is separate and disabled by default. The scheduled
`ai.access.cleanup` job deletes expired retained payloads and metadata beyond the configured audit
period.

## Deployment and verification

1. Run migrations and the permission/role seeders.
2. Clear optimized caches and restart queue/scheduler workers.
3. Review the closed defaults before enabling anything.
4. Record provider and model governance before enabling external modes.
5. Create one narrow workload and token, test the four endpoints with non-production data, and
   confirm allowed and denied attempts in the metadata-only audit.

Do not enable direct external processing or identified technician profiles until the organization
has recorded its own legal, security, workforce, and supplier decisions.


## Historical export contract (2026-09-27)

Approved RFC 2026-09-27-controlled-history-and-commercial-time-export adds Commercial worklog
read abilities commercial.worklog.read and commercial.worklog-links.read. These are fixed
pseudonymized, workload-bound projections, not ordinary identified Commercial reads. Report worklog
now reports uncapped total and explicit truncation while retaining the per-window result limit.

CoordinatorReadScope enforces installation internal_only/selected_clients/selected_work_contexts
and workload intersections. Selected modes with empty required lists deny access. Source rows with
missing/inconsistent Work Context cannot fall back to broader access. Worklog requires report.view,
ticket.view and task.view; Commercial adds its existing view/timebank permissions. Internal-model
workloads cannot use coordinator tokens. No settings or token grants are automatically changed.
See Report and Commercial Knowledge contracts and HR-2026-09-27-WORKLOG before activation.
