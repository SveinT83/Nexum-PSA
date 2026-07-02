Ticket time registration records technician time on a ticket without immediately consuming a contract timebank or creating invoice lines.

Ticket billing remains client-gated. Internal Tickets have no `client_id`, so their time entries can
still record operational effort but are not selected for Economy order generation.

Technicians add time from the ticket show page using the `Add time` action near `Reply`. The form captures the work date, minutes, time rate, invoice text, and an optional internal note. The invoice text is the customer-facing billing description that later billing can reuse.

The Time widget in the ticket rightbar includes a local per-ticket stopwatch. Technicians can start the timer, pause or resume it with the same button, and stop it when the work is ready to register. Stopping the timer opens the Add time modal with elapsed minutes prefilled. The timer is local browser draft state and does not create a database record until the modal is saved.

Saved time entries appear as rows in the ticket Activity timeline together with replies and internal notes. The rightbar Time widget is focused on the active stopwatch instead of repeating the time log.

The ticket index reads the local stopwatch state and lightly highlights rows that have an active or paused timer in the current browser. The list also shows the assigned technician for each ticket, or `Unassigned` when no owner is set.

Available rates come from two sources:

- Accepted client contracts, using the contract item time rates inherited from the contract services.
- Active global Commercial time rates that are allowed without a contract.

When a time entry is saved, Nexum snapshots the selected rate name, code, type, unit, amount, currency, and any contract references onto `ticket_time_entries`. This protects old ticket work from future price edits.

Saved entries start with `billing_status = pending` and `timebank_status = pending`. Billing or ticket resolution workflows can later decide whether the minutes should be consumed from a contract timebank or invoiced at the stored rate. The initial registration step does not deduct minutes.

The AI draft button assists with invoice text. It uses selected time rate, the technician's existing draft text, previous time entries, ticket replies, internal notes, and ticket context to propose a concise billing description without changing the saved time automatically. Driving or travel rates should produce driving/travel descriptions, not copied technical repair text.
