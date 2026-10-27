Warroom settings control the global Dashboard for all technicians.

Prerequisite: an active internal account with Warroom settings management
(`warroom.manage_settings`). Dashboard read access alone does not permit saving.

## Change the global Dashboard

1. Open **Admin -> Warroom**.
2. Under **Operational Windows**, set the SLA due soon and Inbox recent windows.
   Each window accepts 1 to 168 hours.
3. Set the latest Ticket, Asset alert, Calendar event and recent integration list
   limits. Each list accepts 1 to 20 rows.
4. Select at least one visible panel.
5. Select **Save Settings**.
6. Open **Dashboard** and confirm the authorized panels and list limits.

The available panels are Pulse metrics, Ticket fireline, Asset alerts, Calendar,
Domain radar, Integration health, Warroom lanes, System health and Next actions.
Panel selection does not grant access to the source modules or mailboxes.
Saved values affect subsequent Dashboard requests immediately.

These settings are global; there are no per-technician dashboard preferences.
My Day retains its personal previews of 8 Tickets, 8 Tasks and 12 events.

## Another person saved first

On Dev, a settings page carries the revision it loaded. If an API client or
another browser tab saves first, your older page cannot overwrite that save.

1. Reload **Admin -> Warroom**.
2. Review the current values.
3. Reapply only the changes you still need.
4. Select **Save Settings**.

A rejected stale save does not partially apply your values. If the settings lock
or database is unavailable, report the problem to the operator and reload after
recovery. Do not assume the save succeeded from a closed page or a retry alone.

## API and operating details

API managers can read and replace all the same fields through **Warroom API**.
Writes require a current revision and a unique idempotency key; exact replay and
operation read-back are available to the original authorized token.

The stored global settings use `common_settings`, type `warroom`, name
`dashboard`. The additive Warroom settings-command migration must be installed
before using the new revision-aware settings page or settings API.

Revision protection and the API are verified on Dev on 2026-10-27. Main promotion
and production rollout are separate; do not infer production availability here.
