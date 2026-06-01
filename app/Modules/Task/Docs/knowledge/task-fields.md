The task form is designed for fast internal work assignment.

Title is the short work instruction. Keep it concrete enough that the assignee
can understand the expected outcome from the list view.

Description carries the longer context, links, or acceptance notes.

Assignee is the person who should do the work. A task should normally have one
assignee. If multiple people need separate responsibilities, create child tasks.

Queue groups work by operational area. Tasks currently reuse the existing queue
records used by Tickets, but the Task UI treats them as general queues.

Priority controls urgency. Tasks currently reuse the existing priority records
used by Tickets, but the Task UI treats them as general priorities.

Category and tags use the global taxonomy system. Use them for sorting,
filtering, Knowledge suggestions, and future AI context. Categories should be
used for stable groupings; tags can be used for more flexible labels.

Client and Site are reporting shortcuts. A task can still be owned by another
record, but these fields make it easier to sort operational work by customer.

Due is the expected completion time. Scheduled start and scheduled end are for
calendar-style planning.

Estimated minutes is used when completing a task quickly. If no actual time has
been registered, completing the task creates a time entry from the estimate.
Admin Task Settings may provide a default estimate for manually created tasks.

Block owner completion means the owning record should not be treated as complete
while the task is still open.

AI Assist can fill editable task form fields when an active AI agent is
configured. The button requires title, description, and at least one context
record such as client, site, ticket, or parent task.

AI may suggest a clearer title and description, ticket link, estimate, ticket
rate, queue, priority, category, tags, assignee, and checklist items. These are
only form values in the browser. The task is not created, completed, timed, or
otherwise changed until the technician reviews the result and saves the form.
