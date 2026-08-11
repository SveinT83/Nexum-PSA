# Feature Slice: AI API Key Least Privilege

Status: Done
Date: 2026-07-14
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Codex

## Goal

Make API-key creation fail safe before coordinator scopes or policy controls are added.

## User-Visible Behavior

API abilities are unchecked by default. An administrator must deliberately select at least one
ability or separately confirm full access. Existing broad tokens are listed for review and can be
rotated or revoked; they are not silently rewritten.

## Scope

- Change empty ability normalization so it cannot grant every catalogued ability.
- Validate that ordinary tokens have at least one selected ability.
- Keep full access behind an explicit confirmation separate from the ability list.
- Add explicit read/write metadata to the ability catalog and stop inferring access mode from suffixes.
- Show existing full/broad tokens as requiring review.

## Out Of Scope

- New coordinator endpoints.
- New privacy/governance tables.
- Automatic token rotation or revocation.

## Data Touched

- Existing Sanctum `personal_access_tokens` records are read for review but not migrated.
- Integration API Management controller, catalog, view, and documentation.

## Permissions

Existing Integration Admin/API-key management permissions and Admin middleware remain in force.

## Tests

- Empty ordinary selection is rejected.
- Explicit selected abilities are stored exactly.
- Explicit full access remains possible for non-coordinator integrations.
- Read/write metadata classifies progressive read abilities correctly.
- Existing tokens are not modified by viewing the review state.

## Documentation

Update API Management Knowledge documentation and the parent RFC/TODO progress.

## Done Criteria

- No UI or request path converts no selection into broad access.
- Focused Integration tests pass on Dev.
- Existing broad tokens are visible for deliberate review.
- No existing token is mutated automatically.
