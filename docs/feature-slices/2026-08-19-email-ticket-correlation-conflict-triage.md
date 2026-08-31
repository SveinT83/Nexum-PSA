# Feature Slice: Email/Ticket Correlation Conflict Triage

Status: Done On Dev / Human Review Reopened
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 14)
Review ID: `HR-2026-08-16-014`

2026-08-31 implementation: inbound correlation now evaluates active durable links, all matching
RFC header evidence, and the additive `TD-...` subject key together. Agreement links through the
existing guarded Ticket action. Disagreement creates one idempotent, metadata-only pending conflict
and blocks Ticket creation/linking until an administrator chooses one evidence-backed Ticket with a
reason. The new admin page records the actor, decision, time and Ticket event without moving,
deleting, marking read or publishing the source email.

## Purpose

This slice addresses conflicts in Email-to-Ticket correlation. It ensures that durable links and RFC headers remain authoritative, while Ticket keys (`TD-...`) in subjects act as an additive fallback. It also implements an "audited choice" workflow for conflicting evidence.

## Scope

- **Correlation Hierarchy:** Priority: 1) Manual Durable Link, 2) RFC `In-Reply-To`/`References`, 3) `TD-...` Ticket Key in Subject.
- **Conflict Detection:** Identifying when different correlation methods point to different tickets.
- **Triage Workflow:** UI/Process for users to resolve correlation conflicts manually.
- **Audit Trail:** Recording why a specific correlation was chosen when conflicts were present.

## Technical Design

### Correlation Service
- Update `InboundEmailCorrelationService` to implement the hierarchy.
- When multiple matches are found, flag the message for "Triage Needed".

### Conflict Storage
- `email_ticket_correlation_conflicts` table to store detected conflicts.

## Implementation Plan

1. **Update `InboundEmailCorrelationService`:** Implement the hierarchical matching logic.
2. **Database Migration:** Create `email_ticket_correlation_conflicts` table.
3. **Triage Action:** `ResolveEmailTicketCorrelationConflict` action.
4. **Verification:** Test scenarios with conflicting headers and subject keys.

## Boundary & Risks

- **Boundary:** Focused on *inbound* correlation; outbound reconciliation is Slice 16.
- **Risk:** High volume of conflicts if subject keys are reused or headers are mangled by forwarders.
- **Mitigation:** Prefer the most durable/immutable evidence (RFC Message-IDs) first.

## Implementation Evidence

- Migration `2026_08_31_120000_create_email_ticket_correlation_conflicts_table.php` adds one conflict
  row per Email message and no provider/data backfill.
- `InboundEmailTicketCorrelationService` replaces first-match routing and treats a pending conflict
  as a hard stop on every replay.
- `ResolveEmailTicketCorrelationConflict` accepts only a recorded candidate, reuses the guarded
  inbound Ticket link action, and writes the selected Ticket, actor, reason and Ticket event once.
- **Admin > Settings > Email accounts > Review conflicts** exposes only currently stored mailbox
  context and evidence-backed Tickets to administrators.
- Focused conflict, idempotency, safe-evidence, route and resolution checks pass inside the complete
  Email feature suite. Human browser/runtime review is deliberately reopened because implementation
  followed the older design approval.

## Done Criteria

- [x] Durable link, RFC header and `TD-...` evidence is evaluated together without first-match guessing.
- [x] Conflicts are stored idempotently and block automatic Ticket creation or linkage.
- [x] An authorized administrator can make one audited evidence-backed choice in the UI.
- [x] Migration, permissions, tests and documentation are complete on Dev; human review remains open.
