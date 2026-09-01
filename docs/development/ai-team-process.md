# AI And Team Development Process

This document defines the shared development process for humans and AI agents working on Nexum PSA.
`AGENTS.md` remains the mandatory entry point. This file explains the team workflow behind it.

## Purpose

Nexum PSA is now worked on by multiple people and AI agents. The process must protect context,
domain ownership, business rules, data integrity, and user-facing behavior while still allowing
small fixes to move quickly.

These rules apply to:

- Human developers.
- Codex.
- Claude.
- ChatGPT.
- Autonomous AI agents.
- Automation scripts that write code or documentation.
- Future AI integrations that generate changes.

## Core Principle

Documentation defines intended behavior, approved direction, and business rules.

Code, migrations, tests, and database state define implemented behavior.

When they disagree, do not guess. Stop, investigate, and clarify before changing behavior. The right
fix may be to update code, update documentation, or create an RFC because the intended behavior is no
longer clear.

## Product Phase

Nexum PSA is no longer in MVP/version-1 planning. Beta is live, and the primary work is to finish the
existing product.

Before starting new systems, contributors must first check whether the request should instead improve
an existing module, add missing settings, complete beta-critical UX, strengthen permissions, improve
tests, or update Knowledge documentation.

If a beta-critical gap is discovered, add it near the top of `docs/TODO.md` or recommend doing so if
the user has not approved the edit yet.

New domains and large new capabilities should wait unless they unblock beta completion or have an
approved RFC.

## Work-In-Progress Discipline

The default is finish before starting. Contributors must not create a trail of partly implemented
Issues, RFCs, ADRs, Feature Slices, documentation updates, and unverified Dev changes.

Before creating or starting a new Issue, RFC, ADR, or Feature Slice:

1. Reconcile `docs/TODO.md`, relevant GitHub Issues, planning-artifact status lines, the Dev
   working copy, and open human-review entries.
2. Identify the existing active item in the same or dependent scope.
3. Complete and verify that item first when safe progress is possible.
4. If it is genuinely blocked, record the exact blocker, owner, next action, resume condition, and
   linked human-review ID before switching work.
5. Start another item only after completion, an explicit approved reprioritization, or an urgent
   beta/security/production incident.

Each contributor should own one primary implementation at a time. Explicitly coordinated,
non-overlapping Feature Slices may run in parallel only when every slice has its own TODO row or
clearly identified parent workstream, owner, scope, status, and handoff.

Uncommitted or unpushed work is still active work. On authoritative Dev, implementation is
`Done On Dev` when the requested behavior is present, relevant tests/checks pass, and required
documentation is current. Commit, push, merge, Main promotion, migration, deployment, runtime
activation, production verification, and human review are separate states.

Agents must enforce this discipline, including with human collaborators. When a new ordinary task
would increase avoidable WIP, the agent must name the existing finishable item and recommend or
continue its completion first. Silence, age, a passing partial test, or switching branches does not
close an item.

The active register must be updated in the same work session whenever an item starts, blocks,
changes scope, is superseded, passes verification, receives human review, or completes. Related
GitHub Issue state and RFC/ADR/Feature Slice status must be reconciled at the same time.

## Change Levels

### Level 1: Small Fix

Examples:

- Typo or copy fix.
- Small UI alignment issue.
- Clear bug with narrow scope.
- Missing validation message.
- Documentation-only update.
- Small permission guard that follows existing patterns.

Required process:

- Read `AGENTS.md`.
- Read affected files and nearby patterns.
- Run or explain relevant verification.
- Update docs only when behavior changes.

No RFC is required.

### Level 2: Feature Or Workflow Change

Examples:

- New setting.
- Changed ticket, contact, sales, task, storage, economy, or knowledge behavior.
- New admin flow.
- New user-visible workflow.
- Significant UI behavior.
- Work that touches one module but may affect another.

Required process:

- Create an RFC in `docs/rfc/`.
- Wait for approval before implementation.
- Read `AGENTS.md`.
- Read relevant module docs and Knowledge docs.
- Read `docs/TODO.md`.
- Inspect routes, controllers, models, requests, views, policies/permissions, and tests.
- Document impact in the implementation summary.
- Add or update tests.
- Update module docs and Knowledge documentation.

RFC is mandatory because feature and workflow changes can affect other modules and future plans even
when they look local at first.

### Level 3: Domain, Database, Integration, Permission, API, Or Cross-Module Change

Examples:

- New domain or module.
- Database schema changes.
- Major workflow changes.
- Permission model changes.
- External integration changes.
- API contract changes.
- Background sync, queue, or automation behavior.
- Changes affecting multiple modules.

Required process:

- Create an RFC in `docs/rfc/`.
- Wait for approval before implementation.
- Perform explicit impact analysis.
- Add or update tests for all affected modules.
- Update Knowledge and developer docs.
- Create an ADR in `docs/adr/` when a meaningful architecture decision is made.

## Pre-Implementation Analysis

Before medium or large implementation work, the contributor must review:

- Relevant documentation.
- Related modules.
- Related workflows.
- Permissions and access rules.
- Routes and controllers.
- Models, migrations, requests, services, jobs, listeners, and policies.
- Existing tests.
- `docs/TODO.md`.
- Relevant ideas and backlog documents.
- Relevant RFCs.
- Relevant ADRs.

The contributor must identify:

- Dependencies.
- Risks.
- Missing documentation.
- Affected modules.
- Potential side effects.
- Required migrations or deploy commands.
- Required queue, scheduler, cache, or build actions.

## Change Classification

The contributor or AI agent must classify the request before implementation:

- If it is Level 1, proceed after reading affected files and verifying the change.
- If it is Level 2 or Level 3 and no approved RFC exists, stop and create or request an RFC first.
- If an approved RFC or beta-completion item is too large to implement safely in one pass, define
  Feature Slices before implementation.
- If the level is unclear, treat it as Level 2 until clarified.

This rule exists because contributors and AI agents may not have full project context. The process
must force impact review before feature and workflow work starts.

## Feature Slices

A Feature Slice is a small, complete, testable implementation unit under an approved RFC,
beta-completion item, or documented maintenance task.

Feature Slices keep larger work reviewable and reduce the risk that a contributor or AI agent changes
too much at once.

Each Feature Slice should define:

- Goal.
- User-visible behavior.
- Scope.
- Out of scope.
- Data touched.
- Permissions.
- Tests.
- Documentation updates.
- Done criteria.

Use `docs/processes/feature-slice-process.md` for the process and template.

## Clarification Rules

Ask one focused clarification question at a time when safe progress is blocked.

Do not ask for clarification when the answer is already discoverable from current docs, code, tests,
or established project conventions.

When recommending a solution, present one primary recommendation. Alternatives can be mentioned, but
the contributor should make a clear recommendation unless the user explicitly asks for multiple
options.

## Cross-Module Protection

Nexum PSA domains are connected. A change must not be evaluated only inside the file being edited.

Common impact examples:

- Contacts may affect Tickets, Clients, Sales, Email, Calendar, and future Telephony.
- Email may affect Inbox, Tickets, Notification, Operational Signals, and future AI tools.
- Services may affect Contracts, Sales, Economy, SLA, Ticket time, and Storage costs.
- Assets may affect Tickets, Storage, Operational Signals, and future service intake.
- Permissions may affect every tech/admin/client workflow.
- Knowledge may affect BookStack sync and AI context.

When a known future TODO will influence the current area, design the current change so it does not
create avoidable rework.

## TODO Management

`docs/TODO.md` is the authoritative backlog and live work-in-progress register.

When a contributor discovers missing work, missing documentation, technical debt, future
improvements, or required follow-up tasks, it should recommend adding a TODO entry. If the user has
already approved the work, update `docs/TODO.md` directly.

Every active entry must identify the owner, exact status, related Issue/RFC/ADR/Feature Slice,
current evidence, next action, and human-review ID when applicable. Do not leave an item as
`In Progress` after it is complete, and do not mark implementation incomplete merely because the
verified Dev change is uncommitted or unpushed.

At the start and end of medium or large work, reconcile:

- Active TODO workstreams.
- Open GitHub Issues in scope.
- Draft RFCs and Proposed ADRs.
- Draft, Ready, In Progress, Blocked, and Rework Feature Slices.
- Human-review state.
- Actual Dev implementation and verification evidence.

Accepted ADRs and Approved RFCs are decision records, not unfinished work by themselves. Their
implementation state belongs in the linked Feature Slice and TODO workstream.

## Documentation Maintenance

When behavior changes, verify whether these need updates:

- Module documentation.
- Knowledge documentation.
- Architecture documentation.
- RFC documents.
- ADR documents.
- Workflow documentation.
- Permission documentation.
- API documentation.
- UI documentation.
- README or setup documentation.

Knowledge article bodies must not repeat the article title as the first Markdown heading because the
Knowledge page UI already renders the title.

## Testing And Verification

Before work is complete, verify the relevant behavior:

- Existing functionality still works.
- Related modules still work.
- Permissions still work.
- Routes still work.
- Workflows still work.
- Integrations still work or are clearly mocked/skipped.
- Queue and scheduler behavior is covered when affected.

For narrow changes, run the narrow relevant test set. For broad or release-oriented changes, run the
full test suite when practical.

If tests cannot be run, the final handover must state why.

## Multi-Agent Handover

Every contributor should leave enough context for the next person or AI agent.

The final summary should include:

- Files changed.
- Behavior changed.
- Tests or checks run.
- Commands required after deploy.
- Known risks.
- Follow-up TODO items.

Do not revert or overwrite another contributor's work unless the user explicitly asks for that.

## Process Violations

The following are process violations:

- Implementing a Level 2 or Level 3 change without an approved RFC.
- Implementing a large approved RFC as one broad change when it should have been split into Feature
  Slices.
- Exposing UI for unfinished functionality.
- Changing domain ownership without reading `docs/module-architecture.md`.
- Changing UI without reading `docs/ui-guidelines.md`.
- Ignoring known TODOs that directly affect the change.
- Skipping tests without stating why.
- Making undocumented assumptions about business rules, permissions, integrations, or database
  behavior.
