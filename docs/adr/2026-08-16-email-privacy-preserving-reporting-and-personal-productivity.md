# ADR: Email Privacy-Preserving Reporting And Personal Productivity

Status: Accepted
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-reporting-advanced-productivity.md`

## Context

The finished Mail workspace needs saved views, favorites, snooze, follow-up reminders, reusable
personal snippets, and operational reporting. These capabilities can improve daily work, but they can
also create a competing mailbox state, expose private mail through aggregates, retain content in a
second analytics store, or turn presence and collaboration into employee monitoring.

The existing Report module owns report discovery and shared presentation while each domain owns its
query and data semantics. Email owns mailbox authorization, per-user state, provider placement,
rules, AI feedback, collaboration, and lifecycle. Those boundaries must remain intact.

## Decision

Keep productivity state installation-local and Email-owned:

- saved Mail views store only an allowlisted versioned query definition, never result IDs or copied
  message content;
- favorites, snooze, and reminders are per-user account-conversation state independent of provider
  Seen/flags and other users;
- a new inbound generation wakes a snoozed conversation by default without acknowledging it;
- composer snippets are private user-authored text inserted explicitly into a draft and never send or
  run automatically; and
- keyboard shortcuts invoke existing guarded actions and are disabled while an input/editor/modal is
  active.

Email supplies reporting through local immutable fact events and rebuildable daily aggregates. The
Report module owns registry/discovery and the report shell; Email owns report definitions, queries,
filters, routes, authorization, facts, backfill/rebuild, and Knowledge. No external analytics,
warehouse, tracking pixel, mailbox-content export, or provider data-egress dependency is introduced.

Facts contain bounded identifiers, times, counts, durations, outcome codes, and allowlisted coarse
dimensions only. They never copy subject, sender/recipient address or domain, snippet, body, header,
attachment name/path, note/draft text, Ticket detail, raw provider error, credential data, or search
term. Raw facts expire after a bounded default period; daily aggregates retain no content.

Every report query intersects `report.view` with current ordinary mailbox authority. A personal
account is visible only to its owner or an explicitly authorized ordinary delegate according to the
same content policy; configuration administrators and break-glass-only users do not receive routine
reports. Shared/system account aggregates are visible only to current ordinary viewers and never
include inaccessible account totals. Per-user metrics are self-only. Team views show account-level
workload/outcomes and never rank, score, compare, or expose historical activity by technician.

Presence heartbeats are never reporting facts. Opened-by, assignment, review, rule, AI, and send facts
may contribute only to documented operational aggregates with current authority and bounded
retention. An incomplete or ambiguous source is reported as missing evidence rather than guessed.

## Rationale

- Domain-owned facts preserve Email semantics while the Report registry stays decoupled.
- Local bounded aggregates provide useful trends without exporting or duplicating mailbox content.
- Rechecking current account access prevents historical aggregates from surviving as an access
  side channel.
- Per-user productivity state avoids changing shared provider truth or another user's queue.
- Explicit snippets and existing guarded actions add speed without creating a new automation/send
  path.

## Consequences

Positive:

- Technicians gain useful queue organization and follow-up tools that remain personal and reversible.
- Authorized teams can inspect shared-mailbox volume, backlog, response evidence, automation outcomes,
  and health without mailbox content in the report store.
- Rebuild, retention, deletion, revocation, and no-evidence behavior are explicit.
- Reporting cannot silently become workforce surveillance.

Negative:

- Projection actions across Mail must emit idempotent reporting facts after commit.
- Aggregate correction/rebuild is an operational job with its own lag and health state.
- Fine-grained sender/domain/content analytics are deliberately unavailable without a later privacy
  decision and separate data contract.
- `report.view` alone is insufficient; every report query also resolves current Mail authority.

## Alternatives Considered

- **Query all live Mail tables for every report.** Rejected because large ranges are expensive and
  make stable correction/retention behavior difficult.
- **Copy Mail into a generic external analytics warehouse.** Rejected because it creates a new
  processor, authorization plane, deletion problem, and content-egress risk.
- **Manager dashboards grouped by technician.** Rejected because responder performance cannot be
  inferred fairly from mailbox presence, opened receipts, or shared-account sends.
- **Use provider flags for snooze/follow-up.** Rejected because providers differ and personal Nexum
  workflow must not mutate shared provider truth.
- **Scheduled send as a productivity shortcut.** Rejected from this slice because delayed external
  communication requires its own durable authorization, cancellation, provider-outcome, and restore
  contract.

## Follow-Up

- Any future external BI/warehouse, sender-domain analytics, scheduled reports, or automatic delivery
  requires a separate ADR covering data egress, recipient authorization, retention, and deletion.
- Report's future generic saved templates/delivery must reuse these domain authorization rules rather
  than serializing unrestricted Email queries.
- Keep `HR-2026-08-16-032` Pending until named reviewers verify personal privacy, shared-account
  scoping, no leaderboard, aggregate correctness, retention/rebuild, saved views, snooze/reminders,
  snippets, keyboard/accessibility, API, and responsive behavior.
