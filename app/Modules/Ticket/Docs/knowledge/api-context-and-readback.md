Availability: Dev implementation verified on 2026-10-20; scoped Main merge approved by Svein Tore on 2026-10-28. Production deployment and BookStack publication are separate, unverified steps for this API delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks; Main approval does not mark them performed.
HR-2026-10-20-TICKET-TASK-API. This is the read/context portion of the approved full-parity RFC;
related implemented conversation/ledger operations are described in api-messages-and-ledgers.md.
The remaining parent slices and production availability are not claimed.

## Prerequisites

Use an active internal account with the same Ticket permissions required in the browser.
In **Admin > System > Integrations > API Management**, create a token with only needed scopes.
Do not paste the token into chat, documents, URLs or command logs. Existing tokens were not changed.
A token scope does not replace the user's current domain or record access.

## Choose scopes

| Operation | Token ability | User permission |
|---|---|---|
| Form references/context, event metadata, schedule | tickets.read | ticket.view; global form context also allows ticket.create/update |
| Exact time records and eligible rates | tickets.time.read | ticket.view |
| Costs/reservations and planned scope | tickets.costs.read | ticket.view |
| Linked Task summaries | tasks.read | ticket.view AND task.view |

The storage-items reference catalog additionally requires tickets.costs.read and ticket.view.
The capability endpoint lists only actually registered typed-parity operations, with separate
actor/token decisions and an explicit reminder that object/runtime checks still apply. Legacy
API contracts remain in OpenAPI; this is not a promise that future actions are ready.

## Read references and verify an operation

1. Request GET /api/v1/tickets/capabilities to inspect implemented typed read operations.
2. Request GET /api/v1/tickets/context. Follow the returned catalog URL for queues, statuses,
   priorities, types, categories, tags, Clients, Sites, Contacts, Assets, technicians, own calendars,
   active Task Templates, workflows, SLA policies or permitted storage items.
3. Pass q for name search and per_page/page for paging. Sites/Contacts/Assets use a verified
   client_id; optional site_id/contact_id must belong to that Client. An absent Client returns
   internal Assets and no customer Sites/Contacts. Never guess an ID from another Client.
4. For an existing Ticket, request GET /api/v1/tickets/{ticket}/context using its TD-... key.
   The response identifies the record context and existing action-guard decisions.
5. After an authorized mutation, use the corresponding GET below to read the exact saved result.
   A write response alone is not proof of unrelated email delivery, billing or stock completion.

## Read endpoints

- GET /api/v1/tickets/{ticket}/time-rate-options
- GET /api/v1/tickets/{ticket}/time-entries
- GET /api/v1/tickets/{ticket}/time-entries/{timeEntry}
- GET /api/v1/tickets/{ticket}/cost-entries
- GET /api/v1/tickets/{ticket}/cost-entries/{costEntry}
- GET /api/v1/tickets/{ticket}/planned-lines
- GET /api/v1/tickets/{ticket}/tasks
- GET /api/v1/tickets/{ticket}/activities
- GET /api/v1/tickets/{ticket}/schedule

Collections return data, meta and links. Default per_page is 25; allowed range is 1-100.
Read every page until links.next is null; meta.total describes all permitted matching rows.
Time records retain actual billing/timebank state and Task provenance. Task-origin Ticket time
is a billing projection; do not sum it again with Task actual time. A missing schedule is data:null.
Event activity is metadata only: IDs, type, actor and timestamp. Raw event message/metadata and
restricted Custom Field payloads are deliberately excluded. Existing message metadata endpoints
remain unchanged; complete content/files have separate delivery scopes.

## Expected results and troubleshooting

- 401: authenticate with the supported API identity.
- 403: check both the token ability and active user's existing browser domain rights. A wildcard
  token cannot manufacture user rights. Scope changes require deliberate API Management action.
- 404: the record/catalog is unknown, inaccessible, or a nested time/cost ID belongs to another Ticket.
- 422: correct pagination or the mismatched Client/Site/Contact/Work Context before retrying.
- GET context/history never marks a Ticket/message read, registers time or changes business state.
- Private Tasks not visible to this actor are not included in the linked Task summaries.

No migration, frontend build, queue restart or scheduler activation is required for these reads.
Promotion/deployment belongs to Svein. Regenerate OpenAPI during authorized deployment and verify
runtime path availability before configuring a production client.
