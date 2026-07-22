# ADR: Automated Release Version And Build Identity

Status: Accepted
Date: 2026-07-16
Decision Makers: Svein Tore / Codex

## Context

Nexum needs a customer-readable product version and an exact deployed build identity. Updating an
environment variable for every commit, merge, or deployment is unreliable and makes it unclear
whether a number represents a release or merely a source revision. The repository currently has
legacy `Beta` and `Beta2` tags, a GitHub release named `v0.2.0-beta`, and an application fallback of
`0.1.0`.

The selected process must automate version calculation without turning every commit or merge into a
public release. It must also let each installation report the exact commit that is running.

## Decision

- Product versions follow Semantic Versioning and change only through a release pull request.
- Release Please runs on pushes to `main`, reads Conventional Commits, and creates or updates one
  release pull request.
- Merging the generated release pull request updates `version.txt` and `CHANGELOG.md`, creates a
  semantic `v...` tag, and publishes the GitHub release.
- Nexum remains on semantic beta prereleases until a deliberate stable-release policy change.
- Normal commits and normal merges do not require a manual version bump.
- The application reads its installed product version from `version.txt`, with `APP_VERSION` as an
  optional artifact/deployment override.
- The application reads its deployed commit from Composer root-package metadata, with
  `APP_COMMIT_SHA` as an optional artifact/deployment override.
- Deployments refresh Composer metadata before caching Laravel configuration.
- The Admin status compares release version and commit distance separately.

## Rationale

Release Please separates everyday development from intentional releases while calculating the next
version and changelog automatically. Conventional Commit categories provide a reviewable rule for
patch, minor, and breaking changes. A release pull request keeps the final publication decision
visible to a human without requiring that person to calculate a version.

Composer records the root package's source reference during `composer install` after checkout.
Using that reference avoids running Git commands during web requests and works for the project's
normal source deployment. Explicit environment overrides retain compatibility with packaged or
container deployments.

## Consequences

- Contributors should use `fix:`, `feat:`, and breaking-change markers for releasable changes.
- GitHub Actions must have permission to update contents and create release pull requests.
- The first generated semantic tag supersedes the legacy `Beta`/`Beta2` naming convention without
  deleting historical tags.
- `version.txt` and `CHANGELOG.md` become release-controlled source files.
- A deploy that skips Composer metadata refresh may report a stale commit; deployment documentation
  and checks must call this out.
- A normal merge can change the deployed commit count while leaving the product version unchanged.
  This is intentional and is why Admin displays both values.

## Alternatives Considered

- **Bump the version on every commit:** rejected because it creates noisy product versions and
  makes every implementation detail look like a release.
- **Bump the version on every merge to `main`:** rejected because merges used for integration or
  follow-up fixes should not automatically become public releases.
- **Edit `APP_VERSION` manually during deployment:** rejected because it is easy to forget and can
  disagree with the deployed source.
- **Use only commit SHAs:** rejected because customers and release notes need a readable product
  version.
- **Run Git commands from web requests:** rejected because deployment metadata should be prepared
  before serving requests and must not depend on shell access.

## Follow-Up

- Implement the two Feature Slices under
  `docs/rfc/2026-07-16-version-and-github-update-status.md`.
- Add and verify the GitHub Action, manifest, version file, and changelog.
- Verify repository Action permissions when the workflow first reaches `main`.
- Update deployment and Knowledge documentation.
- Revisit prerelease settings when Nexum is approved for its first stable release.
