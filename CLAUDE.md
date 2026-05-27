# Claude Entry Instructions

`AGENTS.md` is the authoritative instruction file for tdPSA.

Before making code changes, read and follow:

1. `AGENTS.md`
2. `docs/module-architecture.md` when changing modules, routes, controllers, views, or domain ownership
3. `docs/ui-guidelines.md` when changing UI, Blade, layout, navigation, components, or page styling
4. Any task-relevant documents in `docs/`

This file exists only so Claude-based tools discover the tdPSA instruction chain. It must not
override `AGENTS.md`, `docs/module-architecture.md`, or `docs/ui-guidelines.md`.

Legacy Claude and Laravel Boost context lives in `docs/CLAUDE.md`.
