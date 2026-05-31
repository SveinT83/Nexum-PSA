# Feature Slice Process

A Feature Slice is a small, complete, testable implementation unit.

Use Feature Slices to break approved RFCs, beta-completion work, and larger maintenance tasks into
safe pieces that humans and AI agents can build without losing the bigger context.

## When Feature Slices Are Required

Create Feature Slices when:

- An approved RFC contains more than one user-visible or data-affecting change.
- A beta-completion item spans several screens, modules, or workflows.
- Work must be split across several contributors or AI agents.
- A change can be implemented safely one part at a time.
- The risk of accidental scope creep is high.

Feature Slices are not required for simple Level 1 fixes such as a typo, narrow bug, or small
styling correction unless the fix reveals larger follow-up work.

## Location And Naming

Feature Slices may be documented inside the parent RFC when there are only a few slices.

For larger work, store them in `docs/feature-slices/`.

Use this naming format:

```text
YYYY-MM-DD-parent-topic-slice-name.md
```

Example:

```text
2026-05-31-technician-profile-side-menu.md
```

## Required Sections

Use this structure:

```md
# Feature Slice: Short Name

Status: Draft | Ready | In Progress | Done | Blocked
Date: YYYY-MM-DD
Parent: RFC/TODO/document link
Owner: Name or agent

## Goal

What small outcome does this slice deliver?

## User-Visible Behavior

What changes for technicians, admins, clients, or system operators?

## Scope

What is included?

## Out Of Scope

What is intentionally not included?

## Data Touched

Tables, models, settings, files, queues, or external systems touched.

## Permissions

Permissions, roles, policies, middleware, or route guards affected.

## Tests

Feature, unit, regression, integration, or manual checks required.

## Documentation

Knowledge, module docs, RFC, ADR, TODO, or README updates required.

## Done Criteria

Concrete checklist for calling this slice complete.
```

## Implementation Rule

A Feature Slice should be independently reviewable and testable. Do not hide unrelated refactors
inside a slice.

If implementation reveals a larger design issue, stop and update the parent RFC before continuing.
