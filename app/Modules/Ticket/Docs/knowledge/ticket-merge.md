Ticket merging consolidates duplicate or related tickets into one primary ticket.

Merging is always a technician-approved action. Automatic inbound duplicate handling exists only for exact duplicate emails when the setting is enabled. Similarity suggestions do not merge anything until a technician confirms.

## Manual Bulk Merge

Technicians can merge from the Ticket list.

Workflow:

1. Select two or more tickets.
2. Click `Merge selected`.
3. Choose the primary ticket.
4. Enter an optional reason.
5. Confirm merge.

All selected tickets except the primary ticket are merged into the primary ticket.

## What Gets Moved

`MergeTickets` transfers important related records from the source ticket to the target ticket.

Current merge behavior includes:

- Conversation messages.
- Attachments.
- Time entries.
- Cost entries.
- Linked Email records.
- Tasks.
- Time entry allocations.
- Tags.
- Relevant audit/history records.

The source ticket is soft-deleted and stores merge metadata so old links can redirect to the target ticket.

## Merge Redirects

When a merged source ticket is opened, the show route redirects to the target ticket and shows a warning.

This preserves old references without showing merged tickets in normal ticket lists.

## Exact Duplicate Auto-Merge

Ticket Settings include an exact duplicate inbound email merge setting.

When enabled, inbound email intake can link to an existing open ticket when customer, contact, subject, and body match an existing open ticket.

This is narrow by design. It avoids automatically merging messages that are merely similar.

## Merge Suggestions

Ticket Settings can enable AI-assisted merge suggestions.

The current implementation is local, deterministic, and testable. It does not call an external AI provider yet.

Suggestions use:

- Subject and body similarity.
- Shared reference numbers in subject.
- Asset-like tokens in subject, such as `NAS99D3C6`.
- Reply prefix normalization, such as removing `Re:` or `Fwd:`.
- Internal ticket reference normalization, such as removing `[TD-2026-000009]`.
- Client and contact context.

Suggestions can group multiple related tickets into one primary ticket.

Example:

Multiple notifications mentioning the same unregistered device identifier can become one suggestion:

```text
TD-2026-000018, TD-2026-000019 into TD-2026-000012
```

The suggestion card explains why the merge is suggested.

## Dismiss Suggestions

Technicians can dismiss a merge suggestion.

Dismissal stores all pairs in the suggested group so the same noisy suggestion does not keep returning.

Dismissed suggestions are stored in `ticket_merge_suggestion_dismissals`.

## Safety Rules

Merge suggestions should never silently modify tickets.

Merge actions should always:

- Require technician confirmation.
- Use the same `MergeTickets` action as manual bulk merge.
- Preserve source ticket redirect behavior.
- Preserve audit information.
- Avoid suggesting the same ticket in multiple active suggestions at the same time.

## Implementation References

Important files:

- `app/Modules/Ticket/Actions/MergeTickets.php`
- `app/Modules/Ticket/Services/TicketMergeSuggestionService.php`
- `app/Modules/Ticket/Models/TicketMergeSuggestionDismissal.php`
- `app/Modules/Ticket/Controllers/Tech/TicketController.php`
- `app/Modules/Ticket/Views/Tech/Tickets/index.blade.php`
