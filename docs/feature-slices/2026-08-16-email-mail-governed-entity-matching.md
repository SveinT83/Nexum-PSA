# Feature Slice: Governed Entity Matching And Permission-Filtered PSA Context

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Related ADRs:
- `docs/adr/2026-08-11-email-owned-mail-client-domain.md`
- `docs/adr/2026-08-11-email-mailbox-access-and-rule-authority.md`
Owners: Email / Contact / Clients / WorkContext / Integration
Human Review: `HR-2026-08-16-025`

## Goal

Show useful Contact, client, site and other authorized PSA context beside a Mail conversation without
turning an email address, display name, domain, AI guess or Ticket link into identity authority. Exact
deterministic evidence is preferred; ambiguity remains visible for manual choice; governed AI may
rank already-authorized candidates but cannot discover or disclose inaccessible records.

## Dependencies And Existing Boundary

Orders 5, 6, 8, 10, 13-15 and 21 must be stable: canonical/source identity, current mailbox and
provider binding, private invalidation, deterministic rules/API parity, durable Ticket relationships
and attachment security. Contact remains owner of people, addresses and relationship provenance;
Clients/WorkContext remain owners of client/site visibility. Email stores only source-scoped match
proposals and accepted links. Integration owns any AI workload and data-egress decision.

Current inbound classifiers directly select the first `ContactEmail` row for an address. That is
acceptable only as legacy routing compatibility, not as the finished reader-context identity
contract: duplicates, aliases, ownership changes and inaccessible Work Context can otherwise choose
or reveal the wrong record.

## Matching And Authorization Contract

Matching proceeds in bounded stages and never broadens the candidate cohort after authorization:

1. Reauthorize the actor's exact Mail source placement/account and capture its current projection
   version. Break-glass content access never grants PSA context.
2. Ask Contact for normalized exact-address candidates and their provenance. A verified exact unique
   address is strong evidence, not automatic Work Context authority.
3. Resolve each Contact relation through Clients/WorkContext-owned permission scopes. Drop rather
   than count or label candidates the actor cannot access.
4. Add explicit durable Ticket relationship/client/site evidence when it agrees with current
   authorization. A Ticket reference is context, not mailbox or Contact authority.
5. If zero or several authorized candidates remain, offer bounded manual search through the owning
   domain's permission-filtered query. Never search after pagination or in PHP.
6. Governed AI may rank only the already-minimized authorized candidate IDs/labels and bounded Mail
   facts allowed by the active data policy. It may not query PSA tables, create a Contact, select a
   hidden candidate or write a match.

Display-name/domain similarity, signatures, free-text names, authentication results and AI output
are weak evidence. They may explain/rank a suggestion but cannot make an automatic accepted match.
Several exact address rows are ambiguous until Contact ownership is repaired or a human selects one.

## Additive Data Model

Reserve migrations after order 24, currently `2026_08_16_162000` and `163000`.

Add `email_entity_match_runs`:

- conversation/source message, account, requester or system actor, trigger and schema/policy version;
- frozen source fingerprint, authorized candidate cohort hash/count, provider/AI provenance and cost;
- status `queued`, `running`, `completed`, `no_match`, `ambiguous`, `stale`, `blocked`, `cancelled` or
  `failed`, with bounded safe reason/error and timestamps;
- no body, snippet, subject, address, entity label, private path, AI prompt/response or hidden count.

Add `email_entity_match_candidates` and append-only events:

- run, candidate type and opaque target ID, evidence classes/hashes, rank/confidence band and current
  authorization result;
- state `suggested`, `accepted`, `rejected`, `superseded`, `stale` or `inaccessible`;
- actor, action, source/candidate fingerprint, policy/schema/version and timestamps;
- a single active accepted match per conversation and entity role, while preserving history.

Accepted links reference owning-domain records but do not copy labels, addresses or private context.
Soft deletion, ownership/permission changes and merges invalidate through durable events/projection
versions. Down refuses while accepted links/events exist. Migrations do not run matching or AI.

## User Experience

The selected Mail reader shows a compact **PSA context** section after the message body. It is absent
when no current authorized context or available action exists. Exact accepted context is labelled as
linked; suggestions show evidence class and require explicit selection. Ambiguity says that more than
one authorized match exists without revealing hidden alternatives.

Manual search is scoped to the selected conversation and owning-domain authorization. Selection
preview names the exact Contact/client/site and source conversation before confirmation. Correction,
unlink and replacement are explicit, audited and do not edit Contact data. A separate Contact-owned
flow may propose ownership repair or new Contact creation; this Mail slice never auto-creates one.

The UI/API returns non-enumerating denial after access loss, removes stale context through private
invalidation and rechecks Mail plus PSA authority when opened or accepted. Counts, timing, status and
AI availability must not reveal inaccessible mailbox or Work Context existence.

## AI Governance

AI is optional and default-deny. The workload is read-only, tool-free and schema-validated through
Integration's governed executor. Input contains the minimum authorized candidate facts and bounded
sanitized Mail facts; non-clean attachments, remote images, raw source and unrelated thread/account
content are excluded. Output can only rank supplied opaque candidate keys with reasons from an
allowlist.

Mailbox policy, Work Context policy, candidate-domain policy, AI-agent availability and installation
egress policy are intersected. Any timeout, malformed output, cost limit, policy drift or revocation
falls back to deterministic/manual matching. Provenance, schema, prompt-policy version, cost and
staleness are recorded without retaining content-bearing prompt/response. AI never accepts a match.

## API And Cross-Domain Actions

Add Email-owned source-scoped endpoints/actions for list/refresh/accept/reject/unlink and manual
candidate search. Token abilities intersect ordinary mailbox View and each target domain's current
read/link ability. IDs are opaque/non-enumerating, result sets are bounded and acceptance uses an
idempotency key plus exact source/candidate fingerprints.

Rules, Smart Inbox, Signal and Ticket may consume only an accepted current match through a public
read action. They cannot read candidates, bypass owning-domain permission or infer a Client from a
rejected/ambiguous suggestion. Existing trusted supplier routing remains its own explicit Signal/
Storage contract and is not generalized into entity identity.

## Bounds And Lifecycle

- Default 10 and hard 50 authorized manual candidates; cap-plus-one reports narrowing required.
- One current run per actor/conversation/policy fingerprint; bounded queue lease/retry and no
  synchronous unbounded candidate/AI scan.
- Invalidation on source membership/content identity, Contact address/relation merge, client/site
  ownership, Ticket relationship, user/account access, policy/schema or AI-agent change.
- Retention removes rejected/superseded run detail on policy while preserving minimal accepted-link
  and correction audit; legal hold/export follows order 23.

## Out Of Scope

- Automatic Contact/client/site creation or merge, domain-wide identity inference, CRM enrichment,
  external people-search, tenant-wide learning, mailbox access through a PSA relationship, or using
  a suggestion as Ticket/portal audience authority.
- Supplier-product/order identity owned by Storage, or changing Contact ownership without its guarded
  action and separate review.

## Tests

- Unique exact, duplicate exact, alias/case/IDN normalization, display-name/domain-only weak evidence,
  missing address and Contact merge/ownership repair.
- Personal/shared/delegated/revoked/break-glass Mail access crossed with Contact/client/site/Ticket
  Work Context; hidden candidates do not affect counts, labels, timing, snippets or API shape.
- Manual search SQL scoping, cap-plus-one, pagination, stale source/candidate, accept/reject/unlink/
  replacement, concurrency/idempotency and non-enumerating route binding.
- AI disabled/policy-denied/tool/provider/cost/schema/timeout paths, minimized authorized cohort,
  attachment exclusion, provenance/staleness and deterministic fallback; no AI acceptance/write.
- Rules/Smart/Signal/Ticket consume accepted-current only; no Contact creation, mailbox grant, Ticket/
  portal publication, provider mutation, personal unread or provider-read side effect.
- Migration/down guards, retention/export/invalidation, queue/worker health, API/OpenAPI, Bootstrap
  reader/mobile/accessibility and affected Email/Contact/Clients/WorkContext/Integration tests.

## Documentation And Operations

Update Email, Contact, Clients, WorkContext and Integration Knowledge, API/OpenAPI, privacy/cost and
entity-correction runbooks, TODO, completion index and `docs/human-review.md`. Deploy additive schema
with `umask 0002`, clear caches, rebuild group-writable views and restart affected workers. Migration
does not create matches or invoke AI; historical preview/backfill is a separate bounded operator run.

`HR-2026-08-16-025` remains Pending until a named reviewer verifies exact/ambiguous/manual/AI paths,
every negative Mail/PSA access combination, correction/invalidation, API and sanitized audit.

## Done Criteria

- [ ] Matching prefers deterministic Contact-owned evidence, treats ambiguity honestly and never
  turns weak/AI evidence into identity authority.
- [ ] Every candidate/context query intersects current Mail and owning-domain authorization without
  hidden counts, labels or Work Context leakage.
- [ ] Acceptance/correction is explicit, idempotent, source-bound, provenance-rich and produces no
  Contact/provider/Ticket/portal side effect by itself.
- [ ] Tests, migrations, UI/API, docs/runbooks and `HR-2026-08-16-025` are complete while named human
  review remains Pending.
