Availability: implemented and verified on Dev; source merged to Main in PR #379 on 2026-10-28. Production deployment and exact BookStack article/access read-back are not verified for this delivery. HR-2026-10-20-TICKET-TASK-API remains Pending for operational human checks.
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

## Creator and last editor (Production, 12 November 2026)

Availability: merged to Main in PR #403 and installed and verified in production
on 12 November 2026. Ticket and Task pages and API read-back passed authorized
server checks. Svein Tore approved HR-2026-11-12-TICKET-TASK-ATTRIBUTION and the
production rollout. Retrieval through an employee assistant is a separate check.

You need ordinary permission to view the Ticket or Task. This adds no new access
to records or another user's private Tasks.

1. Open **Tickets** or **Tasks** and open the record.
2. Read the small **Created by** and **Updated by** line below the subject/title.
3. **Created by** identifies the original creator. Assignment changes do not
   replace the creator.
4. **Updated by** identifies the verified actor for the last recorded domain
   change, such as an edit, status/assignment change, tag/custom-field update,
   or a change to the Task's own checklist. The displayed time uses your Nexum
   date/time preferences.
5. Reading, marking a Ticket read, unchanged submissions and technical timestamp
   touches do not replace the last editor. Comments and time entries retain
   their own authors; a parent workflow/field change is attributed separately.

**Unknown** means attribution is absent or the user reference is unavailable.
Existing history is not guessed from the assignee or email sender. An older
record may have a known name but no verified edit time. Automation with a
verified managed actor shows that actor's name; unattributed background changes
do not impersonate the previous employee.

### API read-back

Authorized list, detail and record mutation responses include:

- `created_by` and `updated_by`: nullable user IDs, set by the server.
- `creator` and `updater`: nullable objects containing only `id` and `name`.
- `created_at`: the original creation time.
- `actor_updated_at`: the last tracked domain change time; null for older
  records without that evidence.
- `updated_at`: the existing general timestamp, which may also change for
  technical touches. Use `actor_updated_at` with `updater` for last-editor display.

Do not send actor IDs or actor timestamps as write inputs. The authenticated
operation supplies the actor, and unsupported attribution inputs are rejected.
A null actor reference is an unknown/unavailable identity, not an authorization
to assume a particular employee. Existing token scopes, record permissions and
private Task restrictions still apply.

If the line is absent, check which environment/version you are using. If an old
record shows Unknown, that is expected until a real supported change supplies
new evidence. Do not rewrite historical creator identities to make the line
look complete.
