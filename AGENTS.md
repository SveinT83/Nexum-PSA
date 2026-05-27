# tdPSA Agent Instructions (MANDATORY)

This is the main instruction file for AI-assisted work in tdPSA. Keep it short and use the
specialized Markdown files below as the detailed source of truth when they are relevant.
Most project-wide supporting documents live in `docs/`.

## Read Order

1. Always read this file before making code changes.
2. Read `docs/module-architecture.md` before creating a module, changing module structure, adding
   routes, moving controllers, moving views, or changing domain ownership.
3. Read `docs/ui-guidelines.md` before changing UI, layout, Blade views, shared components,
   navigation, page styling, or page-specific CSS.
4. Use `README.md` for project overview and developer setup context only.
5. Check `docs/` for task-relevant plans, assessments, integration notes, and release documents
   before changing an affected domain. Examples include security notes, integration plans, beta
   checklists, and module-specific design notes.
6. Treat `docs/nexum-psa-v1.md` as historical MVP planning unless the task explicitly references
   the original phase 1 scope.
7. Treat `docs/CLAUDE.md` and `docs/juneGuidelines.md` as legacy assistant notes. They must not
   override this file, `docs/module-architecture.md`, or `docs/ui-guidelines.md`.

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
