Open **Admin > Integrations > Tripletex > Settings**. An active administrator needs the explicit
`integration.tripletex_manage` permission.

## Account and employee setup

Each Nexum installation uses one Tripletex account and its own encrypted token. The token
field displays **Leave blank to keep the existing token**; it never displays the stored token.
**Verify company** checks the company identity. **Read employees and activities** refreshes
the employee, activity and project choices without importing any time.

While synchronization is paused, select the Nexum employee, Tripletex employee, default
activity and first date to synchronize. Employees are never matched by name automatically.
An established employee mapping cannot be reassigned because its delivery history must remain
bound to the original employee. Other employees are outside the synchronization scope.

## Start and stop

The **Synchronize time registrations automatically** switch is below the account settings.
It saves immediately. On enables the mapped two-way runtime; off stops imports and exports
while preserving local time, mapping, delivery baselines and pending changes. Both directions
use the same switch. Saving connection details pauses synchronization; replacing the token
also resets verification readiness.

The server must have the time-sync runtime enabled and a verified write contract. Missing
prerequisites produce an error instead of silently displaying an active integration.
Only mapped dates from the configured start date are eligible; historical data is not imported
by default.

## Record, edit and remove time

In Workday, **Save interval** or **Save time** records effective time immediately. There is no
separate daily confirmation or import acceptance. Existing private drafts remain private until
the employee explicitly saves them through this workflow. Older draft/preview/confirm API
operations retain their previous behavior for compatibility.

Imported time appears as a duration with an activity and optional project. It has no fabricated
start/end time. It can be edited and saved in Nexum. Enter hours with up to two decimals.
For example, 45 minutes is 0.75 hours. Tripletex stores hundredths of an hour (36-second units);
a one-minute clock entry therefore becomes 0.02 hours (1.2 minutes). When rounding changes the
duration, Nexum keeps the original clock details as provenance and uses one effective duration,
without counting both.

Set a duration row to **0** to remove it. Removing part of a clock aggregate changes the linked
Tripletex total; removing its last interval deletes the linked row. A verified deletion in
Tripletex likewise removes the effective local time. Revision history and a delivery baseline
remain until the original retention deadline. Retention cleanup never deletes Tripletex time.

For this pilot, clock work crossing midnight must be entered separately for each date.
Source allocations that cannot remain valid after an imported duration change are held for
reconciliation; source Task/Ticket time is never silently rewritten.

## Background status and exceptions

The scheduler runs every five minutes. It checks today, pending edits and rotating older dates
inside the retained scope. Older history may take several scans; a successful scan is not a
claim that every historical date was checked.

Workday shows pending, synced, paused or attention status. Synced requires provider read-back.
Settings show dates that need attention. Simultaneous incompatible edits preserve both values;
align the correct time through an authorized Workday or Tripletex edit. Locked provider rows
are not changed or reopened. Network interruptions preserve durable intent and recover using
the provider's unique employee/date/activity/project key, avoiding duplicate creation.

See the [pilot verification](../../../../../docs/plans/2026-10-05-tripletex-time-sync-verification.md)
for tested behavior, deployment requirements and remaining rollout checks.

## Customer-number synchronization

The separate **Synchronize customers with Tripletex** switch makes Tripletex authoritative for new/linked Client
numbers. It does not activate time transfer. See [customer numbers](tripletex-customer-numbers.md)
for creation, explicit links, recovery and the independent activation requirements.
Saving connection settings pauses both customer and time synchronization.
