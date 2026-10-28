# ADR: Explicit Paired Talk Destinations And Evidence

Status: Accepted
Date: 2026-10-28
Decision Makers: Svein Tore (audited #205 completion/Main), Codex (technical defaults)
Parent: RFC 2026-10-28-talk-notification-destinations.

## Decision

Nextcloud owns paired conversation destinations and durable no-replay receipts, using existing
service-user credentials and current room v4/chat v1 contracts. Notification owns global switch/
default and existing recipient preferences. Independent default-off Talk enablement never changes
unrelated connection features. Existing write/managed mode, native permissions, scoped API gates,
TLS and credential authority apply; no recipient/role/token grant is automatic.

No first-active fallback for an explicit target. Global default and local defaults bind a persisted
destination to its owning connection. Missing/unavailable/disabled/foreign targets fail closed.
Legacy webhook configuration remains a hidden migration compatibility path; normal tests require
typed Talk evidence and exact read-back. Preserve personal legacy target authority instead of
silently rerouting it through a different new global target.

Claim a unique hashed identity before provider I/O; preserve terminal/unknown evidence and do not
resend after ambiguity or target/binding drift. Store no message body or credential in receipts.
Room/message actions are explicit, ordinary tests are generic, API execution switches stay off.

## Consequences

New additive schema/migration, exact current-state and receipt tests, and scoped API/UI/docs work
are required. Human receipt is distinct from provider conversation-storage verification. Legacy
migration may block old targets until deliberately reconciled. Runtime kill switches cannot recall
already accepted messages. Production and an exact real-message recipient remain separate from
the explicitly authorized tested Main merge. HR-2026-10-28-TALK-DESTINATIONS stays Pending.


## Verified Implementation - 2026-10-28

Main PR #368 / 917de21a021f8f4fe4e657edf6f2b60203dfa807 independently matches the tested tree
f0fe6969c8662e36ce67f64dc7cb1df02bb6ced2. Dev 46/1389 and isolated Main 78/10459 pass.
Only the two named additive Talk migrations were applied on Dev; native rollback-only MySQL
probe and exact actual BookStack read-back passed. Existing internal catalog article visibility
and publication placement are preserved. No provider/recipient/global activation or production
deployment occurred. HR-2026-10-28-TALK-DESTINATIONS stays Pending. Issue #205 retains its
actual external integration criterion until an explicitly authorized connection/conversation is verified.
