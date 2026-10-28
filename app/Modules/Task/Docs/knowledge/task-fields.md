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

Work Context records whether the task belongs to the owning organization or to a
Client. Client and Site remain reporting shortcuts. A task can still be owned by
another record, but these fields make it easier to sort operational work by
customer.

When a new task is saved without a Client, it becomes internal work and `client_id`
stays empty. When a task is created for a Ticket, it inherits the Ticket Work
Context unless the form or API payload explicitly selects a Client.

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

## Markdown In Task Descriptions

Availability: Issue #328 is implemented and tested on Dev; scoped Main verification is in progress.
Production is unchanged. Human-review checklist HR-2026-10-28-TASK-TICKET-MARKDOWN remains Pending.

Prerequisite: ordinary Task create/update/view access. No new permission is granted.

1. Open **Tasks > New Task**, or open an existing Task and select its edit action.
2. In **Description**, write normal text or Markdown: **bold**, *emphasis*, lists, code and safe
   links. One newline stays a visible line break; blank lines separate paragraphs.
3. Select the existing save action. Open the Task detail or quick-view modal to read formatting.
4. Select **Edit Task** again to edit the original Markdown. API description strings also retain
   the original source; display HTML is never written over it.
5. Task template item descriptions and quick-create forms use the same source convention when
   they create Tasks.

Examples:

```text
First line
Second line

**Important**
- Check the service
- Record the result

1. Prepare
2. Verify

`code` and [documentation](https://example.com)
```

Raw HTML is shown as text, unsafe link targets are disabled, and Markdown images show their alt
text without loading remote images. Use existing attachment controls where available. Other
Task fields, system activity, unrelated modules and Mail's rich-HTML editor keep their own rules.
Historical description text is not rewritten. If legacy text happens to contain Markdown syntax,
escape it with a backslash or use a code block when it must stay literal.
