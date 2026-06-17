# RFC: Marketing API Surface

Status: Approved
Date: 2026-06-17
Owner: Codex

## Context

Nexum PSA already has Sanctum-based API routes for Clients and Contacts. Marketing has production
web workflows for mailing lists, campaigns, campaign emails, approval, due sending, AI assists, and
settings, but it does not expose those workflows over the API.

External automations now need to create clients and contacts, add contacts to marketing lists,
create mailing lists, create campaigns, manage campaign emails and schedule settings, approve
campaigns, queue due sends, and manage marketing settings.

This is a Level 3 change because it adds API routes, API scopes, and cross-module behavior across
Marketing, Contact, Integration, Email, and Clients.

## Goals

- Keep existing Client and Contact APIs as the source for client and contact creation.
- Let Contact API writes set `do_not_email` and `marketing_consent` so explicit opt-in lists can be
  populated through integrations.
- Add Marketing-owned API routes for mailing lists and materialized members.
- Add Marketing-owned API routes for campaign creation, campaign updates, schedules, campaign
  emails, approval, due-send queueing, test-send, and AI draft endpoints.
- Add Marketing-owned API routes for reading and updating marketing settings.
- Add explicit Marketing API scopes to the Integration API catalog.
- Reuse existing Marketing actions so API behavior matches the technician UI.

## Non-Goals

- Replacing the technician UI.
- Adding a new marketing engine outside the existing Marketing module.
- Adding WordPress, Google, or social publishing integrations in this slice.
- Creating a separate seller call list or engagement list workflow inside Marketing.
- Sending unapproved campaigns automatically.

## Proposed Change

Add these scopes:

- `marketing.read`
- `marketing.lists.manage`
- `marketing.campaigns.create`
- `marketing.campaigns.update`
- `marketing.campaigns.approve`
- `marketing.campaigns.send`
- `marketing.settings.update`

Add `/api/v1/marketing/*` endpoints for:

- Mailing list list/create/show/update/delete.
- Mailing list member read, refresh, add Contact, and remove Contact.
- Campaign list/create/show/update.
- Campaign schedule update.
- Campaign email create/update/delete/test-send/AI draft.
- Campaign AI plan, approval, and due-send queueing.
- Marketing settings read/update.

The API controllers must use the same validation rules and action classes as the current web
controllers where practical.

## Impact Analysis

- Marketing owns the new API routes, controllers, resources, tests, and documentation.
- Integration owns the new scope names in the API ability catalog.
- Contact accepts and persists marketing preference fields already exposed by Contact resources.
- Email templates and sender accounts remain owned by Email.
- No schema migration is required.
- Due-send API calls queue the existing `SendDueMarketingCampaignEmails` job.

## Testing Plan

- Feature test list and campaign API workflow with Sanctum scopes.
- Feature test Marketing read-only API tokens cannot mutate Marketing data.
- Feature test Contact API can persist marketing consent and opt-out fields.
- Run focused Marketing and Contact feature tests where the local PHP environment supports them.

## Documentation Plan

- Update Integration API Management Knowledge documentation with scopes and routes.
- Update Marketing Knowledge/README documentation with the new API surface.
- Update `docs/TODO.md` active workstream note.

## Approval

Approved by Svein Tore Ramstad in conversation on 2026-06-17 after requesting the APIs for Clients,
Contacts, Marketing mailing lists, campaigns, campaign emails, settings, and technician Marketing
workflows.
