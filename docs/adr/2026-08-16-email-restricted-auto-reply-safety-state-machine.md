# ADR: Restricted Automatic Reply Safety State Machine

Status: Accepted
Date: 2026-08-16
Decision owners: Email / Integration / Ticket / Security Operations
Related RFC: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Feature Slice: `docs/feature-slices/2026-08-16-email-mail-restricted-automatic-replies.md`

## Context

An automatic external reply combines untrusted inbound content, recipient resolution, rule policy,
provider delivery and potentially AI-derived classification without a human at the final send
boundary. A false positive can disclose information, create mail loops, duplicate delivery, answer a
complaint/security/contract matter incorrectly or consume provider/cost limits. Treating it as one
more rule action or ordinary retryable queue job would hide the irreversible SMTP boundary.

The Mail RFC separately authorizes only a restricted, default-off implementation after explicit
product approval. Svein supplied that approval on 2026-08-16 while retaining every safety, test,
operations and human-review gate. This decision defines the architecture; it does not enable sending.

## Decision

Email owns a durable, explicit automatic-reply state machine that reuses the unified outbound
submission and Sent/delivery reconciliation boundary. A deterministic published rule may propose a
reply, but cannot send directly. Each proposal freezes the exact inbound source, reply recipient,
approved scenario/template version, policy versions, delay window and idempotency identity.

The first supported version sends only a reviewed deterministic template/structured response in an
existing authorized thread. AI may classify an allowlisted scenario or propose a manual draft, but
AI-generated prose is not automatically sent. Adding generated automatic body content requires a new
ADR/review rather than widening this one silently.

Sending is enabled only when installation, account, published-rule and scenario/template gates are
all on, the dedicated permission/publisher approval is current, and the exact execution passes every
fresh exclusion, loop, rate, budget, recipient, provider, lifecycle and delivery-readiness check.
Every layer defaults off. A global emergency stop and account stop prevent new claims immediately.

The durable states distinguish `scheduled`, `cancelled`, `blocked`, `claimed`,
`provider_write_started`, `accepted`, `delivery_pending`, `delivered`, `bounced`, `unresolved` and
safe pre-write failure. Only failures proven before provider write may retry under bounds. Anything
after the write boundary is reconciled by Message-ID/outbound identity and never blindly replayed.

## Recipient And Content Decision

- Reply only to the verified original author/reply address in the existing source thread. No Reply
  All, CC, BCC, newly invented/manual/AI address, bulk recipient or marketing path.
- Suppress auto-generated, bounce, list, bulk, precedence, out-of-office and loop-marked messages
  using standards headers plus bounded compatibility facts. Missing/ambiguous facts fail closed.
- Exclude sensitive/security/complaint/legal/contract/finance/credential/attachment-dependent cases.
  A later bounded policy needs its own review; confidence cannot override an exclusion.
- Add stable `Auto-Submitted`, loop and immutable outbound headers without replacing Ticket/provider
  threading or the existing `TD-...` compatibility key.
- Templates are immutable published versions with fixed safe placeholders from an allowlist. Subject,
  body, signature and recipient preview are exact and content injection cannot create headers/HTML.

## Limits And Cancellation

Limits intersect installation, account, rule, scenario, recipient/domain, conversation and provider
budgets. Reserve atomically before claim; reconcile actual delivery/cost afterward. Enforce a
configurable delay/cancel window, per-thread reply maximum, recipient/time window rate limit, daily/
monthly account cap and global emergency stop. Unknown policy, time, cost or provider outcome blocks.

Operators and authorized users may cancel before `provider_write_started`; after that boundary only
reconciliation/status is allowed. Cancellation/emergency stop never asserts that an already accepted
provider submission was not delivered.

## Privacy And Authority

The execution actor is a dedicated system actor with a published rule/scenario snapshot, never the
mailbox owner session or a general AI agent. At execution it reauthorizes the active account/provider
binding, source placement/thread, rule publication, recipient and target-domain/Ticket policy. It has
no generic mailbox search, Contact creation, provider folder mutation or cross-domain write tools.

Audit and notifications store safe IDs, policy/template versions, reason/status and hashes, not Mail
body, recipient address, prompt/response, provider response or credential. Normal authorized readers
may inspect the exact source/reply through the source record; configuration/audit-only operators see
metadata.

## Consequences

- Automatic replies are visibly separate from summaries, suggestions, drafts and normal rules.
- Delivery safety uses one shared Email outbound truth rather than a second SMTP pipeline.
- Low latency is traded for a deliberate cancel window and fresh policy checks.
- Operational rollout requires explicit per-layer activation and real-provider reconciliation tests.
- Unsupported scenarios remain manual drafts rather than best-effort automated replies.

## Rejected Alternatives

- A generic `send_reply` rule action: it lacks a durable approval/write/reconciliation boundary.
- Retrying SMTP exceptions: response-loss can duplicate external mail.
- AI confidence as authorization or generated automatic prose in the first version.
- Reusing Notification or Marketing send paths: they own different audience/suppression contracts.
- Installation/account opt-in alone without scenario/rule publication and exact execution checks.

## Verification

The slice must prove all layered gates, exclusions, loop/rate/budget/cancel/emergency behavior,
recipient/header/template safety, durable write boundary, response-loss/no replay, Sent/bounce
reconciliation, privacy and cross-domain non-effects. Real provider enablement remains a named human
review action; no migration or deploy enables a layer automatically.
