# tdPSA Agent Instructions (MANDATORY)

This is the main instruction file for AI-assisted work in tdPSA. Keep it short and use the
specialized Markdown files below as the detailed source of truth when they are relevant.
Most project-wide supporting documents live in `docs/`.

These rules apply to human developers, Codex, Claude, ChatGPT, autonomous agents, scripts, and
future AI integrations. If another agent-specific file conflicts with this file, this file wins.

## Read Order

1. Always read this file before making code changes.
2. Read `docs/development/ai-team-process.md` before planning medium or large work, coordinating
   multiple contributors, creating RFCs/ADRs, or making changes that affect more than one module.
3. Read `docs/module-architecture.md` before creating a module, changing module structure, adding
   routes, moving controllers, moving views, or changing domain ownership.
4. Read `docs/ui-guidelines.md` before changing UI, layout, Blade views, shared components,
   navigation, page styling, or page-specific CSS.
5. Use `README.md` for project overview and developer setup context only.
6. Check `docs/` for task-relevant plans, assessments, integration notes, and release documents
   before changing an affected domain. Examples include security notes, integration plans, beta
   checklists, and module-specific design notes.
7. Check `docs/TODO.md` before planning or implementing work. If the current change touches an
   area with related future work, account for that future direction in the design so new code does
   not create avoidable rework or conflict with known planned capabilities.
8. Treat old MVP/version-1 planning as historical context only. Nexum PSA is past MVP planning:
   beta is live, and current work prioritizes finishing, hardening, documenting, and polishing the
   existing product before starting new systems.
9. Treat `CLAUDE.md`, `docs/CLAUDE.md`, and `docs/juneGuidelines.md` as compatibility entry files.
   They must point back to this file and must not override this file, `docs/module-architecture.md`,
   `docs/ui-guidelines.md`, or `docs/development/ai-team-process.md`.

## Source Of Truth

- Documentation defines intended behavior, approved direction, and business rules.
- Code, migrations, tests, and current database state define implemented behavior.
- If documentation and implementation disagree, stop and clarify before changing behavior.
- Never silently choose one side when the mismatch could affect data, permissions, workflows,
  integrations, or customer-facing behavior.

## Product Priority

- Current work is beta completion toward the finished Nexum PSA product, not MVP/version-1
  exploration.
- Before proposing or implementing new systems, first improve existing modules so they work
  reliably, have the required settings, are documented, have tests, and are prepared for planned
  future capabilities.
- Missing beta-critical capabilities discovered during discussion or implementation must be added
  near the top of `docs/TODO.md`.
- New domains and large new capabilities should wait unless they unblock beta completion or have an
  approved RFC.

## Change Levels

- Level 1 small fixes: typo, clear bug, narrow validation fix, small styling adjustment, or
  documentation-only update. Read affected files, test the relevant behavior, and update docs only
  when behavior changes. No RFC is required.
- Level 2 feature or workflow changes: new setting, changed domain behavior, new admin flow, or
  significant UI behavior. Create an RFC before implementation, get approval, read relevant module
  docs and TODO, perform impact analysis, update Knowledge/docs, and add tests.
- Level 3 domain, database, integration, permission, API, or cross-module changes: create an RFC
  before implementation, get approval, document impact, test affected modules, and create an ADR
  when an architectural decision is made.

## Pre-Implementation Analysis

- Before medium or large implementation work, read the relevant docs, routes, controllers, models,
  requests, views, permissions, tests, workflows, TODO items, RFCs, and ADRs.
- Identify affected modules, dependencies, data migrations, permissions, integrations, risks, and
  missing documentation.
- Ask one focused clarification question at a time when a missing decision blocks safe progress.
- For small obvious fixes, keep momentum but still verify the affected code and tests.

## RFC And ADR Rules

- RFC means Request For Change. Use `docs/processes/rfc-process.md` for the process and template.
- ADR means Architecture Decision Record. Use `docs/processes/adr-process.md` for the process and
  template.
- Feature Slice means a small, complete, testable implementation unit under an approved RFC,
  beta-completion item, or documented maintenance task. Use `docs/processes/feature-slice-process.md`
  for the process and template.
- RFC approval is required for Level 2 and Level 3 changes.
- ADRs are required for significant architecture, package, data model, integration, authentication,
  authorization, workflow, or infrastructure decisions.
- Feature Slices are required when an approved RFC or beta-completion item is too large to implement
  safely in one pass. Small Level 1 fixes do not need a Feature Slice unless they reveal broader
  follow-up work.

## Mandatory Architecture Rules

- ALL domain routes MUST be in `app/Modules/{Domain}/routes.php`.
- DO NOT use `routes/web.php` for domain routes.
- DO NOT create new files in `routes/`.
- Controllers MUST be in `app/Modules/{Domain}/Controllers`.
- Views MUST be in `app/Modules/{Domain}/Views`.
- Module names MUST be singular, for example `Client` and `Ticket`, not `Clients`.
- See the `app/Modules/Skelteton` module for a reference implementation and additional
  instructions.

## Laravel Boost

- Laravel Boost is installed and should be used for Laravel-specific investigation when relevant.
- Prefer Boost-provided framework context, docs, and tooling before guessing Laravel behavior.
- Use Boost alongside the project standards in this file; Boost must not override tdPSA module
  architecture or UI rules.
- If Boost guidance conflicts with `docs/module-architecture.md` or `docs/ui-guidelines.md`, the
  tdPSA project standards win.

## UI And Component Rules

- Reuse global Blade components from `resources/views/components` wherever practical before creating
  module-specific markup or components.
- Prefer shared components for common UI elements such as buttons, cards, form controls, navigation,
  and repeated layout patterns to reduce maintenance.
- Blade views should use visible section/block comments for major layout areas, matching the
  existing project style.
- Do not expose UI controls for unfinished or stubbed functionality. If a button, toggle, setting,
  route, or menu item is visible, the underlying behavior must be implemented, tested, and honest
  about what it does. Future ideas belong in docs/TODO/Knowledge, not active UI.

## Multi-Agent Handover

- When finishing work, report files changed, behavior changed, tests run, deploy commands required,
  known risks, and follow-up TODO items.
- Do not overwrite or revert work from another contributor unless the user explicitly asks for it.
- If another contributor's changes affect the current task, work with those changes and call out
  conflicts before editing shared behavior.

## Comment And Documentation Rules

- Files MUST include clear English comments that explain structure, intent, and non-obvious
  behavior.
- Comments should help future debugging and maintenance; do not add noisy line-by-line comments for
  self-explanatory code.
- When a domain is completed or materially updated, Knowledge documentation MUST be created or
  updated for the affected functionality so it can be synced with BookStack. Keep documentation
  split into focused pages for major features, workflows, settings, and operational behavior.
- Knowledge article bodies MUST NOT repeat the article title as the first Markdown heading when the
  Knowledge page UI already renders the article title.

## Testing Rules

- New features and behavior changes MUST include relevant Laravel tests unless the change is
  documentation-only, styling-only with no behavior impact, or explicitly approved as untested.
- Prefer feature tests for user-facing workflows, module routes, controller actions, validation,
  permissions, persistence, notifications, and integration boundaries.
- Use unit tests for isolated services, actions, formatters, parsers, and pure business logic.
- Regression fixes MUST include a test that fails before the fix and passes after it whenever
  practical.
- When changing shared behavior or cross-module contracts, add or update tests for the affected
  modules, not only the module where the code was edited.
- Run the narrow relevant test set before handing work back. For broad or release-oriented changes,
  run `HOME=/tmp php artisan test` or clearly explain why it could not be run.
- If tests are not run, the final response MUST state that clearly with the reason.

If these rules are broken, the code is invalid.
