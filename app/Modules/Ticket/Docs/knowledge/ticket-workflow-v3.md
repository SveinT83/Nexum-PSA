Ticket Workflow is the rulebook for how a Ticket may move between working steps. Think of each workflow state as a room. The workflow decides which doors are visible, what must be ready before a door opens, and who may carry the Ticket into another room.

## The Three Building Blocks

A workflow consists of:

- States: operational steps such as Intake, Waiting for approval, Implementation, Lost, and Complete.
- Requirements: facts that must be true before an action, transition, or escalation is allowed.
- Available actions: the buttons and operations technicians may use in each state.

Several operational states can use the same reporting status. This lets an administrator create detailed working steps without making the standard Ticket reports unnecessarily complicated.

## Building Steps And Return Paths

The first workflow step is created for you. Use **+ Add next step** directly beneath a step to insert the room that follows it. The new room opens for editing, and its **Reporting status** is selected inside the room together with its name, role, requirements, actions, assignment, and commercial policy. Nexum also creates the normal next-step button from the source room to the new room.

Every room contains its own **Next-step buttons** section. The source is therefore always clear: these are the buttons a technician can use while the Ticket is in this room. Each button has a label, target room, manual setting, and optional requirement groups.

The **Manual** switch controls whether the next-step button is available to a technician. A
transition can also have one or more **Automatic after action** triggers. Choose **Any technician
activity** when the first meaningful technician action should try the transition, or choose one or
more specific actions when only particular work should count.

Technician activity includes field and assignment changes, internal notes and customer messages,
marking a reply read, applying SLA, requesting a Knowledge update, starting the Ticket timer,
registering time, adding planned or actual cost, quote work, Storage reservation and picking,
purchase needs, and review/evidence work. A received customer reply is a separate trigger because
it was performed by the customer rather than a technician. Merely opening or viewing a Ticket is
not activity and never changes its workflow step.

The business action is saved first. Nexum then evaluates the matching transition and target-step
requirement groups against the updated Ticket. If the requirements pass, the Ticket moves exactly
one step and the reporting status, workflow state, history, and audit event change together. If the
requirements do not pass, the action remains saved but the Ticket stays in its current step until a
later matching action makes another evaluation. For example, an internal note can remain in `New`
while an Asset requirement is missing; linking the Asset can then move the Ticket to `In Progress`.

An automatic action never moves through several steps at once and never closes a Ticket
automatically. A finishing step remains a deliberate close action. When several outgoing paths
match the same activity, a specific-action path is considered before a generic **Any technician
activity** path, and only the first allowed non-finishing path is used.

Automatic triggers are part of the workflow definition. Saving the editor preserves them, and a
Ticket already pinned to a published version continues to use that version even when a newer draft
changes or removes the trigger.

Workflow definitions published before explicit action-trigger semantics keep their historical
solution-requirement behavior so an upgrade does not silently change an active pinned Ticket. Every
new or republished definition uses the current schema and advances only from its configured
specific or **Any technician activity** triggers.

A target may be later or earlier in the workflow. For example, `Quote review` can offer `Revise declined quote` back to `Sales intake`, where scope and price can be changed before a new quote is sent. A button cannot target the same room it starts from.

The Workflow definition API uses the same `from_state_key` and `to_state_key` transition model. Forward and return paths created through the API are validated and stored exactly like paths created in the editor.

Ordinary editing stays in the browser until an explicit action or save. Typing names, selecting
statuses, changing roles, choosing requirements/operators, and editing policies do not redraw the
Livewire editor. Buttons that add or remove steps, groups, requirements, actions, or paths perform
the required server update and keep the affected step open.

## Requirement Groups

Requirements are built in groups. A group can require:

- All conditions: every condition in the group must be true.
- Any condition: one condition in the group is enough.

The workflow can then require all groups or any group. For example:

- Group 1, Any: customer replied, customer uploaded a signature, or the Client has a valid accepted contract.
- Group 2, All: an Asset is linked to the Ticket.

When the workflow requires all groups, the technician may continue when one customer-approval option is satisfied and the Asset is linked.

Available facts include Ticket fields and activity, customer response/signature evidence, Client contracts, Assets, senior review, Sales quote state and accepted value, Storage reservation/picking/purchase needs, assignment state, and Economy readiness.

The operator is part of every requirement. **Must be true** requires the selected fact to exist or
be satisfied. **Must be false** requires the opposite; the Ticket header prefixes such a requirement
with **Must not** so a negative rule cannot look like a missing positive rule. The assigned
technician is the Ticket owner, so **Ticket has an owner / must be true** passes whenever the Ticket
is assigned.

The description entered when a Ticket is created is initial context, not a later technician reply
or internal-note action, and does not satisfy those workflow activity facts. A requirement placed on
a step is an entry gate for that step. A requirement on a next-step button is an additional gate for
that path. If the same condition is configured in both places, it is enforced in both places but
shown only once in the Ticket header.

**Technician reply exists** and **Internal note exists** are separate activity requirements. Put
both conditions in one **Require at least one** group when either a public technician reply or a
later internal note should be enough. Put them in a **Require all** group, or in separate required
groups, when both must exist. Neither condition requires the message to be a solution, and the
initial Ticket Body satisfies neither condition.
In the Ticket header, an OR group is shown as one combined gate such as **At least one: Internal
note exists OR Technician reply exists**, so a false optional alternative is not presented as a separate mandatory failure.

**Solution is marked** is a separate solution requirement. It passes when a public technician reply
is marked as the solution, or when an internal note is marked as the solution and Ticket Settings
allows internal solution notes. A manual transition becomes clickable
when this requirement passes; it moves automatically only when the transition also has a matching
**Automatic after action** trigger.

## Available Actions

Use **+ Add action** in a workflow step to configure only the actions that should differ from normal Ticket behavior. Only added actions appear in the step. Removing an action returns it to inherited, permission-aware behavior.

Each state can control every important Ticket action. An action can be:

- Inherited: use the normal Ticket behavior.
- Available: show and allow it.
- Hidden: do not show the button.
- Blocked: show the action but explain why it cannot be used.
- Conditional: allow it only when its own requirement groups pass.

Controlled actions include changing state, editing fields, assigning technicians, adding notes or customer replies, registering time, planning or adding costs, creating and sending quotes, recording acceptance, reserving or picking equipment, creating purchase needs, requesting senior review, classifying evidence, escalating, and closing.

The server checks the same policy for browser and API requests. Hiding a button is therefore not the security control; the shared workflow action guard is.

## Ticket Header Progress

The Ticket header shows the workflow as a compact left-to-right sequence. The current step is highlighted, visited steps are marked, and chevron arrows separate the steps. On smaller screens, the sequence scrolls horizontally instead of becoming a large card.

Hover over a step, or focus it with the keyboard, to see every evaluated requirement for that step. Each requirement is marked as satisfied or missing. The workflow-decisions API returns the same ordered steps and requirement results.

Changing step remains a deliberate action in the compact **Next step** section in the Ticket right panel. Planned costs, quote approval, senior review, and customer evidence remain separate task-focused tools in the Ticket body rather than being presented as the workflow itself.

## Escalation And Workflow Changes

An escalation path is a deliberate move from one workflow to another. It can be optional or required.

Example: a normal support Ticket gets planned equipment. The default workflow can offer `Escalate` to a Sales workflow. If escalation is optional, the technician may choose it. If it is required, protected actions such as adding actual cost, assigning another technician, or closing remain blocked until the escalation is completed.

The escalation definition chooses the target workflow and target state. It may also restrict the eligible owner. Repeat and loop protection prevents accidental escalation cycles.

## Assignment And Senior Review

Every state can keep the current owner when eligible, clear the owner for manual assignment, or run automatic assignment within an eligible user list and required permissions.

This supports rules such as:

- A trainee cannot own a Sales negotiation state.
- An escalated Sales Ticket must be assigned to an eligible senior or sales technician.
- Assignment to another technician is blocked until an Asset or other required data is present.

A state or action can require a named senior-review gate. The request records a fingerprint of the material Ticket evidence. The reviewer must have senior-review permission and, when separation of duties is enabled, cannot approve their own or currently owned work. Material changes to messages, evidence, planned commercial scope, or state invalidate the approval and require a new review.

## Customer Evidence

Inbound Ticket messages can be classified as customer-response evidence. Uploaded Ticket attachments can be classified as signature evidence. Technician-authored messages cannot be used as customer response, and a message cannot masquerade as an uploaded signature.

Evidence keeps its source, fingerprint, scope, actor, timestamp, and invalidation history. This gives the workflow a traceable fact instead of an unchecked manual box.

## Planned Cost And Ticket Quotes

Planned scope is separate from actual cost. A technician can add equipment, services, time, or custom lines without reserving stock, ordering anything, or billing the Client.

When planned lines exist, `Create Quote` reuses the Sales quote engine and editor. Nexum creates or reuses a Sales Opportunity linked to the Ticket, while the quote remains operable from both Ticket and Sales. A sent quote version becomes immutable; editing after send creates a new draft version.

Sending from the Ticket:

1. Recalculates the quote.
2. Creates an immutable PDF snapshot.
3. Adds a public Ticket reply containing the total and secure acceptance link.
4. Attaches the PDF to the Ticket reply.
5. Sends through the normal Ticket email process.

The Ticket must be published to the Customer Portal before a customer-facing quote reply can be sent.

The customer can accept through the secure link or Customer Portal. If the customer accepts in an email reply, an authorized technician can mark that inbound Ticket message as acceptance evidence. All acceptance methods use the same Sales acceptance action. The quote becomes accepted, the Opportunity becomes won, and only the lines from that accepted immutable quote version become approved Ticket scope.

## From Approval To Storage, Purchase, And Economy

An approved equipment line may be converted to a real Storage reservation and pending Ticket cost. An approved orderable line may instead create a draft purchase need linked to the Ticket and planned line. Creating the need never sends an order to the vendor.

Custom approved lines can be converted to pending actual Ticket costs. These operations are idempotent: repeating the same conversion or purchase request does not create duplicate downstream records.

Completed closure checks that actual time and costs stay within the accepted quote and the configured state tolerance. Declined, cancelled, and no-sale outcomes require a reason and do not generate a billing order. A successful completed Ticket continues through the normal Economy order process.

## Customer Status Updates

Every next-step button has an optional **Customer update** policy. Enable **Notify customer** when a
successful transition should tell the customer that the reporting status changed. Choose Email,
Customer portal, or both; select an active Ticket Email template; and optionally enter a short
customer-facing message.

Customer updates are sent only for Tickets that are **Published** to the customer. An Unpublished
Ticket may still move to its next workflow step, but Nexum records the notification as skipped and
does not create an outbound customer message. Publishing later does not retroactively send an old
status update.

The message uses the previous and current reporting-status names. Internal note text, internal
workflow step names, requirements, cost details, and other technician-only information are never
copied into the update automatically. The default Email template is
`tickets/ticket_status_update`, which can be edited in Email Templates.

Manual next-step buttons, automatic action triggers, and Ticket API transitions all use the same
delivery path. Each successful transition creates at most one public status-update message and
queues delivery after the database transaction commits. Portal delivery creates only the portal
database notification; it does not also invoke the generic portal-notification Email channel when
workflow Email delivery is selected. Delivery failures are logged for retry and investigation but
do not undo the completed workflow transition.

## Publishing And Existing Tickets

Saving the editor creates a draft. Publishing creates an immutable numbered workflow version. Every new Ticket is pinned to the published version it started with, so later editing does not silently change its rules.

The Ticket header, next-step decisions, browser actions, API, message triggers, completed closure,
and inbound relationship status sync all evaluate the same pinned definition. A successful move
updates the reporting status when needed, the workflow state key, workflow history, and Ticket audit
events together. Direct status edits in the Ticket form or API are translated to an outgoing
workflow transition; when several workflow steps use the same reporting status, the caller must use
the specific next-step transition instead of an ambiguous status change.

A completed close must use the configured finishing transition. Customer-declined, cancelled, and
no-sale closure remain explicit exception outcomes with a required reason. Inbound relationship
status updates are rejected and audited as skipped when the pinned workflow does not allow the
mapped transition; they no longer bypass workflow requirements.

Existing active Tickets move to a newer published version only through the migration preview and
explicit selection. The administrator selects the Tickets to migrate; the target step is determined
automatically for each Ticket from the new workflow and the Ticket's current facts.

Nexum first keeps the same stable step when it still exists and its entry requirements pass. If it
does not, Nexum selects the furthest non-finishing step with explicit entry requirements that pass.
When no explicit requirement classifies the Ticket, Nexum preserves one unambiguous matching
reporting status or uses the valid initial step. A Ticket is blocked from migration when no safe
target can be identified. Correct the new workflow requirements and publish another version instead
of manually forcing a target.

The preview explains the proposed step and placement reason. Apply evaluates every selected Ticket
again inside the migration transaction, so a stale preview or a legacy API `state_mapping` value
cannot force a different target. Closed Tickets are not migrated. The migration history records the
chosen strategy and the evaluated target-step requirements.

## API Parity

The version 1 API provides the same workflow operations as the Ticket page:

- Read workflow decisions, visible actions, missing requirements, transitions, and escalations.
- Perform transitions, escalation, close outcomes, planned-line operations, quote creation/send/acceptance, reviews, and evidence classification.
- Start the Ticket timer and register time or actual cost through the same guarded actions that the
  Ticket page uses; successful actions evaluate the same automatic workflow triggers.
- Create, edit, inspect, publish, preview, and migrate workflow definitions.

API tokens need the relevant `tickets.actions`, `tickets.workflow.read`, `tickets.workflow.manage`, or `tickets.workflow.publish` ability. The authenticated user also needs the same domain permission as a user performing the action in the browser.

## Safe Administration

Use stable state, transition, and escalation keys. Test a new draft with representative Tickets, publish it, and use migration preview before moving active work. Do not delete or rename facts in a way that makes a published version unreadable.

Workflow publishing, migration, escalation, approval recording, evidence classification, planned cost, and senior review have separate permissions. Every transition, escalation, review, evidence classification, accepted quote, conversion, purchase need, migration, and close outcome is written to the Ticket audit history.
