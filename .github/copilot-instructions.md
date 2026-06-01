# Copilot Entry Instructions

`AGENTS.md` is the authoritative instruction file for tdPSA / Nexum PSA.

Copilot and other editor assistants must follow:

1. `AGENTS.md`
2. `docs/development/ai-team-process.md` for medium or large work
3. `docs/module-architecture.md` for module/domain ownership changes
4. `docs/ui-guidelines.md` for UI work
5. `docs/TODO.md` before planning or implementing work

Important project-specific reminders:

- Use Bootstrap, not Tailwind.
- Keep domain code inside `app/Modules/{Domain}`.
- Keep domain routes in `app/Modules/{Domain}/routes.php`.
- Do not create domain routes in Laravel's default `routes/` directory.
- Do not expose UI controls for unfinished behavior.

This file must not override `AGENTS.md`.
