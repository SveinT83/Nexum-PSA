# Feature Slice: Email/Ticket Correlation Conflict Triage

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
Owners: Email / Ticket
Human Review: `HR-2026-08-16-014`

## Goal

Make inbound and synchronized Email-to-Ticket correlation deterministic, additive and reviewable.
An active primary conversation relationship and verified header chain are strongest; the existing
exact `TD-...` key remains a supported fallback. When valid signals disagree, Nexum records a
sanitized conflict and captures nothing until an authorized human selects or rejects the candidates.

## Dependencies

Order 13 first-class relationships/captures/events must be stable. This slice also uses current Mail
placement authorization, Ticket Work Context policy, canonical source identities, provider
reconciliation, rule idempotency and private invalidation. It does not change provider state or send.

## Correlation Decision

For one exact source message/account/conversation, evaluate only normalized, installation-local
evidence:

1. an active primary relationship for the current Email conversation;
2. `In-Reply-To`/`References` matching a captured inbound or reserved/reconciled outbound Message-ID
   in a primary relationship;
3. an exact active or retired Nexum `TD-...` key in the subject; and
4. account/conversation/placement and explicit approved system-policy facts.

Sender, domain, participant overlap, normalized subject similarity, AI output, Ticket assignee,
Client/Contact guesses and canonical duplicate grouping may suggest review context but can never
auto-capture.

One unambiguous strong target still requires current account policy, active Ticket, audience and
idempotency checks. Zero target remains unrouted. Two different targets, several valid Ticket keys,
several header-linked primaries, legacy multiple-primary evidence, account/audience incompatibility,
or a retired alias disagreeing with an active key creates a conflict. Signal precedence must not
silently choose between disagreeing valid targets.

## Additive Data Model

Reserve migrations after order 13, currently `2026_08_16_139000` and `140000`.

Add `email_ticket_correlation_conflicts`:

- opaque UUID, source account/conversation/message/placement, trigger (`inbound`, `provider_sync`,
  `manual_recheck`, `migration`), algorithm/policy versions and immutable source fingerprint;
- status `pending`, `resolved`, `rejected`, `stale`, `superseded`, or `cancelled`, safe reason code,
  candidate count, selected relationship/Ticket where resolved, actor/system policy, decision reason,
  created/resolved timestamps and lock version;
- unique active identity for source message+algorithm+fingerprint so retries cannot create another
  queue item.

Add `email_ticket_correlation_candidates`:

- conflict, candidate Ticket and optional relationship/conversation, evidence kinds and hashes,
  relative strength, current policy result and safe denial code;
- no mail/Ticket body, subject, sender, recipients, attachment names, private paths or raw header.

Add append-only `email_ticket_correlation_events` for detected, re-evaluated, inspected, resolved,
rejected, stale, superseded and capture-result facts. Inspection events identify resources and actor
but never copy content.

Down refuses while any conflict/candidate/event or linked capture decision exists.

## Detection And Re-Evaluation

Extract one pure evidence normalizer and one policy-aware decision action used by inbound storage,
provider-originated imports, explicit recheck and rule paths. It returns `unrouted`, `route`, or
`conflict`; controllers/jobs cannot implement their own precedence.

Detection persists the conflict before any Ticket capture. It does not set `email_messages.ticket_id`,
create a relationship/capture/Ticket message, change unread/provider state, run downstream Ticket
automation, send a notification containing content, or expose the candidate list broadly.

Every inspection and resolution re-reads the current source fingerprint, account/conversation,
primary relationships, headers, keys, aliases, Ticket Work Context, audience and actor permissions.
Changed evidence makes the old conflict stale and runs a fresh deterministic evaluation; it never
applies a stored decision to a different source state.

## Triage UI And Actions

Provide a permission-filtered Admin/technician queue with safe counts and reason labels. Listing
requires the dedicated `email.ticket_correlation_triage` permission but does not itself grant either
Mail content or Ticket content.

Inspection is available only when the actor currently has ordinary Mail View for the exact source
account/placement and normal Ticket View for every candidate shown. If only a subset is authorized,
the conflict is non-enumerating/unavailable rather than leaking hidden candidate count or identity.
Record the inspection event before returning content and fail closed if audit persistence fails.

Resolution options are:

- select an existing compatible active primary relationship/Ticket;
- create a primary/reference/transfer proposal through order 13 actions where no safe relationship
  exists;
- reject all candidates and leave the message unrouted; or
- cancel because the source/Ticket is no longer eligible.

The actor supplies a bounded reason. Resolution locks the conflict, source, relationships and
Tickets, reauthorizes both domains, records selected and rejected candidate evidence, then invokes
the exact idempotent relationship/capture action. A failure creates no partial relationship/capture
and leaves the conflict retryable with safe evidence. Repeating the decision returns the same result.

Rejecting a conflict does not create a permanent broad sender/subject suppression rule. Any
conversation-level not-Ticket suppression belongs to order 15 and must be explicitly selected.

## Bounds And Privacy

- At most 20 candidates and 100 evidence references per conflict; overflow becomes
  `too_many_candidates` and requires narrower/manual relationship repair without truncation.
- Queue pages use capped pagination and safe metadata only. Search cannot search mail body or hidden
  Ticket data.
- Background evaluation handles one message per item with a bounded header/key set; queue payloads
  contain IDs and fingerprints only.
- Candidate Message-IDs and Ticket keys are hashed in operational evidence; exact values appear only
  inside a fully authorized inspection and are not copied to logs/notifications.
- Private invalidations announce only opaque conflict/status projection versions to authorized users.

## Permissions And API

Add `email.ticket_correlation_triage` conservatively to Admin/Superuser, not Tech by default. Actual
resolution additionally requires ordinary Mail View, Ticket View/Update, and relationship-specific
authority. Break-glass does not allow Ticket correlation or resolution.

Add read/resolve API abilities only if bound to explicit accounts and Ticket Work Contexts. Index,
show, inspect, resolve, reject and recheck routes use opaque IDs and non-enumerating binding. API
resources contain safe reason/evidence categories and permitted target metadata only, never raw
headers/content, credentials, private paths or canonical IDs.

## Out Of Scope

- Guessing by sender/subject/AI, automatic relationship transfer, Ticket merge aliases (order 15),
  outbound compose (order 16), portal publication (order 19), provider mutation or auto-reply.
- Treating `TD-...` as authorization or replacing it with headers.
- Broad suppression based on a rejected candidate.

## Tests

- Primary relationship, header chain without Ticket key, exact key without headers, retired alias
  compatibility and no-signal unrouted behavior.
- Header-vs-key, multiple headers, multiple keys, legacy multiple primary, account/audience and
  alias/active-key conflicts create one durable conflict and zero captures.
- Duplicate delivery/job/redelivery, concurrent detection, changed fingerprint/evidence, stale/
  superseded reevaluation and candidate/relationship deletion.
- Full/partial/no Mail and Ticket access, break-glass, disabled actor, route/API non-enumeration,
  inspection audit-before-content and audit failure.
- Resolve/reject/proposal paths, reason validation, lock races, permission loss, failure after target
  write, idempotent capture and rejected-candidate evidence.
- Bounds/overflow, sanitized rows/logs/notifications/API, opaque invalidations and no hidden counts.
- No personal/provider read change, provider call, send, portal publication, tag/classification copy,
  Ticket automation or Signal loop before an exact successful capture.
- Migration/down guards, routes/OpenAPI, queue/cache/view and affected Email/Ticket/Portal/Rule tests.

## Documentation And Operations

Update Email/Ticket README and Knowledge, correlation troubleshooting, API/OpenAPI, TODO, completion
index and `docs/human-review.md`. Deploy additive migrations/permissions with `umask 0002`, clear
caches, rebuild group-writable views and restart Email/default workers. Deployment never resolves an
existing conflict or captures mail automatically; first Dev validation is preview/read-only.

`HR-2026-08-16-014` remains Pending until a named reviewer checks every precedence/conflict case,
authorization and no-leak behavior, inspection audit, resolve/reject/idempotency, stale evidence,
queues, sanitized output and unchanged provider/personal/portal state.

## Done Criteria

- [ ] One shared decision action implements additive precedence and conflict creation for every
  inbound/provider/manual path.
- [ ] Valid disagreeing signals never route silently; triage is bounded, non-enumerating, audited and
  currently reauthorized.
- [ ] Resolution invokes the exact first-class relationship/capture boundary once and records all
  selected/rejected evidence without partial side effects.
- [ ] Tests, migrations, permissions, API/UI, docs, deploy checks and `HR-2026-08-16-014` are complete
  while human review remains Pending.
