# Feature Slice: Email/Ticket Correlation Conflict Triage

Status: In Progress / Implementation Missing
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 14)
Review ID: `HR-2026-08-16-014`

2026-08-21 audit: the planned conflict records, triage actions, UI and focused tests do not exist.
No completed conflict-triage behavior is claimed; existing durable links, RFC headers and additive
`TD-...` fallback remain unchanged.

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
