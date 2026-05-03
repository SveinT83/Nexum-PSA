# EmailController — Tech Inbox

Controller file: `app/Http/Controllers/Tech/Inbox/EmailController.php`

## Purpose

`EmailController` currently handles the technician-facing Inbox for inbound email messages that have not been routed to a ticket yet.

The Inbox is a triage area only. Users must not reply, reply-all, or forward from Inbox. A message should either become a ticket/lead, be linked to an existing ticket, or be classified as noise/spam through a rule or archive/delete action.

---

## Current implementation state

Implemented today:

- List unrouted email messages where `ticket_id` is `null`.
- Search by subject, sender email, and body text.
- Paginate the list.
- Manually trigger polling for active email accounts.
- Show a full email message.
- Show attachments for an unrouted message.
- Download attachments.
- Soft delete / hide an unrouted message locally.
- Optionally delete from IMAP server when the account policy is `sync_delete`.

Current views:

- `resources/views/tech/inbox/index.blade.php`
- `resources/views/tech/inbox/view.blade.php`

Current routes are defined in `routes/tech.php` under the authenticated tech route group.

---

## Current routes

Expected route names:

- `tech.inbox.index` — list unrouted messages.
- `tech.inbox.poll` — manually fetch mail from active accounts.
- `tech.inbox.show` — read one unrouted message.
- `tech.inbox.delete` — delete/hide an unrouted message.
- `tech.inbox.download` — download attachment belonging to an unrouted message.

---

## Target route/controller structure

The current single controller is acceptable for MVP, but the intended structure is:

- `App\Http\Controllers\Tech\Inbox\IndexController@index`
- `App\Http\Controllers\Tech\Inbox\ShowController@show`
- `App\Http\Controllers\Tech\Inbox\TriageController` for actions.
- `App\Http\Controllers\Tech\Inbox\AttachmentController@download`

Do not split the controller just for cleanup. Split only when adding real triage actions or when the current controller becomes harder to maintain.

---

## Access and permission requirements

Current implementation is only protected by the broader `auth` + `tech` route middleware.

Target permissions:

- View Inbox: `inbox.view`
- Create ticket from email: `ticket.create`
- Link email to existing ticket: `ticket.edit`
- Create global email rule from message: `email.rules.manage`
- Label/archive message: `inbox.manage`
- Hard delete message: `email.admin`
- Download attachment: `inbox.view` and access to the parent message

Action buttons must be hidden when the user does not have the required permission. Backend checks are still mandatory.

---

## Required missing functionality

The current Inbox is not complete according to the desired tdPSA plan. These items are missing and should be implemented before treating Inbox as done.

### 1. Create Ticket from Inbox message

Add an action that promotes the email into a ticket.

Expected behavior:

- Prefill title from email subject.
- Prefill description from sanitized email body or text body.
- Preserve original email metadata and attachments.
- Allow technician to select queue, category, priority, client, site, contact, and asset where known.
- Use parser hints when available.
- Set `ticket_id` on the email message after successful ticket creation.
- Write audit log entry.
- Redirect to the created ticket.

Permission:

- `ticket.create`

### 2. Link to existing Ticket

Add an action for connecting an Inbox message to an existing ticket.

Expected behavior:

- Search/select ticket by ticket ID, title, customer, or recent activity.
- Attach message body and attachments to the selected ticket conversation/history.
- Set `ticket_id` on the email message.
- Preserve headers for future threading.
- Write audit log entry.
- Redirect to the linked ticket or back to Inbox with success message.

Permission:

- `ticket.edit`

### 3. Create Rule from message

Add a button for creating a global email rule from the current message.

Purpose:

- If the message is spam/noise, the technician should be able to create a rule instead of manually deleting similar emails forever.

Expected behavior:

- Open or redirect to the email rule editor.
- Prefill rule conditions from the message:
  - From email
  - From domain
  - Subject contains
  - Receiving account
- Suggested default action should be safe, not destructive by default.
- Destructive delete rules must require explicit confirmation.
- Support Continue/Stop flag.
- Write audit log when the rule is created or published.

Permission:

- `email.rules.manage`

### 4. Archive vs hard delete

Current `destroy()` hides/deletes the message and may also delete from IMAP depending on account policy. This should be clarified.

Target behavior:

- Archive/Hide should be the normal technician action.
- Hard delete should be admin-only.
- Archive should keep enough metadata for audit and duplicate prevention.
- Hard delete must require confirmation and audit.

Permissions:

- Archive: `inbox.manage`
- Hard delete: `email.admin`

### 5. Labels / tags

Add lightweight labels for Inbox triage.

Examples:

- spam
- noise
- vendor
- billing
- sales
- needs-review

Permission:

- `inbox.manage`

### 6. Real-time alerts / background agent

The current implementation has manual polling through `tech.inbox.poll`.

Target behavior:

- A background job/agent fetches and processes incoming email.
- New Inbox items raise an internal event such as `inbox.received`.
- Updates raise `inbox.updated`.
- UI can later show badge/toast through Livewire or Echo.
- Fallback polling is acceptable until real-time is implemented.

---

## Explicit non-goals

Do not add these to Inbox:

- Reply
- Reply-all
- Forward
- Full mailbox client behavior

Reason:

A message is either a ticket/lead candidate or noise/spam. Communication with customers must happen from the Ticket/Lead module after classification.

---

## Data rules

Inbox should only show messages that are not already routed.

Current rule:

- `ticket_id IS NULL`

Future rule may also consider:

- `lead_id IS NULL`
- `archived_at IS NULL`
- `deleted_at IS NULL`
- `state IN (new, untriaged, awaiting-link)`

Threading metadata must be preserved:

- Message-ID
- In-Reply-To
- References
- Subject token if present
- Account ID
- IMAP UID where available

---

## Error handling expectations

- 403: User lacks required permission.
- 404: Message is already routed or does not exist.
- 422: Invalid ticket/rule/action input.
- 500: Unexpected mail/storage failure; log and show safe user-facing message.

Attachment downloads must verify that the attachment belongs to an accessible unrouted message.

---

## Audit requirements

Audit these events:

- Message viewed, if audit policy requires read tracking.
- Message archived.
- Message hard deleted.
- Ticket created from message.
- Message linked to ticket.
- Rule created from message.
- Attachment downloaded, if audit policy requires file access tracking.
- Manual poll triggered.

Audit record should include:

- Actor user ID
- Message ID
- Account ID
- Action type
- Related ticket/rule ID when relevant
- Timestamp
- Origin: UI / job / API

---

## UI expectations connected to this controller

Index view should contain:

- Search
- Sort/filter later
- Message list
- Open action
- Create Ticket action where possible
- Create Rule action where possible
- Archive action where possible

Show view should contain:

- Full message header
- Sanitized body
- Attachments
- Triage action panel
- Create Ticket
- Link to existing Ticket
- Create Rule
- Archive
- Hard Delete for admin only

No reply/forward buttons should be visible.

---

## Acceptance criteria before Inbox is considered complete

- User with `inbox.view` can list and read unrouted email.
- User with `ticket.create` can create a ticket from an Inbox email.
- User with `ticket.edit` can link an Inbox email to an existing ticket.
- User with `email.rules.manage` can start a rule from an Inbox email with prefilled sender/domain/subject/account.
- User without permission cannot see or execute restricted actions.
- Archive and hard delete are separate behaviors.
- All important actions are audited.
- Inbox does not expose reply, reply-all, or forward.

---

## Maintenance rule

Keep this file updated whenever methods, validation rules, side effects, route bindings, permissions, or triage actions are changed.

File naming follows controller naming: `EmailController.md`.
