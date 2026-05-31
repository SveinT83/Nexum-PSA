# ADR Process

ADR means Architecture Decision Record. ADRs preserve why an important technical decision was made.

## When ADR Is Required

Create an ADR for decisions such as:

- Framework or package choices.
- Authentication or authorization design.
- Domain ownership changes.
- Workflow architecture.
- Database architecture.
- Integration architecture.
- Queue, scheduler, or automation architecture.
- Security-sensitive implementation choices.
- Decisions that future contributors are likely to revisit.

Not every feature needs an ADR. Use ADRs when the reasoning matters beyond the current task.

## Location And Naming

Store ADRs in `docs/adr/`.

Use this naming format:

```text
YYYY-MM-DD-short-title.md
```

Example:

```text
2026-05-31-nextcloud-talk-bot-ownership.md
```

## Required Sections

Use this structure:

```md
# ADR: Short Title

Status: Proposed | Accepted | Deprecated | Superseded
Date: YYYY-MM-DD
Decision Makers: Name or agent

## Context

What decision was needed?

## Decision

What did we choose?

## Rationale

Why is this the right choice for Nexum PSA?

## Consequences

Positive and negative consequences.

## Alternatives Considered

Other reasonable options and why they were not chosen.

## Follow-Up

Any TODOs, docs, tests, migrations, or future review points.
```

## Updating ADRs

Do not rewrite accepted history to pretend a different decision was made. If a decision changes,
mark the old ADR as superseded and create a new ADR that explains the new decision.
