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
6. Read `docs/human-review.md` before preparing a merge, migration, deployment,
   release, or completion handoff for a large update. It is the source of truth
   for what still requires human verification and what a human has approved.
7. Use `README.md` for project overview and developer setup context only.
8. Check `docs/` for task-relevant plans, assessments, integration notes,
   security notes, beta checklists, RFCs, ADRs, and feature slices before
   changing an affected domain.
9. Treat old MVP/version-1 planning as historical context only. Nexum PSA is
   past MVP planning: beta is live, and current work prioritizes finishing,
   hardening, documenting, and polishing the existing product before starting
   new systems.
10. Treat `AGENT.md`, `CLAUDE.md`, `docs/CLAUDE.md`,
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
- When the user asks to plan an idea, RFC, or future module as the final or
  ultimate version, describe the full target behavior first. Use Feature
  Slices only after the target state is agreed, and do not present MVP/v1 as
  the product plan unless the user explicitly asks for that.
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

## Human Review Tracking

- `docs/human-review.md` is the authoritative, persistent record of manual
  verification for large updates.
- A large update includes every Level 2 or Level 3 change, completed Feature
  Slice, migration or data change, permission or integration change,
  cross-module change, substantial user-visible workflow, and broad
  merge/release candidate. Add Level 1 work when its user-facing risk makes
  manual verification useful.
- Before handing off a large update, add or update one review entry with a
  stable ID, scope, affected pages/workflows, concrete checks, expected results,
  relevant migrations/deploy actions, risks, and status.
- The final handoff must name the review ID, state which checks remain, and ask
  the user to perform the human review. Automated tests support this gate but
  never replace or complete it.
- Only explicit confirmation from a named human reviewer may change an entry to
  `Reviewed`. An AI agent must not infer approval from passing tests, a merge,
  deployment, silence, or an ambiguous reply.
- When the reviewer reports partial progress or defects, update the same entry
  and preserve unchecked or failed items. Use `In Review` or `Rework Needed`
  until the remaining checks are explicitly resolved.
- Before a merge, migration, deployment, or release, read the file and report
  all relevant entries that are not `Reviewed`. Do not delete reviewed history;
  retain it as the durable record of who reviewed what and when.

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
- For GitHub idea work, search existing docs and Discussions before creating a
  new item. If GitHub Discussions cannot be posted from the current session,
  save the approved text in a pending publication file under `docs/plans/` and
  do not substitute a GitHub Issue unless the user explicitly changes the
  process.
- When a GitHub comment, review, or Discussion is about product direction,
  privacy policy, settings, or another conceptual decision and the user has not
  clearly asked for implementation, treat "address this" as a discussion and
  response-drafting task first. Ask focused clarification questions, draft the
  reply, and only update files or post externally after explicit confirmation.

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
- The authenticated layout loads Livewire 3 through `@livewireScripts`, and
  Livewire owns the single Alpine runtime on those pages. Do not separately
  import or start `alpinejs` from `resources/js/app.js`; duplicate Alpine
  runtimes can leave visible `wire:click` controls disconnected.
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
- If relevant verification uncovers failing tests or runtime errors, do not
  call the work fully complete and move on just because the failures look
  pre-existing. Fix them, log an explicit approved deferral, or report the
  blocker; the user preference is to handle known verification failures before
  starting the next feature.
- Before pushing to `Dev`, run the relevant test set on the development server.
  Do not push code changes to `Dev` after unverified runtime work. If tests
  cannot be run because the Dev server, database, queue, or another dependency
  is unavailable, stop and report the blocker instead of pushing unless the user
  explicitly approves an untested push.
- If tests are not run, the final response must state that clearly with the
  reason.
- Passing automated tests does not mark a large update as human-reviewed. Keep
  the corresponding `docs/human-review.md` entry open until a named human
  reviewer explicitly confirms the listed checks.

## Local Tooling And Networked Services

- The SSH development server is an isolated development environment, and
  `/var/Projects/tdPSA` is the authoritative working copy for Nexum coding.
- ALL ordinary code implementation and code editing MUST happen directly in
  that Dev working copy. Do not implement in the local Windows workspace and
  copy or sync the result to Dev afterward.
- If the Dev server is unreachable, stop implementation and report the blocker.
  Do not fall back to local coding. Local work remains appropriate for
  read-only inspection and documentation/planning-only tasks.
- The only coding exception is an explicitly agreed special experiment that
  needs isolation. Use a dedicated branch/worktree, normally with the
  `codex/` prefix, and keep it separate from the ordinary Dev working copy.
- Before changing or syncing code on the development server, read the remote
  `/var/Projects/tdPSA/AGENTS.md`, verify the active branch/state, and do not
  assume the local Windows workspace is synced with remote `Dev`.
- Use the development server as the default runtime for Laravel verification:
  PHP, Composer, Artisan, migrations, scheduler, queue, and Laravel tests should
  normally run on the Dev server, not through local Windows PHP.
- Compiled Blade views under `storage/framework/views` must remain
  group-writable by the PHP-FPM group (normally files `0664`). Keep the
  directory's default group-write ACL, and set `umask 0002` before Artisan
  commands run as the SSH project user when they may render or rebuild views,
  including `php artisan view:cache` and view-rendering tests. Read-only
  compiled views can make Livewire actions return a server error while their
  buttons appear to do nothing.
- For production Nexum email or notification checks, verify users' current
  notification settings before sending tests or changing preferences. Report
  which notification types were already enabled or changed, keep test
  tickets/messages clearly internal, and state that inbox receipt still needs
  confirmation unless the mailbox can be verified.
- When sending commands from Windows PowerShell through SSH, keep remote
  one-liners simple. For PHP/Tinker checks or commands with shell-sensitive
  quoting, prefer a temporary script under `/tmp` on the development server and
  delete it after verification.
- When syncing code to the Dev server with `scp`, `rsync`, or similar tools,
  verify that new directories and files are readable by the web/PHP-FPM
  process, not only by the SSH user. New `scp`-created directories may inherit
  restrictive `700` permissions and make Laravel report misleading container or
  autoload errors such as `Target class ... does not exist`. After syncing new
  module trees or support classes, normalize permissions to match the existing
  repository style, for example directories `2755` and files `0644` owned by the
  project user/group, then run `php artisan optimize:clear` and perform a web
  HTTP smoke test for affected pages. Passing CLI tests as the SSH user is not
  sufficient when the browser path is affected.
- Do not run local Windows PHP/Composer for Laravel runtime verification unless
  the PHP version, required extensions, `vendor/`, built assets, and database
  access are already confirmed to match the project. If local PHP fails because
  of version, extension, `vendor`, Vite manifest, or database mismatches, stop
  and switch to the Dev server instead of retrying with platform-override
  workarounds.
- Local commands are still appropriate for read-only inspection, Git operations,
  text search, and targeted syntax checks that do not depend on Laravel runtime
  services. If the Dev server is unreachable and runtime tests cannot be run
  safely, report that clearly in the handoff.
- The Codex command sandbox may block raw sockets and outbound network access
  even when the web application can reach the same service normally.
- If a Laravel CLI command fails with a connection-level error against an
  external service that the running web app can use, verify whether the failure
  is caused by sandbox networking before changing application configuration.
- For this project, the development database may run on a Plesk/MySQL server on
  the local subnet. DB-dependent Artisan commands that need that external MySQL
  connection may need to be run outside the sandbox after confirming the target
  host and port are reachable.
- Do not use commands that print secrets, such as full database configuration
  dumps, unless there is no safer alternative. Prefer targeted checks that show
  host, port, connection status, and sanitized metadata only.

## Multi-Agent Handover

- When finishing work, report files changed, behavior changed, tests run,
  deploy commands required, known risks, follow-up TODO items, and the relevant
  human-review ID/status for every large update.
- Do not overwrite or revert work from another contributor unless the user
  explicitly asks for it.
- If another contributor's changes affect the current task, work with those
  changes and call out conflicts before editing shared behavior.

If these rules are broken, the code is invalid.
