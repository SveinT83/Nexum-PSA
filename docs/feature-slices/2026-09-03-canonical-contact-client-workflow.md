# Feature Slice: Canonical Contact Client Workflow

Status: Done On Dev
Date: 2026-09-03
Parent: ../rfc/2026-09-03-canonical-contact-workflow-and-legacy-cutover.md
Owner: Codex

## Goal

Make Contact the one technician-visible workflow from both Client and Contacts entry points.

## User-Visible Behavior

- Client Contacts lists the same canonical records as central Contacts.
- New Contact from a Client opens the standard Contact form with the Client locked.
- Contact detail and edit always use Contact-owned pages.
- Creating a Client creates its primary person as a canonical Contact.
- Old Client User URLs redirect into the canonical workflow.

## Scope

- Contact-owned Client query.
- Client Contacts tab links and canonical field rendering.
- Explicit Client/Site context for Contact create.
- Legacy Client User route redirects and write normalization.
- Stable bridge synchronization through StoreContact.
- Contact permission aliases and canonical audit event.
- Client and Contact feature tests.

## Out Of Scope

- Removing client_users.
- Rewriting Ticket, Asset, Sales, or Nextcloud storage.
- Manual Contact merge UI.

## Data Touched

- contacts and Contact child tables.
- contact_relations.
- client_users compatibility rows.
- activity_log.
- Client and Contact module routes, controllers, actions, queries, views, and tests.

## Permissions

- contact.view protects canonical detail and list access.
- contact.create protects every Contact creation alias.
- contact.update protects every Contact edit/update alias.
- contact.delete remains unchanged.
- Client access does not grant Contact mutation implicitly.

## Tests

- Cross-list visibility from both entry points.
- Exact canonical route and form reuse.
- Duplicate Contact prevention.
- Stable bridge ID during canonical edit.
- Legacy redirect permission boundaries.
- Canonical primary Contact during Client creation.

## Documentation

- Contact README and Knowledge.
- Client Knowledge.
- RFC, ADR, TODO, and human-review.

## Done Criteria

- Client and Contacts display the same canonical records.
- Client creation and all legacy Client Contact aliases write through StoreContact.
- A canonical edit does not replace an existing compatibility bridge ID.
- Contact permissions protect legacy aliases as well as canonical routes.
- Focused and affected tests pass, and the broad Dev-suite outcome is recorded with unrelated
  failures kept as a separate follow-up.
- HR-2026-09-03-005 records Svein's 2026-09-04 Dev UI approval and the remaining production
  deployment operations.
