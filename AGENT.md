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

## Dev SSH Access

The Dev SSH endpoint is `sveintore@192.168.2.201`; the remote project is
`/var/Projects/tdPSA` on branch `Dev`. On Windows Codex Desktop, select the
dedicated current-user key explicitly because its filename is non-default:

```powershell
ssh.exe -i "$HOME\.ssh\nexum_dev_ed25519" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes sveintore@192.168.2.201
```

Do not rely on a password being present in a new chat, and never copy a private
key or plaintext password into the repository, logs, chat, command output, or
documentation. A plain SSH attempt may miss the approved key and is not
evidence that Dev is unavailable. If the exact key command fails, report
whether the failure is reachability, host-key verification, or authentication.

Separate authenticated SSH connections may run in parallel. `MaxSessions`
does not impose a total per-user cap across those connections.

Before changing remote files, read `/var/Projects/tdPSA/AGENTS.md`, verify the
active branch and worktree state, and preserve unrelated changes.

Operational note: if Artisan commands need the external development MySQL
server and fail with socket/connection errors from an AI tool sandbox, follow
the local tooling rules in `AGENTS.md` before changing `.env` or database
configuration.
