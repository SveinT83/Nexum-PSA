# tdPSA Rules (MANDATORY)

- ALL routes MUST be in Modules/*/routes.php
- DO NOT use routes/web.php for domain routes
- DO NOT create new files in routes/
- Controllers MUST be in Modules/{Domain}/Controllers
- Views MUST be in Modules/{Domain}/Views
- Module names MUST be singular (Client, Ticket, not Clients)
- When creating a new module or changing a module structure, you MUST read and follow `module-architecture.md`.
- See the `app/Modules/Skelteton` module for a reference implementation and additional instructions.
- Files MUST include clear English comments that explain structure, intent, and non-obvious behavior.
- Comments should help future debugging and maintenance; do not add noisy line-by-line comments for self-explanatory code.
- Blade views should use visible section/block comments for major layout areas, matching the existing project style.

If these rules are broken, the code is invalid.
