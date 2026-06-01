This article summarizes the technical operating model for the Ticket domain.

It is intended for developers and administrators who need to understand where behavior lives, what should be tested, and which boundaries should not be bypassed.

## Module Ownership

Ticket code belongs in `app/Modules/Ticket`.

Domain routes must stay in:

```text
app/Modules/Ticket/routes.php
```

Do not add Ticket routes to `routes/web.php`.

Controllers should stay under:

```text
app/Modules/Ticket/Controllers
```

Views should stay under:

```text
app/Modules/Ticket/Views
```

## Important Actions

Use action classes instead of duplicating behavior in controllers.

Important actions:

- `StoreTicket`
- `AddTicketMessage`
- `CreateTicketFromInboundEmail`
- `LinkInboundEmailToTicket`
- `ChangeTicketStatus`
- `CloseTicket`
- `MarkTicketRead`
- `MarkTicketMessageSolution`
- `MergeTickets`
- `RegisterTicketTimeEntry`
- `ReserveTicketStorageItem`
- `UpdateTicketStorageReservation`
- `UpdateTicketTimeEntry`
- `ApplyTicketSla`

## Important Services

Important services:

- `TicketRuleEngine`
- `TicketAssignmentEngine`
- `TicketSlaResolver`
- `TicketWorkflowRuntime`
- `TicketActionGuard`
- `TicketMergeSuggestionService`

These services own reusable business rules. Avoid reimplementing those rules in views or controllers.

## Jobs And Queues

Customer replies are sent through queued jobs.

Important jobs:

- `SendTicketReplyEmail`
- `SendTicketInternalNotificationEmail`

Queue workers must be running in environments where outbound ticket email should actually send.

## Testing

Ticket behavior is covered by:

```text
app/Modules/Ticket/Tests/Feature/TicketModuleTest.php
```

New Ticket behavior should add or update tests.

Feature tests are preferred for:

- Routes.
- Controllers.
- Validation.
- Settings.
- Ticket creation.
- Email behavior.
- Workflow transitions.
- SLA behavior.
- Assignment.
- Merge behavior.
- Time and cost registration.

Unit tests may be added for isolated services where feature tests would be too broad.

## Knowledge Documentation

When the Ticket domain is materially updated, update the Markdown files under:

```text
app/Modules/Ticket/Docs/knowledge
```

Then sync repository documentation into Knowledge:

```bash
php artisan knowledge:sync-docs --module=Ticket
```

The sync marks changed Knowledge pages as `pending_push`. Use the BookStack push action in the Integration settings, or queue it directly:

```bash
php artisan knowledge:sync-docs --module=Ticket --push
```

## Related Future Work

Before changing Ticket behavior, check `docs/TODO.md` for related planned work.

Known future ideas that may affect Ticket include:

- Custom Fields and Metadata.
- Task Templates.
- Service Workshop Foundation.
- Operational Signals.
- Shared Send Email Component.
- SLA Reporting Foundation.
- Ticket Knowledge Follow-Up.

Design new changes so they do not create avoidable rework for these known directions.
