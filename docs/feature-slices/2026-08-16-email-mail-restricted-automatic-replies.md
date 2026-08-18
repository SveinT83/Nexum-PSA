# Feature Slice: Restricted Automatic Replies

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-email-restricted-auto-reply-safety-state-machine.md`
Owners: Email / Integration / Ticket / Security Operations
Human Review: `HR-2026-08-16-026`

## Goal

Deliver a narrowly allowlisted, deterministic reply-only automation with layered default-off
activation, exact recipient/template preview, delay and cancellation, strong loop/sensitive-case
exclusions, bounded rate/cost policy and truthful provider delivery reconciliation. Uncertainty never
sends or replays mail.

## Dependencies And Activation Gate

Orders 6-11, 13-15, 20-21 and 25 must be stable: secure provider runtime, complete provider
reconciliation, private invalidation/presence, deterministic published rules, unified outbound/Sent
API, Ticket correlation/closed-workflow, clean attachment policy and governed entity context. Normal
manual sending and draft suggestions remain available independently.

The 2026-08-16 product approval authorizes implementation only. Installation, account, scenario,
template and every rule are off by default after deploy. Nothing auto-sends until named Dev human
review plus a separate explicit activation step. No migration, seed, scheduler or retry enables it.

## Additive Data Model

Reserve migrations `2026_08_16_164000` and `165000`.

Add `email_auto_reply_policies`, immutable scenario/template versions and publication events:

- installation/account scope, allowed scenario, reply/domain boundary, template/schema/version;
- delay, thread/recipient/account/global rates, provider/cost budgets and validity window;
- exclusion policy versions, publisher/approver, activation/revocation/emergency-stop state;
- append-only activation/publication history with safe IDs/hashes/reasons.

Add `email_auto_reply_executions` and events:

- exact account/conversation/source message/placement, published rule/action/scenario/template and
  provider-binding/outbound-policy fingerprints;
- exact normalized reply target hash and immutable outbound/Message-ID/idempotency identity;
- frozen evidence/exclusion/limit reservation, scheduled/cancel/deadline/write/reconciliation times;
- states from the ADR, tokenized claim/lease, bounded attempts and stable safe reason/error;
- nullable unified outbound submission/Sent/bounce references, never a second SMTP log.

Unique keys prevent more than one execution for source+published action and enforce one active reply
reservation per conversation/scenario window. Down refuses while publications/executions/evidence
exist. Schema contains no body, address, prompt, provider response, secret or private path.

## Layered Availability

The action/control/job exists only when all are current:

- installation `restricted_auto_reply_enabled=false` explicitly activated by Security Operations;
- active account opt-in by provider/account manager, with supported verified outbound binding;
- active allowlisted scenario and immutable reviewed template version;
- dedicated `email.auto_reply_publish` publisher permission and current mailbox authority;
- a published deterministic rule version whose single action references that scenario/template;
- active emergency-stop state, worker/scheduler, delivery reconciliation and health readiness; and
- current source, recipient, Ticket/lifecycle/security and budget eligibility.

Broad Admin/Technician roles, Mail Send, account management, rule edit, API `email.update`, break-
glass, AI or a personal owner alone do not publish/activate. Personal accounts may be disabled
installation-wide even when shared/system use is permitted. API publication/activation uses narrower
abilities and the same previews/approvals; no shortcut endpoint exists.

## Proposal, Preview And Delay

After deterministic inbound/rule evaluation, create a proposal only for a supported existing thread.
Freeze source/version and run header/content/recipient/Ticket/security exclusions before showing exact
preview. Preview includes source account/thread, scenario, exact recipient and rendered subject/body,
why it qualifies, exclusions checked, delay/deadline, recent reply/rate counts and audit identity.

Rendering uses an immutable template with allowlisted scalar placeholders and context-aware escaping.
No message HTML/signature/header string becomes template syntax or a new header. The first version
does not auto-send AI-generated prose and never attaches or quotes inbound files/content.

Schedule only after an exact unexpired preview fingerprint. Default delay is at least five minutes;
organization policy may set a larger minimum, never zero. Authorized users with current mailbox View
and cancellation permission can cancel the one proposal during the window without publishing rules
or changing provider state.

## Exclusions And Loop Safety

Fail closed for absent/ambiguous headers, sender/recipient/thread/authentication or policy evidence.
Suppress at minimum:

- null/reused/conflicting identity; forged/misaligned sender where policy requires authentication;
- `Auto-Submitted` other than `no`, `Precedence` bulk/list/junk, mailing-list headers, bounces/DSNs,
  out-of-office, auto-reply, loop headers, own addresses and known no-reply addresses;
- Reply All, CC/BCC, more than one target, new/manual/AI recipient, marketing/list/bulk message;
- complaint, abuse, security incident, credential, legal, contract, finance/payment, privacy/DSAR,
  legal hold, permanent deletion, offboarding/restore or unresolved Ticket audience/workflow;
- non-clean/attachment-dependent content and any rule/scenario requiring attachment inspection;
- an existing accepted/pending/unresolved execution, thread/window maximum or rate/cost exhaustion.

Add and validate stable `Auto-Submitted: auto-replied`, installation loop token, immutable outbound ID,
threading headers and provider/Ticket correlation. A reply bearing Nexum's own loop identity can never
trigger another automatic reply.

## Execution And Reconciliation

At claim time, lock the exact execution and shared provider account, re-resolve current binding, then
reauthorize every gate/source/recipient/exclusion/rate/cost/template/rule fingerprint. Reserve all
limits atomically. Any drift blocks without provider call.

Create one order-11 outbound submission and persist `provider_write_started` before its irreversible
provider boundary. Safe proven pre-write failures may retry within bounded lease/deadline. Timeout,
disconnect or exception after possible submission becomes `unresolved`; automatic/manual retry is
unavailable. Reconcile by immutable outbound Message-ID/provider Sent/bounce evidence. A redelivered
job or losing worker cannot send again or overwrite accepted/delivered/cancelled/unresolved state.

Provider acceptance is not delivery. UI/history distinguishes accepted, delivery pending, delivered,
bounced and unresolved. Bounce/complaint suppresses the scenario/recipient according to policy and
notifies authorized operators without content leakage. Ticket capture follows its existing exact
relationship/audience policy once Sent evidence exists; it does not make the reply permissible.

## Emergency Stop And Operations

Global and account emergency stops atomically prevent new proposals/claims. They cancel scheduled
items that have not crossed the write boundary and leave submitted items for reconciliation. An
operations dashboard shows safe counts/oldest age/rates/budget/reconciliation/stop state and exact
authorized drill-down. It never exposes personal mailbox content to configuration-only operators.

Queue `email-outbound` separately from sync, with bounded claims, retry/backoff and explicit health.
Scheduler performs proposal expiry, safe cancellation and read-only reconciliation; it never creates
an unreviewed rule/template or replays unresolved delivery. Kill-switch operation and incident
runbook must work without a code deploy.

## Out Of Scope

- AI-generated automatic body, Reply All/CC/BCC, new threads/recipients, attachments, marketing/bulk,
  broad customer support bots, free-form user templates, permanent-delete actions or replying on a
  Ticket/Contact relationship without the exact source thread.
- Treating confidence, provider acceptance, Ticket status or sender domain as sufficient authority.

## Tests

- Every gate defaults off and intersects installation/account/scenario/template/rule/publisher/
  provider/readiness; role/API/break-glass/AI/personal-owner bypass denial and invisible controls.
- Exact template escaping/headers/recipient/thread/Ticket key; no Reply All/new recipient/attachment/
  AI prose; injection, Unicode/IDN and corrupted template/version cases.
- All standards and compatibility loop/bounce/list/OOO/own/no-reply tests, own loop round trip, duplicate
  source and header absence/conflict; every sensitive/lifecycle/security/Ticket exclusion.
- Preview/staleness/delay/cancel, per-thread/recipient/account/global rates, cost reservation/reconcile,
  concurrent claims, redelivery, expiry and emergency stop before/after write.
- Pre-write retry, provider accept, delayed Sent, bounce, response loss unresolved/no replay, reconcile
  after restart and exact one outbound/Sent/Ticket capture.
- Personal/shared/delegation/revocation, Contact/Ticket/portal isolation, no provider organize/read,
  personal-unread/rule/Signal/AI/Contact creation side effects.
- Sanitized DB/log/audit/notification/metrics; migration/down guard, queue/scheduler/failed-job/health,
  API/OpenAPI, Bootstrap/accessibility and real-provider controlled Dev tests.

## Documentation And Operations

Update Email, Integration, Ticket, Notification and security Knowledge/runbooks, API/OpenAPI, TODO,
completion index and `docs/human-review.md`. Deploy additive schema/permissions with `umask 0002`,
clear caches, rebuild group-writable views and restart workers while all gates remain off. Configure
and test one expendable Dev mailbox/thread/scenario; never use a real customer recipient for first
activation.

`HR-2026-08-16-026` remains Pending and every enablement remains off until a named reviewer validates
all gates/exclusions, exact recipient/body/headers, delay/cancel/limits/emergency stop, real provider
accept/Sent/bounce/response-loss and sanitized audit. Production activation is a later explicit
operator decision.

## Done Criteria

- [ ] Automatic replies remain visibly separate, layered default-off and limited to approved
  deterministic reply-only scenarios/templates with exact recipients.
- [ ] Exclusion, loop, rate, cost, delay, cancel and emergency-stop controls fail closed and cannot be
  bypassed by rules, AI, roles or API.
- [ ] One durable outbound boundary and reconciliation provide no duplicate replay on uncertainty and
  honest accepted/delivered/bounced status.
- [ ] Tests, migrations, permissions, UI/API, workers/scheduler, docs/runbooks and
  `HR-2026-08-16-026` are complete while named human review and activation remain Pending/off.
