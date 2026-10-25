# ADR: Economy Commands And Source Locking

Status: Accepted under Svein Tore's approved full Economy API RFC, 2026-10-25.
Date: 2026-10-25
Decision Makers: Svein Tore (approved target), Codex (reversible technical defaults).

## Context

Financial generation, source unlock and state changes enter through web, API, jobs and Ticket callers.
Source uniqueness alone cannot serialize draft creation, last-line removal or allocation calculations.
Token ability alone does not grant the current user's browser rights. Retries need durable evidence.

## Decision

Economy owns additive command receipts, a seeded database mutex/revision row and notification intent
records, plus unique source-to-financial-client bindings retained through line deletion/regeneration. No historic bulk backfill; existing line/allocation evidence is authoritative on first use. A single transaction lock serializes Economy writers across web/API/jobs and parent/child
operations, including generation, source deletion, lifecycle, settings and Economy export. This is a
conservative beta-scale boundary; no cache lease or second-resolution timestamp determines correctness.
Nested calls use the same transaction/row. Native locks/uniqueness are verified on Dev.
Content fingerprints include input/source dependencies and a monotonic revision; explicit new writes
require Idempotency-Key plus current If-Match/preview where applicable. Existing mutations remain
compatible when headers are omitted; supplied headers are enforced. Reauthorize before replay.
Command receipts retain exact safe results after deletion. No generic audit retention deletes them.

Notification intents are durable and deduplicated by the successful state command. Dispatch occurs
after commit, retaining processing/submitted/unknown evidence and current existing audience/preferences.
An ambiguous notification attempt is never retried blindly. API operation read-back reports actual
intent status separately from order state; submission never claims inbox delivery.

Use current shared browser policy, strict typed input and customer-safe projections. Data Exchange
keeps profile/run/file ownership; Economy adds a scoped facade and hardens the required owner API
path. No arbitrary domain reassignment, invoice provider, auto-delivery or runtime activation.

## Rationale

A database lock covers every in-process Economy caller and survives worker/process boundaries.
A single small lock trades throughput for simpler financial correctness and recoverable receipts.
No uniqueness rule is imposed across historical orders in different states. Additive migration
leaves historical economics and recorded financial customer allocations untouched.

## Consequences

Economy writers require the additive migration. Global serialization may limit throughput; measure
before replacing it with finer source/period locks. Previews expire safely when dependencies change.
Retain evidence during rollback; no destructive schema rollback once receipts/intents exist.

## Alternatives Considered

Token-only checks, per-process locks, firstOrCreate without serialization, generic status updates,
calling browser controllers from API and automatic unknown-attempt retry were rejected.

## Follow-Up

Six approved slices, full matrix acceptance, native lock/unique/HTTP verification, Knowledge/BookStack
and HR-2026-10-25-ECONOMY-API. Main/production remain separate gates.

Main implementation merge verified 2026-10-25: [PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315),
commit 33eac1a3cba47ffe57f2253847dbaaf8f9b6d4e9. Tested candidate tree matches Main. See
../plans/2026-10-25-economy-api-main-promotion.md for evidence. Production is not deployed.
