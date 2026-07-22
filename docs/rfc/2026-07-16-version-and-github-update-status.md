# RFC: Application Version And GitHub Update Status

Status: Approved / Implemented
Date: 2026-07-16
Owner: Svein Tore / Codex
Change level: Level 3 external integration and shared UI
Implementation approval: Approved by Svein Tore on 2026-07-16

## Context

Nexum already reads the installed application version from `APP_VERSION` and displays it in the
shared technician footer. The current default is `0.1.0`, while GitHub's latest published release
is named `v0.2.0-beta` and is attached to the legacy `Beta2` tag. Nexum does not currently know the
commit SHA that was deployed, query GitHub for release information, or calculate whether an
installation is behind its configured update branch.

Administrators need two related but distinct signals:

- the installed release version and whether GitHub has a newer published release;
- the deployed commit and how it compares with the branch used by that environment.

These signals must stay distinct. An installation can be on the latest published version while
still being behind a development branch, or it can run commits newer than the latest release.

## Goals

- Keep only the installed version number in the shared footer.
- Show a compact application status summary on the right side of the Admin page header.
- Tell administrators when a newer GitHub release is available.
- Show how many commits the deployed build is behind or ahead of its configured update branch.
- Show honest `unknown`, `diverged`, `stale`, and GitHub-unavailable states.
- Avoid making ordinary page rendering depend on GitHub availability or response time.
- Standardize release and deployment metadata for future versions.
- Keep the implementation read-only and require no GitHub write permission.

## Non-Goals

- Do not pull code, run deployment commands, or offer automatic updates from the web UI.
- Do not compare uncommitted working-tree changes with GitHub.
- Do not call GitHub from every page or every request.
- Do not expose a GitHub token, full API response, or sensitive response headers in the UI or logs.
- Do not infer a commit count when the deployed commit SHA is unavailable.
- Do not treat the configured update branch as the same thing as the latest release.
- Do not add a database table or persist release history in this change.

## Current Behavior

- `config/app.php` reads `APP_VERSION` and defaults to `0.1.0`.
- `.env.example` declares `APP_VERSION=0.1.0`.
- `resources/views/layouts/default_tech.blade.php` displays the configured version in the footer.
- The Admin landing page has unused space on the right side of its compact page header.
- No deployed commit SHA or update channel is configured.
- No System-owned query or GitHub client provides application update status.
- The GitHub repository is public and its default branch is `main`.
- GitHub's latest release on 2026-07-16 is `v0.2.0-beta`, attached to the non-semantic `Beta2` tag.

## Proposed Change

### Release And Deployment Metadata

Use release-controlled source metadata and automatically generated deployment metadata as the
authoritative installed values:

- `version.txt`: installed semantic product version without a leading `v`, for example
  `0.2.0-beta`. Release automation owns this file.
- Composer root-package metadata: automatic fallback for the full commit SHA deployed to the
  installation. Normal deployment refreshes it through `composer install` after checkout.
- `APP_VERSION`: optional explicit override for packaged deployments that cannot use
  `version.txt`.
- `APP_COMMIT_SHA`: optional explicit override for artifacts that cannot expose Composer's root
  package reference.
- `APP_UPDATE_BRANCH`: branch against which commit distance is measured. Default to `main`, while
  the development environment may explicitly use `Dev`.
- `APP_GITHUB_REPOSITORY`: repository in `owner/name` form, defaulting to
  `SveinT83/Nexum-PSA`.
- `APP_GITHUB_TOKEN`: optional read-only token for higher rate limits or a future private
  repository. The public repository must work without a token.

The deployment process must refresh Composer metadata before Laravel configuration is cached.
Runtime web requests must not execute Git commands to guess the deployed commit. Ordinary source
deployments require no manual version or commit edits in `.env`.

Future GitHub release tags should use `vMAJOR.MINOR.PATCH` with an optional semantic prerelease
suffix, for example `v0.3.0-beta.1`. During transition, the client may use a valid semantic version
from the release name when the legacy tag is not semantic. The existing `Beta2` release therefore
remains readable as `v0.2.0-beta`, but this fallback must not become the normal release process.

### Automatic Versioning Policy

Product versions and build identity have different lifecycles:

- A normal commit does not change the product version.
- A normal merge to `main` does not immediately publish a new product version.
- Every deployed build receives its exact commit SHA automatically.
- A new product version is created only when the generated release pull request is reviewed and
  merged.

Add Release Please as a GitHub Action on `main`. It will read Conventional Commit messages and keep
one release pull request up to date:

- `fix:` proposes a patch release;
- `feat:` proposes a minor release;
- `feat!:` or another `!`/`BREAKING CHANGE` marker proposes the configured breaking release;
- documentation and maintenance commits that do not change the product do not force a release.

While Nexum remains beta, generated releases use semantic beta versions and GitHub prereleases. The
initial manifest records the existing product version as `0.2.0-beta`; the first generated release
uses a semantic `v...` tag and ends the legacy `Beta`/`Beta2` naming pattern. When Nexum becomes
stable, changing the release channel is a deliberate release-policy update, not a per-commit task.

Release Please updates `version.txt` and `CHANGELOG.md` in the release pull request. Merging that
pull request creates the GitHub tag and release. No contributor or deployer calculates or types the
next version number manually.

### System-Owned Status Query

Add a read-only application-version status client and query under the `System` module. It will:

1. Read and validate the configured installed metadata.
2. Request GitHub's latest published, non-draft release.
3. Compare `APP_COMMIT_SHA...APP_UPDATE_BRANCH` through GitHub's compare endpoint when a local SHA
   is configured.
4. Normalize the result into a small view model rather than passing raw GitHub payloads to Blade.

The normalized result should include:

- installed version and short commit SHA;
- latest release version, URL, and publication time;
- configured update branch;
- commits ahead and behind;
- comparison state: `current`, `behind`, `ahead`, `diverged`, or `unknown`;
- release state: `current`, `update_available`, or `unknown`;
- last successful check time and whether the result is stale.

Version comparison must use semantic-version rules. A stable release is newer than a prerelease of
the same numeric version. Invalid installed or remote versions produce `unknown`; they must not be
compared lexically.

### Availability, Caching, And Rate Limits

- Cache a successful GitHub result for 30 minutes.
- Retain the last successful result as stale data for up to 24 hours when a refresh fails.
- Cache failed refresh attempts briefly so repeated Admin requests do not hammer GitHub.
- Use short connection and total timeouts and at most one bounded retry for transient failures.
- Treat 403 rate-limit responses, 404 responses, malformed payloads, and network errors as
  unavailable states without failing the Admin page.
- Never make the shared footer call GitHub.
- Allow the cache to be refreshed naturally; a manual refresh control is outside this first slice.

### Footer

Keep the footer limited to the installed version, for example `v0.2.0-beta`. Improve contrast if
needed so the value remains readable in both light and dark themes. Do not add release, commit,
branch, warning, or network status to the footer.

Although the UI guidelines discourage a generic footer in the operational shell, this change keeps
the existing footer and adds only the operational version value explicitly requested. A broader
footer/status-bar redesign remains outside this RFC.

### Admin Header

Move the Admin landing page route, controller, and view into the `System` module while preserving
the existing URL and route name. This is required before changing that surface so the implementation
does not extend the legacy global Admin route/view ownership.

The right side of the Admin page header will display a compact, responsive status group. Example
states:

- `v0.2.0-beta` | `Up to date` | `Dev: 0 commits behind`
- `v0.2.0-beta` | `New release v0.3.0 available` | `main: 12 commits behind`
- `v0.3.0-dev` | `Latest release v0.2.0-beta` | `Dev: 3 commits ahead`
- `v0.2.0-beta` | `Commit unknown`
- `v0.2.0-beta` | `GitHub status unavailable`

The release version may link to the GitHub release. The UI must identify stale status and the last
successful check time. On small screens the status group wraps below the Admin title without
creating horizontal overflow. This information remains limited to the Admin page, which is already
permission protected.

### Release And Commit Meaning

The UI must not collapse release and branch comparisons into one ambiguous `outdated` flag:

- `New release available` means the latest published semantic release is newer than
  `APP_VERSION`.
- `N commits behind` means GitHub reports that the configured update branch contains commits not in
  `APP_COMMIT_SHA`.
- `N commits ahead` means the deployed commit has commits not in the configured branch.
- `Diverged` means both sides contain unique commits and both counts must be shown.

## Impact Analysis

### Affected Areas

- `System` module: application status client/query, Admin controller/view ownership, tests, and
  Knowledge documentation.
- Shared tech layout: footer version presentation only.
- Configuration and deployment documentation: version, commit, repository, branch, and optional
  token values.
- GitHub: read-only release and compare API calls.

### Permissions

No new Nexum permission is required. The detailed status is shown only on the existing Admin hub,
which already requires Admin access and `system.view` routing permission. GitHub access is read-only.

### Routes

The existing `/tech/admin` URL and `tech.admin.index` route name remain stable. Ownership moves from
the legacy `routes/techAdmin.php` closure to a controller and route in
`app/Modules/System/routes.php`.

### Data, Queues, And Scheduler

- No database migration or backfill.
- No queue or scheduler is required in the first slice.
- Laravel cache stores the bounded status data.

### External Integration And Security

- Only fixed GitHub API paths built from validated configuration are allowed.
- Repository and branch values are deployment configuration, not request input.
- An optional token is read from environment configuration, sent only to GitHub, and never returned
  to the view model.
- Logs may contain a sanitized failure category and HTTP status, but not authorization headers or
  raw payloads.

### Side Effects And Risks

- An unset or incorrect `APP_COMMIT_SHA` makes commit distance unknown; the UI must say so.
- If the deployed commit is not reachable from GitHub, comparison is unknown rather than zero.
- GitHub outages or rate limits can make status stale; cached data and an honest warning prevent
  the Admin page from failing.
- Legacy non-semantic release tags can make comparison ambiguous; the release-name fallback covers
  `Beta2`, while future release tags must be semantic.
- A development build may be ahead of the latest release. Showing release and branch signals
  separately prevents a false update warning.
- The Admin landing page and shared layout currently contain unrelated uncommitted work. The
  implementation must preserve and build on those changes without reverting them.

## Data And Migration Plan

No database migration is required.

Existing installations remain usable with `APP_VERSION` and `APP_COMMIT_SHA` overrides. Normal
source deployments instead read `version.txt` and Composer's root-package reference automatically.
If neither automatic nor explicit commit metadata is available, commit distance is explicitly
unknown.

Required deployment actions after implementation:

1. Run `composer install` after checking out the deployed commit so the root-package reference is
   current. `composer dump-autoload` alone does not refresh this reference.
2. Set only the environment-specific `APP_UPDATE_BRANCH` when it differs from `main`; Dev uses
   `Dev`.
3. Optionally set `APP_VERSION`, `APP_COMMIT_SHA`, `APP_GITHUB_REPOSITORY`, or
   `APP_GITHUB_TOKEN` when deployment packaging requires overrides.
4. Run the normal frontend build when changed files require it.
5. Run `php artisan optimize:clear` and rebuild cached configuration.

Rollback removes the header status and new configuration reads. No data rollback is required.

## Testing Plan

- Unit-test semantic-version normalization, prerelease ordering, and legacy release-name fallback.
- Unit-test GitHub response normalization for current, behind, ahead, diverged, invalid, missing
  commit, not-found, rate-limit, and network-error states.
- Test that successful results are cached and failed refreshes use labeled stale data.
- Feature-test that the footer shows only the configured installed version.
- Feature-test the Admin header for up-to-date, new-release, commits-behind, diverged, missing-SHA,
  stale, and unavailable states with Laravel HTTP fakes.
- Assert that the Admin route is owned by the System module and retains its existing name and URL.
- Assert that non-admin users cannot access the detailed Admin status.
- Run focused System feature and unit tests on the development server.
- Perform an authenticated Dev HTTP smoke test and visual checks in light, dark, desktop, and mobile
  layouts.

## Documentation Plan

- Add a System Knowledge article explaining installed version, release status, commit comparison,
  cache age, and unknown states.
- Update `.env.example` and deployment/setup documentation with the new metadata variables.
- Document the semantic GitHub release-tag convention.
- Document the generated release pull request and Conventional Commit prefixes.
- Update `docs/TODO.md` as implementation progresses.
- Add a human-review entry before implementation handoff.

## Open Questions

None blocking implementation.

## Approval

Approved by Svein Tore on 2026-07-16 with the additional requirement that version and commit
metadata must be automatic and must not depend on remembering manual `.env` edits.
