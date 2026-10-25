Commercial packages define reusable bundles of services, legal terms, and pricing points that can be offered during sales and contract work.

## Purpose

Packages make repeatable offers easier to maintain. A package groups service selections, package-level terms, and pricing values so sales users do not need to rebuild common bundles manually.

## Package List Workflow

The packages index is an operational catalogue list.

The page keeps navigation in the compact page header and keeps package creation attached to the list card. `New Package` belongs in the package list card header because it creates a new row in that list.

The search card supports:

- Free-text search across package name, description, and status.
- Secondary status filtering behind the funnel button.
- Sortable table headings for name, description, service count, status, and updated time.

Rows are clickable and open the package detail view. The package name remains a direct link for accessibility.

## Operational Notes

- Keep package names short enough to scan in lists.
- Use descriptions for sales context, not long legal wording.
- Legal wording should stay in reusable terms and be attached to packages through the package legal section.
- Package pricing should be reviewed before using the package as a quote or contract starting point.

## Commercial API parity on Dev - 25 October 2026

The supported workflow now has a typed Commercial API on Dev, using shared domain actions,
current user rights, token scopes, strict fields and revision/replay checks.
See [Commercial API](https://doc.tronderdata.no/link/457) for exact operation sequences,
customer/portal identity, composition, timebank and immutable document boundaries.
This is not production availability. The additive migration and authenticated native HTTP
checks now pass on Dev. API delivery and browser durable delivery remain off by default
until controlled worker verification and deliberate activation. Svein Tore accepted the
completed delivery on 25 October 2026; HR-2026-10-25-COMMERCIAL-API is Reviewed.
Main promotion and production deployment remain separate actions.
