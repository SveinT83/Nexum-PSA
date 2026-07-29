# RFC: Default Manual Client Tickets To Published

Status: Approved / Implemented
Date: 2026-07-28
Owner: Svein Tore / Codex
Change level: Level 3 customer-facing Ticket and Customer Portal workflow default
GitHub issue: #191
Implementation approval: Approved by Svein Tore on 2026-07-29
Related RFC: `docs/rfc/2026-07-04-customer-portal-foundation.md`
Related feature slice: `docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md`

## Context

Manually created client Tickets already have an explicit Customer Portal visibility policy. Ticket
Settings can store either `Published` or `Unpublished`, and the Ticket create form exposes the same
per-Ticket choice. The clean-install and missing-setting fallback is currently `Unpublished`, so a
technician must publish the Ticket explicitly before the customer can see or reply to it.

Issue #191 changes the installation fallback to `Published` while retaining the administrator's
stored choice and the technician's per-Ticket override. This is a Level 3 change because a
customer-facing default changes the privacy and workflow boundary shared by Ticket, Customer Portal,
and Notification behavior.

## Goals

- Make `Published` the default for new manually created client Tickets when no explicit valid Ticket
  portal-policy setting exists.
- Preserve an explicitly saved administrator choice of either `Published` or `Unpublished`.
- Keep a visible Published/Unpublished control on the manual Ticket create form.
- Preselect the configured installation default and preserve a technician override after validation
  errors.
- Publish only Tickets linked to a valid Client, using the existing portal timestamp and actor
  fields.
- Keep internal Tickets without a Client outside the Customer Portal even if a request is
  manipulated.
- Leave existing Tickets and existing notification/message behavior unchanged.
- Add regression coverage for the default, overrides, client scope, portal metadata, and email
  boundary.

## Non-Goals

- Do not retroactively publish existing Unpublished Tickets.
- Do not remove the administrator's Unpublished option or overwrite a valid stored setting.
- Do not make internal Tickets without a Client customer-visible.
- Do not add a database column, migration, backfill, route, permission, API endpoint, notification
  type, email job, queue, scheduler, or frontend dependency.
- Do not change one-way portal publishing or add normal-page unpublishing.
- Do not change Tickets created from inbound email, Customer Portal, API, RMM, rules, or automation.
- Do not turn the initial manual description into a public customer message.

## Current Behavior

- `TicketPortalPolicy::settings()` falls back to `Unpublished` when the setting is missing, malformed,
  or contains an unsupported visibility value.
- `TicketPortalPolicy::update()` also uses `Unpublished` when the supplied value is absent or invalid.
- Ticket Settings lets an administrator persist either valid visibility value.
- The manual Ticket create form already renders a Customer Visibility card with both choices, uses
  `old()` to retain input, and receives the installation default from `TicketController::create()`.
- `TicketController::store()` validates the submitted value against the server-owned options and
  otherwise reads the policy default.
- The store flow calls the existing portal-publishing helper only when the created Ticket has a
  Client and the effective choice is `Published`.
- The publishing helper sets `portal_visible_at` and `portal_visible_by`, records the existing Ticket
  event, and emits the existing `portal_ticket_created` Customer Portal notification.
- Initial manual descriptions are stored as internal notes. Creating or publishing a Ticket does not
  dispatch `SendTicketReplyEmail`; Customer Portal notifications continue to use each recipient's
  existing notification-channel preferences.
- Existing Unpublished Tickets remain hidden until the established one-way publish action is used.

## Proposed Change

### Policy Default

Add one explicit source-of-truth constant in `TicketPortalPolicy` for the installation fallback and
set it to `VISIBILITY_PUBLISHED`. Use that constant whenever the persisted setting is missing,
malformed, unsupported, or omitted from a direct policy update call.

A valid stored `Published` or `Unpublished` value remains authoritative. Reading the policy must not
rewrite the setting row, and no migration or startup task will insert or replace settings.

### Ticket Settings And Create Form

Keep both visibility choices in Ticket Settings and on the manual Ticket create form. Replace the
view-level `Unpublished` fallback with the policy's new default constant so rendering remains
consistent even when view data is unexpectedly absent.

The create form will continue using `old('customer_portal_visibility', ...)`, so a submitted
technician choice survives validation errors. Update the concise help text to say that Published
makes a client Ticket immediately customer-visible and Unpublished keeps it internal until later
publication. The copy will also state that the setting has no portal effect without a selected
Client.

The selected visibility remains an explicit form field. A technician may choose Unpublished even
when the installation default is Published, and may choose Published when an administrator has
saved Unpublished.

### Server Enforcement And Publishing

Keep the existing allow-list validation for `customer_portal_visibility`. If the field is absent,
resolve the value through `TicketPortalPolicy::defaultCustomerVisibility()`.

After the Ticket is created, call the existing portal-publishing helper only when both conditions
are true:

- the effective visibility is `Published`; and
- the persisted Ticket has a valid `client_id`.

The helper remains responsible for setting `portal_visible_at`, recording the authenticated
technician in `portal_visible_by`, adding the existing audit event, and emitting the existing
Customer Portal notification. The Client check remains server-side, so submitting `Published` for
an internal Ticket cannot set portal visibility fields or notify portal users.

### Notification And Message Boundary

Do not introduce a customer reply, new mail job, new notification type, or extra notification call.
The existing `portal_ticket_created` notification remains the only customer-facing notification
associated with publishing a manual client Ticket and continues to honor the recipient's established
notification-channel preferences. `SendTicketReplyEmail` must not be queued merely because the
installation fallback is now Published.

This preserves existing behavior for Tickets that were already explicitly created as Published.
The default change only decides which existing visibility path is selected when no administrator or
technician override applies.

## Impact Analysis

### Affected Areas

- `TicketPortalPolicy` fallback behavior.
- Ticket Settings and manual Ticket create form fallback/help text.
- Manual Ticket controller feature coverage.
- Ticket and Customer Portal regression tests.
- Ticket Knowledge documentation and the existing Customer Portal Ticket feature-slice record.

### Permissions, Privacy, And Scope

- Existing Ticket create permissions and Customer Portal membership scope remain unchanged.
- Visibility values remain server-validated from the policy's allow-list.
- A Client relationship remains mandatory for portal publication.
- Customer Portal queries remain responsible for Client/Site membership filtering.
- Explicit administrator and technician choices take precedence over the new installation fallback.
- Existing rows are never updated by this change.

### Routes, Data, Integrations, And Runtime

- No route, model, schema, migration, API, integration contract, queue topology, scheduled task, or
  asset-build change is required.
- No deploy command beyond the normal cache/view refresh is expected.
- The existing Customer Portal notification action remains in use without a new event type or
  recipient rule.

### Risks And Side Effects

- A missing or malformed setting will now expose newly created client Tickets by default, so the
  create form must make the choice conspicuous and the documentation must state the new behavior.
- A technician may unintentionally publish customer-sensitive content if the visibility choice is
  overlooked; explicit form wording and human review are required.
- Changing fallback code in only one layer could make Ticket Settings, the form, and store behavior
  disagree; one policy constant and regression tests will keep them aligned.
- A manipulated request must not make an internal Ticket portal-visible.
- The existing portal notification can use mail when the recipient has enabled that channel; this is
  preserved notification behavior, not a newly introduced customer-reply email.
- Invalid historical settings will resolve to Published after this change. Valid stored
  Unpublished settings remain unchanged and authoritative.

## Data And Migration Plan

No schema migration, data migration, seed update, or backfill is required. Existing Ticket rows and
valid Ticket portal-policy settings are not modified. Installations without a valid stored setting
begin resolving the default as Published when the application code is deployed.

Rollback restores the fallback constant to Unpublished. Tickets created as Published while the new
code was active remain Published because the established one-way visibility fields are historical
audit data; rollback must not erase or rewrite them.

## Testing Plan

- Policy test: no settings row resolves to Published.
- Policy test: malformed or unsupported policy data resolves to Published without rewriting storage.
- Policy test: explicitly stored Unpublished and Published values are each preserved.
- Admin feature test: Ticket Settings still renders and persists both choices.
- Create-form feature test: the visibility field visibly renders both choices and preselects the
  configured default.
- Validation feature test: an explicit technician choice survives a failed submission.
- Store feature test: a manual client Ticket with no submitted override defaults to Published and
  records the correct `portal_visible_at` and `portal_visible_by` values.
- Store feature test: an explicit Unpublished override remains hidden and emits no portal publish
  event or notification.
- Scope feature test: a forced Published value on a Ticket without a Client leaves both portal
  visibility fields empty and emits no customer notification.
- Regression test: existing Unpublished Tickets remain unchanged and hidden.
- Notification regression test: default publication retains the existing Customer Portal
  notification behavior and does not queue `SendTicketReplyEmail` or create a public initial
  message.
- Run focused Ticket/Customer Portal tests, the complete Ticket feature suite, Blade compilation,
  PHP syntax checks, and Dev HTTPS smoke checks for Ticket Settings and Ticket create.

## Documentation Plan

- Update `app/Modules/Ticket/Docs/knowledge/ticket-admin-settings.md`.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-overview.md`.
- Update `docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md` to record the changed
  current fallback while retaining its historical implementation context.
- Update `docs/TODO.md`, this RFC index, and `docs/human-review.md` after implementation.
- Sync the Ticket Knowledge articles through the repository Knowledge command.
- Add a public-safe website handoff item after implementation verification and keep it unpublished
  while human review remains open.

## Open Questions

None. Issue #191 defines the default, override, scope, history, notification, testing, and
human-review boundaries, while the current implementation already provides the required policy,
form control, publishing metadata, and server-side Client gate.

## Approval

Approved by Svein Tore on 2026-07-29.

## Implementation

Implemented on Dev on 2026-07-29. `TicketPortalPolicy::DEFAULT_CUSTOMER_VISIBILITY` now provides one
Published fallback for policy reads, direct policy updates, Ticket Settings, and the manual Ticket
form. Valid saved settings and per-Ticket choices remain authoritative. Existing Client enforcement,
portal timestamps, actor audit, one-way publishing, and Customer Portal notification behavior are
preserved; internal Tickets remain hidden and no `SendTicketReplyEmail` job is added.

The focused portal suite passes with 10 tests and 94 assertions, the complete Ticket feature suite
passes with 158 tests and 1211 assertions, and the complete Customer Portal feature suite passes with
20 tests and 229 assertions. Ticket Knowledge is synchronized, the public-safe website handoff is
recorded but not publishable, and manual verification remains open under `HR-2026-07-29-001`.
