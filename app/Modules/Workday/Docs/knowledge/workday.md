Workday lets employees record and save their own actual work.

## Save and synchronize

**Save interval** and **Save time** now record effective actual time immediately. Employees do
not need a separate confirmation. Tripletex-origin durations can also be edited and saved here.
Where an administrator has enabled the mapped connection, changes and deletions synchronize
automatically in both directions. See the [Tripletex guide](../../../Integration/Docs/knowledge/tripletex-connection.md).
Existing private drafts are not silently published or exported; explicitly saving them opts
into the effective workflow. The older draft/preview/confirm API is retained for compatibility.

## Record actual work

Open **My Day > My workdays**. A month calendar appears at the top, with today active.
Use the month/day arrows, select a date, or choose **Today**. Browsing does not create a record.

The day below is a calendar timeline with hourly grid lines. Each saved interval is one block:
its position and height show the exact start and duration. A 45-minute meeting occupies three
quarters of an hour; 90 minutes occupies one and a half hours. The grid is a visual guide,
not a rounding rule. Clock input uses whole minutes. For a mapped Tripletex employee, effective duration uses hundredths of an hour; incompatible clocks are retained as provenance and replaced by duration-only time.

The first free period of up to one hour in your saved work plan is selected. For a 09:00 start,
09:00-10:00 is selected initially; after that is registered, the next selection is 10:00-11:00.
A shorter gap stays shorter so it cannot overlap the next record or extend the plan. Explicit
absence and work reserved on adjacent dates are excluded. Unknown plans have no invented start;
choose a time manually. **Show full day** exposes time outside the normal working hours.

Click an empty time in the calendar to open the **Register time** modal. The **Time entry**
button opens the current selection, including the initial first-free-hour suggestion.
Set exact **Start**, **End** and **Activity**, then choose
**Save interval**. The default one-hour selection can be changed to any supported minute duration.
The first save creates effective actual time; later saves preserve other intervals and breaks.
Opening a date or selecting time never saves or confirms work.

## Edit a registered block

Click a saved block to open the **Edit time** modal. Change its start, end or activity, then **Save interval**. The selected
outline follows the edited duration. Overlap with other registered work is rejected; touching
endpoints are allowed. The server also rechecks adjacent-day reservations and concurrent writes.
**Registered intervals** provides an accessible list for very short blocks.

**Remove interval** stages removal; use **Save day details** to persist it. The last work
interval can be removed; its versioned empty day records the removal for synchronization. A source allocation or
break that no longer fits must be corrected before the changed draft can be saved.

Inside the modal, **Day description and breaks** contains the general description and explicit break treatment.
**Work - unspecified** is valid. **Save day details** saves these changes without adding the
pending selected hour. Unsaved interval edits require a discard choice before selecting another
block; leaving the page also warns about unsaved changes. **Close**, Escape or a backdrop click
closes the modal without saving. Pending values remain on the page; use **Time entry** to reopen
them. A server validation error reopens the modal, focuses the error and preserves submitted values.

Work intervals include their breaks. Choose whether each break counts as actual time.
Eight elapsed hours minus an excluded 30-minute break means 450 actual minutes.
Included breaks remain part of the total. Plans and billing increments do not change it.

New records use the timezone from **Profile > Work plan**; saved records retain their original
timezone. For overnight work, select the following date for End. During a repeated clock hour,
choose the explicit **clock-change occurrence**. Repeated hours appear separately in the grid
and actual elapsed minutes determine block height. API timestamp validation is unchanged.

The plan stays in UserManagement/Calendar and supplies suggestions after dated availability
and absence; it never becomes actual work automatically. Previously saved profile hours without
dated Calendar projection apply to today/future dates only, without inventing historical plans.
Existing saved actual time always takes precedence. Employee access requires both Workday
activation settings; the Dev pilot is active and production review remains pending.

## Legacy draft, review and confirm compatibility

This section applies only to clients deliberately using the legacy draft API. The current
**Save interval** and **Save day details** buttons record effective time immediately and do
not require these separate confirmation steps.

1. The legacy draft endpoint stores a new private draft revision.
2. **Review saved draft** displays exactly that saved revision and its totals.
3. Check the confirmation statement and choose **Confirm my workday**.

Unsaved form edits are not included in the preview. A preview is valid for 30 minutes and becomes
invalid when the saved draft or an overlapping absence changes. Selected source versions and access are also rechecked. Future work may be drafted, but its intervals must have ended
before confirmation.

Your own confirmation completes the Nexum workflow. No manager approval or invoice line is
created. Work plans are context; they do not establish or approve actual time.

## Legacy confirmed-day correction compatibility

Current effective Save can edit a legacy confirmed day directly, retaining its history.
Only the legacy API correction workflow uses these steps: enter a reason and choose **Start correction**. Edit and save the replacement draft, then review
and confirm it. The last confirmed version remains effective while the correction is pending.
Its intervals remain reserved against overlaps until replacement confirmation.

Earlier revisions stay visible in **Revision history**. A stale browser or API request is rejected;
reload the current day before resubmitting. Repeated identical API requests return their original
receipt instead of creating another revision.

## Access and retention

The separate permissions are `workday.view_own`, `workday.manage_own`,
`workday.confirm_own` and `workday.manage_settings`. Default internal roles receive their own
employee workflow; Admin and Superuser also receive settings access. Custom internal roles may
receive explicit permissions. Neither Admin nor Superuser can use these operations to edit or
read another employee's private day. Confirmed cross-employee oversight is available through the separate workday.view_all permission; see the confirmed-oversight guide.

Records, revisions and owned receipts have a three-year deadline anchored to the work date.
The deadline is midnight after the third anniversary in the original record timezone; corrections
do not extend it. Expired days are excluded from reads and writes. Bounded automatic cleanup and a restore gate are implemented with a separate, default-off
activation switch. See [Retention](retention.md) and the operator runbook before enabling normal use.

Ordinary day, confirmation and allocation writes do not update Task, Ticket, billing or time-bank entries.
Explicit internal Task conversion creates a Task and non-billable actual source time only after its
own preview and acceptance; see [Task conversion](task-conversion.md). Source reconciliation,
confirmed oversight and profile reminders are available when enabled. Tripletex remains future scope. Simple absence is available through My absences when enabled.
Overlapping absence produces a neutral warning before confirmation. Review it and explicitly
acknowledge any remaining overlap; no actual time is changed automatically.

## Attribute existing source time

After saving a day, open **Review sources and allocations**. Task/Ticket time and explicitly
selected Calendar evidence can explain minutes within your actual total. Date-only sources do
not invent intervals. Estimates and calendar plans require explicit verification. Review the
source-reconciliation guide for limits, placement, source changes and privacy.

## Workday through the existing ChatGPT connection - 30 October 2026

For employees whose personal Nexum connection has been configured, use the existing
**Trønder Data – MCP** plugin in ChatGPT. Workday runs through its existing **nexumpsa**
connection. You do not need a separate Workday integration or a manually managed LiteLLM key.

1. Select the existing **Trønder Data – MCP** plugin in ChatGPT.
2. Ask it to read your Workday for the intended date before recording anything. The date
   context must show your own day, current version and record timezone. A plan or suggestion
   is not proof of work performed.
3. State the actual intervals and whether each break is included. Review the date, timezone
   and total before instructing the assistant to save. The current API action is **workdaySave**.
4. After saving, ask for a fresh read of that same day and compare intervals, breaks and total.
   Do not blindly repeat a failed save with a new retry key.

Access remains personal: another employee's day and administrative settings are not included.
A missing or revoked personal connection must fail rather than use a shared administrator.
For 401, ask the integration administrator to check the personal Nexum binding. For 403,
ask them to check the token scope and native Workday permission. For 422, obtain the exact
field error before changing the request. Never paste passwords or tokens into the chat.

For Norwegian local input, check **Profile > Work hours** and the date context for
**Europe/Oslo**. Profile/work-plan settings take precedence over older Preferences values.
A previously saved day keeps its own timezone: changing the profile does not reinterpret
historical intervals or change recorded minutes. Ask an administrator before correcting a
historical timezone; do not shift recorded work to compensate manually.

Availability: the gateway's personal list/date-context reads and denial checks are verified
for the configured incident accounts in production. Actual employee ChatGPT use and a real
employee-approved save remain human acceptance. The Ticket/Task local-display source fix is
still Dev-only until separately approved Main promotion and production deployment.
