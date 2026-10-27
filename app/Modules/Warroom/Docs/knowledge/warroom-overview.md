Warroom is the operational Dashboard for technicians and administrators.

## Open Dashboard or My Day

Prerequisite: an active internal account with Dashboard access (`warroom.view`).

1. Open **Dashboard** in the main navigation.
2. Select **Dashboard** for the operational overview, or **My Day** for your own work.
3. Use a card, queue row or work item to open its existing source workflow.

The Dashboard menu works on desktop and in the mobile navigation. My Day is also
listed after Knowledge in Warroom Lanes when those destinations are available.

Dashboard summarizes open Tickets and SLA pressure, unread/unassigned queues,
Asset alerts, Mail triage, Calendar focus, domain counts and integration health.
It uses global panel settings; personal dashboard layouts are not available.

## Personal work

My Day shows your assigned open Tickets and Tasks and your accessible personal
Calendar events for the current application date. Counts cover the full matching
scope; previews show up to 8 Tickets, 8 Tasks and 12 events.

- **Tickets** opens your open Tickets.
- **Tasks** opens your assigned work and excludes completed, Done and Cancelled Tasks.
- **Events** opens Calendar for the date shown in My Day.
- **Unread** opens your unread open Tickets.
- **Overdue** opens one combined list of your overdue open Tickets and Tasks.
- Individual Ticket and Task rows open their existing detail pages.

Use **Next** and **Previous** when the combined overdue list has more than one
page. Both Overdue entry points use the same scope. Calendar, Ticket, Task and
Mail actions keep their own permissions. **My workdays** and **My absences** appear
only when Workday is enabled and you have the corresponding own-work access.

## Storage pressure

When you can access Storage and at least one item has **Should order** status,
Dashboard shows **Storage needs ordering** and the count. Select **Open Should
order** to open the matching Storage filter. The warning is omitted at zero and
for people without Storage access.

## Access and availability on Dev

Verified on Dev on 2026-10-27: Dashboard and My Day now use the same source access
rules as their API. Dashboard access alone does not grant access to Tickets,
Tasks, Assets, mailboxes, private Calendar details or administrative health data.
Only authorized source data and destinations appear. Private Calendar details
remain masked as **Busy** when the source Calendar permits only free/busy access.

A disabled panel is hidden. An authorized empty queue has a real zero/empty state.
A source that cannot be read shows an unavailable notice; do not interpret that
notice as zero workload or healthy operation. Integration errors use a safe
attention status rather than raw provider error text.

For access problems, ask an administrator to check the owning module or mailbox
permissions. For unavailable data, report the named source and time to the
operator. Do not change permissions merely to remove an availability warning.

## API and release status

The Warroom API covers the operational overview, My Day and overdue work, global
settings and settings-operation read-back. See **Warroom API** for exact paths,
abilities and retry rules.

The API, shared source access and settings revision protection described above
are implemented and verified on Dev. Main promotion and production deployment
are separate; this article does not assert that the new behavior is live in production.

## Warroom API reference

The official Warroom API covers all current Dashboard, My Day and global settings
workflows. This capability is verified on Dev as of 2026-10-27; Main promotion and
production deployment require separate acceptance.

## Prerequisites

Use a trusted HTTPS Nexum API origin and an unexpired bearer token belonging to
an active internal user. An API ability narrows existing user permissions; it
does not grant module, record, mailbox or private Calendar access.

Swagger lists the exact request and response schemas under **Warroom**.

| Method | Path below `/api/v1/warroom` | Token ability | Native permission |
| --- | --- | --- | --- |
| GET | `/context` | `warroom.read` | `warroom.view` |
| GET | `/dashboard` | `warroom.read` | `warroom.view` |
| GET | `/my-day` | `warroom.read` | `warroom.view` |
| GET | `/my-day/overdue` | `warroom.read` | `warroom.view` |
| GET | `/settings` | `warroom.settings.read` | `warroom.manage_settings` |
| PUT | `/settings` | `warroom.settings.manage` | `warroom.manage_settings` |
| GET | `/operations/{command}` | `warroom.settings.manage` | `warroom.manage_settings` |

A delegated settings manager does not need Dashboard read permission to read or
save settings. Source workflow links keep their own domain API and permissions;
Warroom does not perform Ticket, Task, Mail or Calendar writes.

## Read the operational picture

1. Read `/context` for available source states, panel choices, limits, authorized
   destinations and token-dependent settings capabilities.
2. Read `/dashboard` for configured authorized operational panels and source states.
3. Read `/my-day` for the signed-in user's own open work and Calendar window.
4. Read `/my-day?focus=overdue` or `/my-day/overdue` for the same combined overdue scope.

My Day counts cover the full authorized personal scope. Ticket/Task/Event previews
are capped at 8/8/12 rows. Overdue accepts `page` (1..10000) and `per_page`
(1..100, default 25), with deterministic due-date/type/ID ordering and an exact
total. Pagination on `/my-day` requires `focus=overdue`.
There is no arbitrary user/date override.

Timestamps contain an explicit offset; `timezone` and `window` describe the
application's current day. Source states distinguish `available`,
`inaccessible` and `unavailable`; configured panel states also include
`hidden`. Unavailable or inaccessible personal totals are null, never invented
zero values. Lists contain explicitly allowed fields, not raw source records.
Provider error bodies and mailbox content are not returned. Routine mail totals use ordinary owner/shared/delegated access; emergency mailbox access remains in Mail with its required use audit.

## Save settings safely

1. GET `/settings`. Keep the `ETag` / `data.revision` and full `data.settings`.
2. Adjust the desired values. Send all seven fields in one PUT:
   `due_soon_hours`, `inbox_recent_hours`, `latest_tickets_limit`,
   `latest_alerts_limit`, `calendar_today_limit`,
   `recent_integrations_limit`, and `enabled_sections`.
3. Send the exact revision in `If-Match` and a new 8..200 character
   `Idempotency-Key`.
4. Confirm the returned settings, new revision and `operation_id`.
5. GET `/operations/{command}` with the original token for the exact durable result.

The hour windows accept 1..168; list limits accept 1..20. `enabled_sections` is
a nonempty list of distinct keys from `section_options`. Unknown fields and
unsupported filters are rejected. Settings writes and their encrypted receipts
commit in one transaction. Browser and API saves use the same lock/revision.

For a lost response, retry the identical request with the original key, body and
If-Match. The API returns the same operation/result with `replayed=true`.
Do not substitute a new key to bypass an uncertain result. A different request
with the old key returns 409. A stale revision returns 412: reload, review and use
a new key for your next intended save.

## Troubleshooting and evidence

- 401: missing, expired or revoked bearer token.
- 403: current native permission or token ability is missing; neither substitutes
  for the other. Rights are checked again on receipt lookup and replay.
- 404: operation is absent or belongs to another actor/token.
- 409: an idempotency key was reused for a different request.
- 412: state changed; reload before applying new edits.
- 422: invalid field, range, panel list or query.
- 428: required revision or idempotency key is missing.
- 503: required migration, lock or data source is unavailable. A successful
  mutation is not confirmed; use exact read-back/retry after recovery.

GET requests do not change source state. Routine token last-use accounting is
authentication metadata. An accepted request is not evidence of source-domain
delivery or production deployment. Retain the operation ID when asking an operator
to investigate a settings result; never send bearer tokens or credentials.
