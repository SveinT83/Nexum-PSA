# Integration Hub Migration, Backfill, Rollback, and Recovery

Status: Ready for human review
Migration: `2026_08_14_220000_create_integration_hub_foundation.php`

## Change summary

The migration creates settings, capability/binding, execution-grant, durable Execution/Step,
Approval/Decision, audit, emergency-control, and explicit domain-binding tables. It adds ownership,
environment, and normalized health-observation columns to the existing `integrations` table.

Existing Integration rows receive conservative defaults: installation key `installation`, owner
scope `internal`, environment `unknown`, and health `unknown`. Existing encrypted credentials are
not decrypted or copied.

## Preflight

1. Verify the exact release and migration status.
2. Take the normal database backup and verify restore ownership.
3. Inventory existing Integration row counts, types, active/disabled states, and whether legacy
   `last_sync_at`/`is_healthy` evidence exists. Do not print `secrets`, `last_error`, or endpoints.
4. Confirm a stable installation key is configured before enabling the Hub.
5. Confirm scheduler ownership and the intended 04:00 pruning window.
6. Keep Integration Hub disabled during migration and classification.

## Apply

```bash
php artisan migrate --force
php artisan integration-hub:bootstrap
php artisan optimize:clear
```

`bootstrap` creates/updates the nine read-only descriptors, installation bindings, and protected
system actor but does not create service tokens or provider credentials. Inspect readiness with a
narrow operator token. Enable only after signing, workload, scope, and emergency controls are ready:

```bash
php artisan integration-hub:bootstrap --enable
```

## Backfill policy

There is no inferred domain backfill. A domain becomes provider-resolvable only through the
explicit `integration-hub:bind-domain` command after Client, Site, Integration, environment,
hostname, and provider reference are reviewed.

Classify existing Integrations deliberately:

- `internal`: platform-owned and not customer-specific;
- `installation`: organization-wide;
- `client`: bound to one explicit Client;
- `site`: bound to one explicit Site and its Client.

Do not infer Client/Site ownership from names, hostnames, ticket text, public DNS, provider search,
or stored endpoint values. Unknown ownership remains non-resolvable. Existing BookStack
`last_sync_at`/`is_healthy` can be adapted as legacy health evidence; missing evidence remains
unknown and configured does not mean healthy.

## Queue and scheduler behavior

The first slice performs protected reads synchronously and does not require a new queue worker.
Durable Executions survive the requesting connection. The scheduler runs
`integration-hub:prune` daily at 04:00; it removes only expired audit, grants older than the safety
window, and completed Execution metadata whose explicit retention date has passed.

There is no automatic provider retry worker in this slice. The adapter performs one bounded
in-request retry for safe transient reads. If a process stops with an Execution still `running`, a
repeated idempotency key returns `execution_in_progress` rather than making a second provider call.
An operator must inspect audit/provider evidence before classifying or superseding that execution;
do not change it to completed without verification.

Approval requests serialize on the Execution, bind its immutable plan digest, and expire. The
read-only slice exposes approval state but does not expose a provider-mutation or approval-decision
API.

## Post-migration verification

- Migration status shows the Hub migration as applied.
- `integration-hub:bootstrap` is idempotent and does not disable an already enabled Hub.
- Readiness reports the expected defined/enabled counts without key material.
- Route inventory contains only the documented 21 routes.
- Focused and affected Integration/AI tests pass.
- An identity grant/use/replay smoke succeeds as documented.
- Scheduler inventory contains one named `integration-hub.audit.prune` event at `0 4 * * *`.
- No provider call occurs until an explicit approved Plesk binding is manually tested.

## Disable before rollback

Before code or schema rollback:

1. Apply the global emergency control.
2. Stop the MCP process.
3. Revoke service and issuer tokens.
4. Preserve sanitized audit/Execution evidence required by retention or an incident.
5. Take a fresh backup and record the exact migration batch.

## Rollback

Only roll back with an approved release plan after confirming no deployed code still queries the
new tables/columns. Use the framework's exact migration rollback mechanism for this migration in
the target environment; do not roll back an unrelated batch blindly.

Rollback drops only the Hub tables and the added Integration ownership/health columns. It does not
delete existing Integration rows or encrypted credential data. It does delete Hub grants,
Executions, approvals, audit, controls, and explicit domain bindings, so export required sanitized
evidence first.

After rollback, deploy compatible code, clear caches, verify ordinary Integration and BookStack
behavior, and leave the MCP service stopped. Re-application starts with no inferred domain
bindings; restore only reviewed data from the approved backup.

## Failure recovery

- Migration failure: keep the Hub disabled, preserve the database error and migration status, and
  restore/repair through the normal reviewed database process.
- Descriptor/bootstrap failure: do not issue a token. Correct configuration and rerun the
  idempotent bootstrap.
- Scheduler unavailable: run `integration-hub:prune` manually only after a read-only count/retention
  preview and restore scheduler ownership; pruning does not affect provider state.
- Unexpected provider access: global-disable, stop MCP, revoke tokens, inspect correlation/audit,
  and follow the Plesk incident procedure.
