# Feature Slice: Contact Portal Invitation Override

Status: Done
Date: 2026-07-28
Parent: `docs/rfc/2026-07-04-customer-portal-foundation.md` and GitHub issue #185
Owner: Codex

## Goal

Let an authorized user follow or override the Contact-wide portal-invitation default for one
Contact create action without weakening CustomerPortal invitation safeguards.

## User-Visible Behavior

- Contact Settings includes a default for selecting `Send customer portal invitation` on Contact
  create.
- Authorized users can turn the option on or off before saving the Contact.
- The option is never shown during ordinary Contact edit.
- A selected option saves the Contact and queues one Customer Portal invitation for the chosen
  Client/Site scope.

## Scope

- Store the installation default in the existing Contact `common_settings` payload.
- Add the create-only switch to the shared Contact Livewire form.
- Require the existing `customer_portal.invite` permission in both rendering and submission.
- Save the Contact, relations, invitation, and audit boundary transactionally.
- Reuse CustomerPortal's invitation action and queued email delivery.
- Record `contact_create` as the invitation source.
- Update Contact and CustomerPortal tests and documentation.

## Out Of Scope

- Sending or resending invitations from Contact edit.
- Changing Customer Portal roles from the Contact form; Contact create uses `Viewer`.
- Applying the UI default to the Contact API or other domain-owned quick-create forms.
- Changing invitation acceptance, expiry, email templates, accounts, or memberships.
- Adding a new permission, route, migration, queue, scheduler, or frontend build step.

## Data Touched

- Existing `common_settings` Contact defaults payload.
- Existing Contact, email, Client/Site relation, and `client_users` compatibility records.
- Existing CustomerPortal invitation and audit records.
- Existing Customer Portal invitation email queue job.

## Permissions

- `contact.manage_settings` continues to protect the global Contact default.
- `contact.create` continues to protect Contact creation.
- `customer_portal.invite` is required to see or submit the invitation switch.
- No new permission is introduced.

## Tests

- Global-on with per-Contact override off creates the Contact without an invitation.
- Global-off with per-Contact override on creates one scoped Viewer invitation.
- Ordinary Contact edit never shows or sends the option.
- A Livewire submission without `customer_portal.invite` cannot force an invitation.
- Existing Contact and CustomerPortal feature suites remain green.

## Documentation

- Update Contact README and Knowledge documentation.
- Update CustomerPortal README and Knowledge documentation.
- Update the existing Contact Settings entry in `docs/TODO.md`.
- Add a focused entry to `docs/human-review.md`.

## Verification

- The complete Contact and CustomerPortal foundation suites pass with 49 tests and 336 assertions.
- The focused default, override, edit, permission, and scope run passes with 5 tests and 29
  assertions.
- Pint, PHP syntax, `git diff --check`, Blade compilation, and compiled-view group-write checks pass.
- Repository Knowledge synchronization processed one Contact and one CustomerPortal
  chapter/article without skips.
- HTTPS smoke tests for Contact create and Contact Settings return the expected unauthenticated
  login redirect.
- No migration, permission seed, queue restart, scheduler change, or frontend build is required.

## Done Criteria

- All GitHub issue #185 acceptance criteria are implemented.
- The default is safe for installations without a stored setting.
- Existing email, scope, identity, duplicate-access, audit, and queue guards remain authoritative.
- The checkbox cannot be used without `customer_portal.invite`.
- Relevant tests, Knowledge sync, Blade compilation, and HTTPS smoke tests pass on Dev.
