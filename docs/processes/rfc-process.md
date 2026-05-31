# RFC Process

RFC means Request For Change. Use RFCs to clarify and approve important changes before code is
written.

## When RFC Is Required

An RFC is required for Level 2 and Level 3 changes.

Level 2 examples:

- New settings.
- Changed domain behavior.
- New admin flows.
- New user-visible workflows.
- Significant UI behavior.
- Work that touches one module but may affect another.

Level 3 examples:

- New modules or domains.
- Database schema changes.
- Workflow changes across modules.
- Permission model changes.
- Integration changes.
- API changes.
- Background sync, automation, or queue behavior with data impact.
- Significant UI workflows that change how technicians, admins, or clients work.

Only Level 1 small fixes may skip RFC.

## Location And Naming

Store RFCs in `docs/rfc/`.

Use this naming format:

```text
YYYY-MM-DD-short-title.md
```

Example:

```text
2026-05-31-contact-deduplication.md
```

## Required Sections

Use this structure:

```md
# RFC: Short Title

Status: Draft | Approved | Rejected | Superseded
Date: YYYY-MM-DD
Owner: Name or agent

## Context

What problem are we solving, and why now?

## Goals

What must this change achieve?

## Non-Goals

What is intentionally outside this change?

## Current Behavior

How does Nexum work today?

## Proposed Change

One recommended solution.

## Impact Analysis

Affected modules, permissions, routes, data, integrations, queues, UI, and docs.

## Data And Migration Plan

Migrations, backfill, compatibility, rollback concerns, and upgrade order.

## Testing Plan

Feature tests, unit tests, regression tests, manual checks, and integration checks.

## Documentation Plan

Knowledge, module docs, README, setup, or operational docs that must change.

## Open Questions

One question at a time when possible.

## Approval

Who approved it and when?
```

## Approval Rule

Implementation must not begin for Level 2 or Level 3 work until the RFC status is `Approved` or the
user explicitly approves the RFC in conversation.

If implementation reveals that the approved design is wrong, stop and update the RFC before
continuing.
