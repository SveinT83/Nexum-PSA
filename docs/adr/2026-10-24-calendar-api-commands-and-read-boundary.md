# ADR: Calendar API query and command boundary

Status: Accepted under approved RFC 2026-10-23-calendar-full-api-parity.
Date: 2026-10-24
Owner: Codex. Approval: Svein Tore, 2026-10-24.

## Decision
Calendar owns event and administration operations; UserManagement owns own display preferences
and work plans. Nextcloud retains existing provider transport and calendar mapping ownership.
Module routes.php owns official routes; legacy api.php is only a compatibility loading entry.

Reads project existing state without permission/default/calendar initialization. Explicit admin
initialization reuses additive defaults. Token scopes and fresh actor/module/object authority
intersect. Private projection precedes filtering, sorting, counts and related-context exposure.

Local commands serialize through the actor and target rows, with durable receipts in the additive
calendar_api_command_receipts table and separate evidence in the existing domain_api activity journal. Receipts store only operation, actor, record identity and request/key
hashes, never private response snapshots. Replay reauthorizes and projects current state. New
commands require Idempotency-Key; original event CRUD retains documented keyless compatibility.
Event revisions hash persisted fields and participant/series/exception rows, avoiding same-second
updated_at collisions. Partial PUT compatibility remains explicit; unsupported fields are rejected.

Risky series/sharing/archive/provider decisions use actor-bound signed previews covering current
fingerprints and exact choices. Preview is not approval for a different actor, target or payload.
Provider commands retain connection/calendar leases, conditional ETags, durable unknown-write
intent and exact read-back. They never invoke general users/groups/files synchronization.

## Consequences
The additive 2026_10_24_120000 receipt migration is required. Keeping replay identities separate
prevents activity-log cleanup from enabling duplicate commands. Activity logging is also required; a command
rolls back if its audit receipt cannot be persisted. Removed original hard-deleted results yield
409 instead of fabricating a replay. Main, production, activation and live provider acceptance
remain distinct gates under HR-2026-10-23-CALENDAR-API.
