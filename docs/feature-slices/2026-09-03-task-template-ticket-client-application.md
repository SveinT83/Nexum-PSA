# Feature Slice: Manual Ticket And Client Template Application

Status: Done On Dev
Date: 2026-09-03
Parent: `docs/rfc/2026-09-03-task-templates-and-scheduled-generation.md`
Owner: Codex

## Goal

Apply one current Task template manually from an authorized Ticket or Client.

## User-Visible Behavior

Users select a template, preview generated Tasks/relationships/dates, optionally override assignee or
date anchor, and confirm once. Ticket application returns to the originating Ticket with its created
Tasks visible; other owner flows may open the created work.

## Scope

Ticket/Client entry points, preview projection, owner/context authorization, application call,
idempotent confirmation, tests, responsive UI, and Knowledge.

## Out Of Scope

Other owner domains, schedules, rule actions, versions, approvals, and broad per-field overrides.

## Data Touched

Generation runs and Tasks; no Ticket or Client business data is rewritten.

## Permissions

Requires owner visibility plus `task.create`; template management is not required to apply.

## Tests

Ticket/Client context, permissions, preview no-write behavior, duplicate submit, inactive templates,
cross-client denial, and narrow layout.

## Documentation

Task, Ticket, and Client Knowledge plus human review.

## Done Criteria

Both owner surfaces apply the same Task action safely and focused/cross-module tests pass.

## Dev Evidence

Ticket and Client Tasks surfaces link to an active-template chooser and no-write preview. Applying
requires `task.create` plus owner visibility, rejects inactive templates, returns Ticket applications
to the originating Ticket, and uses a stable confirmation key. Focused permission/context tests pass;
responsive human review remains open in `HR-2026-09-03-001`.
