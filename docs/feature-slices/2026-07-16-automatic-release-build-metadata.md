# Feature Slice: Automatic Release And Build Metadata

Status: Done
Date: 2026-07-16
Parent: `docs/rfc/2026-07-16-version-and-github-update-status.md`
Owner: Codex

## Goal

Remove manual version and commit maintenance by making GitHub own release calculation and making
each deployment expose its exact source commit automatically.

## User-Visible Behavior

- The installed version comes from a release-controlled source file.
- A normal deployment reports the exact Composer-recorded commit without an `.env` edit.
- GitHub maintains a release pull request that calculates the next semantic beta version and
  changelog.

## Scope

- Add `version.txt`, `CHANGELOG.md`, Release Please manifest/config, and a `main` workflow.
- Bootstrap from the existing `0.2.0-beta` release and current `main` history boundary.
- Read the installed version and commit through application configuration with optional environment
  overrides.
- Document Conventional Commits and the release pull-request lifecycle.

## Out Of Scope

- Automatic deployment after a release.
- Publishing package-manager artifacts.
- Automatically merging the release pull request.
- Converting Nexum from beta to stable.

## Data Touched

- GitHub Actions configuration.
- Release manifest, changelog, and version source file.
- Laravel application configuration and deployment documentation.
- No database data.

## Permissions

The GitHub workflow needs repository contents and pull-request write permissions. No Nexum user
permission changes.

## Tests

- Validate JSON and YAML configuration.
- Verify Laravel configuration reads `version.txt`, Composer commit metadata, and explicit
  overrides.
- Verify existing version consumers continue to use the same configured application version.
- Confirm workflow behavior on `main` after the change is merged.

## Documentation

- Update README deployment/release guidance.
- Update the parent RFC, ADR, TODO, and System Knowledge documentation.

## Done Criteria

- No normal source deploy requires manual `APP_VERSION` or `APP_COMMIT_SHA` edits.
- Release Please configuration is valid and ready to create/update a release pull request on
  `main`.
- The semantic beta version and exact commit are available to application code.

## Verification

- Release Please JSON and workflow YAML parse successfully.
- Dev `composer install` refreshed the Composer root reference to repository HEAD `42a08a7`.
- System metadata and status tests passed as part of 31 System tests / 197 assertions.
- The first real Release Please pull request remains verifiable only after this workflow reaches
  `main`.
