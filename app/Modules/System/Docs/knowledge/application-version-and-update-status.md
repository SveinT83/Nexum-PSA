Nexum separates the readable product version from the exact build that is deployed.

## What The Interface Shows

- The shared footer shows only the installed product version, for example `v0.2.0-beta`.
- The right side of the Admin header shows the installed version and short commit ID.
- After the Admin page has loaded, Nexum checks GitHub for the latest published release and compares
  the deployed commit with the configured update branch.
- The comparison can report up to date, commits behind, commits ahead, diverged, commit unknown, a
  cached result, or GitHub unavailable.

The initial Admin page does not wait for GitHub. A GitHub outage therefore does not block normal
administration.

## Automatic Version Policy

Ordinary commits, ordinary merges, and deployments do not require a manual version edit.

- `version.txt` contains the product version and is maintained by the generated release pull
  request.
- Composer records the exact source commit when deployment runs `composer install` after checkout.
- A normal deployment can therefore change the displayed commit while keeping the same product
  version.
- Release Please calculates the next Semantic Version from Conventional Commits on `main`.
- Merging the generated release pull request updates `version.txt` and `CHANGELOG.md`, creates the
  semantic tag, and publishes the GitHub release.

Use `fix:` for a patch-level correction, `feat:` for a minor feature, and a breaking-change marker
only for an intentional breaking release. Nexum stays on beta prereleases until the release policy
is deliberately changed.

## Environment Configuration

- `APP_UPDATE_BRANCH` selects the comparison branch. Use `Dev` on the development server and
  `main` in production.
- `APP_GITHUB_REPOSITORY` identifies the repository in `owner/name` form.
- `APP_UPDATE_PRERELEASES` controls whether beta releases are considered.
- `APP_GITHUB_TOKEN` is optional for this public repository and can be added for a higher API rate
  limit.
- `APP_VERSION` and `APP_COMMIT_SHA` are optional overrides for packaged deployments. Normal source
  deployments should not maintain them manually.

Deployment must run `composer install` before Laravel configuration is cached so the root-package
commit reference is current. `composer dump-autoload` by itself does not refresh that reference.
Clear or rebuild Laravel configuration cache after changing any update setting.

## Caching And Failure Handling

Successful GitHub status is cached for 30 minutes. The last successful result is retained for 24
hours and marked as cached if a refresh fails. After a complete failure, Nexum waits five minutes
before trying GitHub again. No GitHub token or raw API response is exposed in the interface.
