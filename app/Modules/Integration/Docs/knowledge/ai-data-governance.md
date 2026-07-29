AI Privacy & Coordinator Governance is available from **Admin -> Integrations**. It records the
organization's technical and governance decisions; it is not legal certification by Nexum.

## Privacy-first defaults

A new installation policy starts with AI and external processing disabled, the privacy gateway
enabled, direct external processing disabled, only `local_only` processing allowed, and `aggregate`
as the maximum data profile. Request, page, result, audit, and optional payload-retention limits are
finite.

Every update creates a revision snapshot with actor, time, and change reason. Provider, model, agent,
workload, and token settings can narrow the installation maximum but cannot widen it.

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
