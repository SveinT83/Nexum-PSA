# ADR: Calendar-owned projections and durable Nextcloud synchronization leases

Status: Accepted
Date: 2026-10-22
Decision Makers: Svein Tore (RFC approval), Codex (implementation under approved RFC).
Parent: ../rfc/2026-10-22-nextcloud-calendar-automatic-sync.md.

## Context

An exposed interval had no scheduled implementation. Multiple remotes target one local Calendar,
legacy unbound copies exist and unconditional resource writes can lose concurrent changes.
Calendar, transport and manual/automatic entry points must agree on identity and ownership.

## Decision

Keep event mutation in Calendar's ApplyExternalCalendarEvent action. Existing provider fields and
metadata retain compatibility; canonical hash version 1 distinguishes the new common baseline
from historical non-comparable hashes. New projections receive the internal Work Context.
Nextcloud owns provider operations, mapping validation, due dispatch and sync/conflict evidence.

Use additive durable per-connection and per-local-Calendar leases, so manual, queued and different
connection attempts cannot race on a shared local calendar. Claims have unique fencing tokens;
queued jobs start by atomic status transition, recheck activation and mapping ownership, and have
a shorter execution timeout than their lease. Expired delivery is reconciled, not blindly retried.
Automatic activation is bound to a hash of non-secret server/principal/mapping scope.

Preserve existing remote href/UID and use If-Match for updates, If-None-Match for new deterministic
UUID resources. Persist intent before HTTP, then verify UID/content/ETag by GET. Unlinked title/time
matches remain conflicts. Retain unsupported recurrence without flattening; unsupported single
resources may project read-only. No deletion propagation or access expansion.

## Rationale

A connection-only queue lock cannot protect two connections sharing one Calendar. Cache expiry
without durable status cannot distinguish a late worker from a new attempt. ETag conditions and
identity read-back protect the provider boundary, while fresh-row comparison protects same-second
local edits. Reusing existing provider fields avoids a broad historical-data migration.

## Consequences

Existing deployments are default-off. Operators explicitly activate reviewed mappings after
deployment and can pause them independently. Retained unsupported/conflict rows make a run partial;
it is never presented as complete success. The pilot requires real bilateral/scheduler verification.
Two empty lease tables require migration before manual calendar sync is available.

## Alternatives Considered

Blindly adding a scheduler around RequestNextcloudSync amplifies cross-calendar/identity hazards
and includes unrelated user/group/file work. Cache-only locks and unconditional PUTs do not provide
the required recovery evidence. A broad recurrence or separate provider-binding data redesign is
not necessary for this conservative approved rollout and would require its own reviewed scope.

## Follow-Up

HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC remains In Review for the live pilot and production activation.
No completion of employee AI retrieval or provider round trip is inferred from tests/Main merge.
