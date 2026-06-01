# tdPSA Agent Entry Compatibility

`AGENTS.md` is the authoritative instruction file for tdPSA / Nexum PSA.

Some tools look for `AGENT.md` while others look for `AGENTS.md`. This file
exists only to catch tools that use the singular filename.

Before making code changes, read and follow:

1. `AGENTS.md`
2. `docs/development/ai-team-process.md` for medium or large work
3. `docs/module-architecture.md` when changing modules, routes, controllers,
   views, or domain ownership
4. `docs/ui-guidelines.md` when changing UI, Blade, layout, navigation,
   components, or page styling
5. `docs/TODO.md` before planning or implementing work

This file must not override `AGENTS.md`.
