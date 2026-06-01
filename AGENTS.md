# tdPSA Agent Instructions (MANDATORY)

This is the main instruction file for AI-assisted work in tdPSA / Nexum PSA.
Keep this file as the short mandatory entry point and use specialized Markdown
files as the detailed source of truth when they are relevant.

These rules apply to human developers, Codex, Claude, ChatGPT, Copilot,
autonomous AI agents, scripts, and future AI integrations. If another
agent-specific file conflicts with this file, this file wins.

## Read Order

1. Always read this file before making code changes.
2. Read `docs/development/ai-team-process.md` before planning medium or large
   work, coordinating multiple contributors, creating RFCs/ADRs, or making
   changes that affect more than one module.
3. Read `docs/module-architecture.md` before creating a module, changing module
   structure, adding routes, moving controllers, moving views, or changing
   domain ownership.
4. Read `docs/ui-guidelines.md` before changing UI, layout, Blade views, shared
   components, navigation, page styling, or page-specific CSS.
5. Read `docs/TODO.md` before planning or implementing work. If the current
   change touches an area with related future work, account for that future
   direction so new code does not create avoidable rework.
6. Use `README.md` for project overview and developer setup context only.
7. Check `docs/` for task-relevant plans, assessments, integration notes,
   security notes, beta checklists, RFCs, ADRs, and feature slices before
   changing an affected domain.
8. Treat old MVP/version-1 planning as historical context only. Nexum PSA is
   past MVP planning: beta is live, and current work prioritizes finishing,
   hardening, documenting, and polishing the existing product before starting
   new systems.
9. Treat `AGENT.md`, `CLAUDE.md`, `docs/CLAUDE.md`,
   `.github/copilot-instructions.md`, and `docs/juneGuidelines.md` as
   compatibility entry files. They must point back to this file and must not
   override it.

## Source Of Truth

- Documentation defines intended behavior, approved direction, and business
  rules.
- Code, migrations, tests, and current database state define implemented
  behavior.
- If documentation and implementation disagree, stop and clarify before
  changing behavior when the mismatch affects data, permissions, workflows,
  integrations, or customer-facing behavior.
- Never guess database structures, workflows, business rules, permissions,
  integrations, or UI behavior when the answer can be verified.

## Product Priority

- Current work is beta completion toward the finished Nexum PSA product, not
  MVP/version-1 exploration.
- Before proposing or implementing new systems, first improve existing modules
  so they work reliably, have required settings, are documented, have tests,
  and are prepared for planned future capabilities.
- Missing beta-critical capabilities discovered during discussion or
  implementation must be added near the top of `docs/TODO.md`.
- New domains and large new capabilities should wait unless they unblock beta
  completion or have an approved RFC.

## Change Levels

- Level 1 small fixes: typo, clear bug, narrow validation fix, small styling
  adjustment, or documentation-only update. Read affected files, test the
  relevant behavior, and update docs only when behavior changes. No RFC is
  required.
- Level 2 feature or workflow changes: new setting, changed domain behavior,
  new admin flow, or significant UI behavior. Create an RFC before
  implementation, get approval, read relevant module docs and TODO, perform
  impact analysis, update Knowledge/docs, and add tests.
- Level 3 domain, database, integration, permission, API, or cross-module
  changes: create an RFC before implementation, get approval, document impact,
  test affected modules, and create an ADR when an architectural decision is
  made.

## RFC, ADR, And Feature Slices

- RFC means Request For Change. Use `docs/processes/rfc-process.md`.
- ADR means Architecture Decision Record. Use `docs/processes/adr-process.md`.
- Feature Slice means a small, complete, testable implementation unit under an
  approved RFC, beta-completion item, or documented maintenance task. Use
  `docs/processes/feature-slice-process.md`.
- RFC approval is required for Level 2 and Level 3 changes.
- ADRs are required for significant architecture, package, data model,
  integration, authentication, authorization, workflow, or infrastructure
  decisions.
- Feature Slices are required when an approved RFC or beta-completion item is
  too large to implement safely in one pass.

## Pre-Implementation Analysis

Before medium or large implementation work, read the relevant docs, routes,
controllers, models, requests, views, permissions, tests, workflows, TODO items,
RFCs, and ADRs.

Identify:

- Dependencies.
- Risks.
- Missing documentation.
- Affected modules.
- Potential side effects.
- Required data migrations and deploy commands.
- Required queue, scheduler, cache, or build actions.

Ask one focused clarification question at a time when a missing decision blocks
safe progress. For small obvious fixes, keep momentum but still verify the
affected code and tests.

## Mandatory Architecture Rules

- ALL domain routes MUST be in `app/Modules/{Domain}/routes.php`.
- DO NOT use `routes/web.php` for domain routes.
- DO NOT create new route files in `routes/`.
- Controllers MUST be in `app/Modules/{Domain}/Controllers`.
- Views MUST be in `app/Modules/{Domain}/Views`.
- Module names MUST be singular, for example `Client` and `Ticket`, not
  `Clients`.
- See `docs/module-architecture.md` and the `app/Modules/Skelteton` module for
  reference implementation details.

## Laravel Boost

- Laravel Boost is installed and should be used for Laravel-specific
  investigation when relevant.
- Prefer Boost-provided framework context, docs, and tooling before guessing
  Laravel behavior.
- Boost must not override tdPSA module architecture, Bootstrap UI rules, or this
  file.

## UI And Component Rules

- Use Bootstrap, not Tailwind.
- Reuse global Blade components from `resources/views/components` wherever
  practical before creating module-specific markup or components.
- Prefer shared components for buttons, cards, form controls, navigation, and
  repeated layout patterns.
- Blade views should use visible section/block comments for major layout areas,
  matching the existing project style.
- Do not expose UI controls for unfinished or stubbed functionality. If a
  button, toggle, setting, route, or menu item is visible, the underlying
  behavior must be implemented, tested, and honest about what it does.

## TODO Management

- `docs/TODO.md` is the authoritative backlog for known follow-up work.
- If missing work, missing documentation, technical debt, future improvements,
  or required follow-up tasks are discovered, update `docs/TODO.md` when the
  work is approved or recommend adding an entry when approval is unclear.
- Before changing an area, check whether TODO/RFC/feature-slice items already
  affect that area.

## Comment And Documentation Rules

- Files must include clear English comments that explain structure, intent, and
  non-obvious behavior.
- Comments should help future debugging and maintenance; avoid noisy
  line-by-line comments for self-explanatory code.
- When a domain is completed or materially updated, Knowledge documentation
  must be created or updated for the affected functionality so it can be synced
  with BookStack.
- Knowledge article bodies must not repeat the article title as the first
  Markdown heading when the Knowledge page UI already renders the title.
- Micro-features and small workflow surfaces must also be considered for
  documentation when they affect user behavior, permissions, integrations, data,
  or future work.

## Testing Rules

- New features and behavior changes must include relevant Laravel tests unless
  the change is documentation-only, styling-only with no behavior impact, or
  explicitly approved as untested.
- Prefer feature tests for user-facing workflows, module routes, controller
  actions, validation, permissions, persistence, notifications, and integration
  boundaries.
- Use unit tests for isolated services, actions, formatters, parsers, and pure
  business logic.
- Regression fixes should include a test that fails before the fix and passes
  after it whenever practical.
- When changing shared behavior or cross-module contracts, add or update tests
  for affected modules, not only the module where the code was edited.
- Run the narrow relevant test set before handoff. For broad or release-oriented
  changes, run `HOME=/tmp php artisan test` when practical.
- If tests are not run, the final response must state that clearly with the
  reason.

## Multi-Agent Handover

- When finishing work, report files changed, behavior changed, tests run,
  deploy commands required, known risks, and follow-up TODO items.
- Do not overwrite or revert work from another contributor unless the user
  explicitly asks for it.
- If another contributor's changes affect the current task, work with those
  changes and call out conflicts before editing shared behavior.

If these rules are broken, the code is invalid.
