# Sales System - General documentation

**Updated:** 2026-05-20

**Status:** First vertical slice implemented

**Access level:** `sales.view`, `sales.create`, `sales.edit`, `sales.manage_quotes`, `sales.admin`
**Controller path:** `App\\Modules\\Sales\\Controllers\\*`

---

## 0. Current Planning Direction — 2026-05-20

This section records the current agreed direction before implementation. Older sections below are historical planning and must be reconciled with this direction before code is written.

### Core Model

Sales should distinguish between the company/account and the active sales process:

- **Client** is the company/account record.
- **Lead candidate** is a client without an active contract and without an active sales process.
- **Sales opportunity / prospect process** is the active sales work for a client.
- Existing clients with active contracts can still have new opportunities for equipment, additional services, renewals, or project work.

Clients without contracts should appear in a lead overview as possible leads, but they should not all become active sales tickets automatically. A seller starts active lead treatment by opening/creating a sales process for that client.

### Decision: Opportunities Always Belong To A Client

Decision date: 2026-05-20

Every active sales opportunity must have a `client_id`. New leads must first create or match a `Client`, then create the opportunity.

Reasoning:

- Client is the shared account/company source used by Sales, Commercial, Ticket, Economy, Storage, and future modules.
- Reusing Client avoids duplicate company/contact/site data in Sales.
- Existing clients can have new opportunities for upsell, equipment, projects, renewals, or extra services.
- Sales-specific lifecycle data must not be stored directly on the client record.

Ownership:

- `clients` stores account/company identity.
- `sales_opportunities` stores active sales process data.
- Sales estimate fields such as employees, users, devices, pain points, probability, value, and expected close date belong to the opportunity or related sales estimate tables.

### Decision: Clients Can Have Multiple Active Opportunities

Decision date: 2026-05-20

A client may have more than one active sales opportunity at the same time.

Examples:

- service agreement opportunity,
- equipment sale opportunity,
- migration/project opportunity,
- renewal opportunity,
- additional service/upsell opportunity.

Each opportunity has its own owner, status, value, probability, expected close date, quotes, activities, and follow-up workflow.

### Decision: Initial Lead Overview Scope

Decision date: 2026-05-20

The initial lead overview shows clients without an active contract.

Existing clients with active contracts are not automatically shown as lead candidates, even if they may be missing services we sell. Upsell and project sales for existing clients are handled by manually creating a new opportunity for that client.

Future extension:

- A later sales suggestion engine can identify existing clients that may need additional services, renewals, projects, or equipment.

### Decision: Starting A Sales Process Requires A Modal

Decision date: 2026-05-20

Clicking a lead candidate should not silently create an opportunity. The seller must start the process through a modal.

The start modal should capture at minimum:

- client,
- opportunity title,
- opportunity type,
- owner,
- primary sales contact,
- first status,
- next follow-up date,
- short need/summary.

Reasoning:

- Avoids empty opportunities.
- Lets the seller pick the right sales type from the start.
- Creates useful workflow state and follow-up data immediately.

### Decision: Default Opportunity Types

Decision date: 2026-05-20

Default opportunity types:

- Service agreement
- Equipment sale
- Project
- Renewal
- Upsell / additional service
- Other

These should be defaults, not permanent hardcoded business limits. They can later become admin-configurable if needed.

### Decision: Sales Workflow Strategy

Decision date: 2026-05-20

Start with one shared default opportunity workflow.

The design must still support ticket-style workflow configuration later, including different workflows per opportunity type.

Initial default workflow:

- New lead
- Contact lead
- Contacted
- Needs discovery
- Quote ready
- Quote sent
- Negotiation
- Won
- Lost
- Not qualified
- No quote allowed
- Follow up later

Future workflow requirements:

- allowed actions per status,
- required fields before status changes,
- disabled/enabled buttons based on workflow rules,
- type-specific workflows such as service agreement, equipment sale, project, renewal, or upsell.

### Decision: Sales Requires Email Threading

Decision date: 2026-05-20

Sales opportunities must support email conversation directly in Sales.

Required communication surfaces:

- customer-visible email replies,
- CC support,
- internal notes,
- sales journal entries,
- quote sent/reminder/accepted/rejected events,
- attachments where relevant.

Sales email should feel similar to ticket conversation, but activity labels and workflow actions must be sales-specific.

Inbound prospect replies and public quote questions should mark the opportunity as unread until a seller marks the activity or whole opportunity as read. The Sales list should prioritize unread opportunities before normal follow-up sorting so active prospect replies are not buried.

### Decision: Sales Email Account Resolution

Decision date: 2026-05-20

Sales should use the same email-account infrastructure as tickets.

Outbound account priority:

1. Active Email account where `defaults_for` includes `sales`.
2. Active global/default outbound Email account.

Incoming mail is routed back to the correct opportunity by outbound message headers or the `SO-YYYY-XXXXXX` opportunity key, similar to ticket email threading.

### Decision: One Current Quote With Version History

Decision date: 2026-05-20

Each opportunity should have one current/active quote at a time.

When a sent quote needs changes, Sales creates a new quote version instead of editing the sent version in place.

Rules:

- Draft quote can be edited.
- Sent quote is locked.
- Changes after send create a new version.
- Accepted/rejected/expired status belongs to the exact quote version.
- The opportunity points to the current quote version.

This avoids parallel quote confusion while preserving full commercial history.

### Decision: Quote Acceptance Marks Opportunity Won

Decision date: 2026-05-20

When a quote version is accepted, the related opportunity should be marked as `Won` automatically.

Follow-up behavior must be configurable in Sales admin settings and overridable by Sales workflow rules:

- automatically create an implementation/onboarding ticket,
- let the seller create the implementation/onboarding ticket manually,
- require seller implementation instructions before ticket creation,
- allow ticket creation without seller instructions,
- choose default ticket queue/type/status/priority for implementation work.

The seller should always be able to add implementation instructions for technicians when a won sale needs delivery work.

Sales settings provide defaults. Workflow decides the actual behavior when the sales type, value, quote contents, or stage requires stricter handling.

### Decision: Contract Creation And Onboarding Ticket After Accepted Quote

Decision date: 2026-05-20

When an accepted quote contains service agreement/subscription content, Sales should create the contract according to Sales settings and workflow rules.

Default behavior:

- create a contract from the accepted quote,
- set contract status to awaiting approval,
- create an onboarding ticket for technicians when delivery/implementation is needed.

Configurable behavior:

- auto-approve the contract when settings and workflow allow it,
- require manual contract approval,
- create onboarding ticket automatically,
- require seller to create onboarding ticket manually,
- require seller implementation instructions before onboarding ticket creation,
- select default onboarding ticket queue/type/status/priority.

Workflow can override global Sales settings when the opportunity type, quote value, services, risk, or delivery scope requires another path.

### Decision: Implementation Work Uses Ticket Type Onboarding

Decision date: 2026-05-20

Implementation work after a won sale should be created in the Ticket system with ticket type `onboarding`.

Reasoning:

- Onboarding is technician delivery work, so it belongs in Ticket.
- A dedicated ticket type lets workflow, reporting, SLA handling, and UI distinguish onboarding from ordinary support.
- Sales remains the commercial source, while Ticket owns delivery execution.

The created onboarding ticket should link back to the opportunity, accepted quote, generated contract, and seller instructions.

### Decision: Accepted Quote Can Split Into Contract, Order, And Onboarding

Decision date: 2026-05-20

A quote may contain both recurring/subscription content and one-time commercial content.

When accepted, the quote can produce multiple downstream records:

- recurring services/subscriptions become a contract,
- one-time lines, equipment, setup fees, and project fees become Economy order lines/orders,
- delivery or implementation work becomes an onboarding ticket.

Quote lines must therefore classify their commercial behavior, for example:

- recurring contract line,
- one-time order line,
- equipment/storage item line,
- setup/implementation line,
- informational/non-billable line.

### Decision: Quote Lines Support Custom Seller Text

Decision date: 2026-05-20

Quote lines can be created from structured catalog sources or as custom lines.

Supported line sources:

- service,
- package,
- time rate,
- storage/catalog item,
- custom line.

Every quote line should have structured calculation fields and a seller-editable explanation field.

The explanation field is the text shown to the customer to explain the line, value, scope, or assumptions. It must not replace structured fields such as quantity, unit price, recurrence, line type, tax, or downstream behavior.

### Decision: Quote-Level Customer Text

Decision date: 2026-05-20

Quotes should support customer-facing text at quote level in addition to per-line explanations.

Quote text fields:

- intro/cover text,
- scope,
- assumptions,
- optional exclusions,
- optional next steps.

Quotes should also support an internal seller note that is not shown to the customer.

### Decision: Customer Quote Portal Actions

Decision date: 2026-05-20

Quotes must have a customer-facing portal/public link similar to contracts.

Required customer actions:

- Accept quote
- Ask question / reply

Accept should store acceptance metadata:

- accepted at,
- accepted by name/contact where available,
- contact/user id where authenticated,
- IP/user agent where available,
- accepted quote version,
- accepted legal/terms/DPA/SLA snapshots.

Ask question/reply should not accept the quote. It should behave like an incoming customer reply on a ticket: the message is recorded on the opportunity email thread/activity timeline and the seller can answer from Sales.

If workflow rules require it, an incoming quote question can move the opportunity back to negotiation or mark it as waiting for seller response.

### Decision: Quote Expiry

Decision date: 2026-05-20

Quotes must have an expiry date and automatic `Expired` handling.

Rules:

- Sales settings define the default expiry period. Initial default: 30 days.
- Seller can override expiry per quote.
- Expired quotes cannot be accepted unless workflow/settings allow reopening or creating a new version.
- Expiry should be visible in the opportunity, quote view, and customer quote portal.

### Decision: Opportunity Forecast Fields

Decision date: 2026-05-20

Sales opportunities must include forecast fields from the start.

Required fields:

- estimated value,
- probability percent,
- weighted value,
- expected close date.

`weighted value = estimated value * probability percent`.

When a current quote exists, the opportunity estimated value should normally be calculated from the quote total, while allowing controlled override if workflow/settings permit it.

### Decision: Probability Defaults And Overrides

Decision date: 2026-05-20

Probability should be set from workflow/status defaults, with seller override.

Initial suggested defaults:

- New lead: 10%
- Contacted: 20%
- Needs discovery: 30%
- Quote sent: 50%
- Negotiation: 70%
- Won: 100%
- Lost: 0%

Future AI support:

- AI may suggest probability based on customer tone, email thread, reply behavior, needs, objections, timeline, and quote activity.
- AI suggestions must be visible as suggestions first, not silently replace seller/workflow values.
- Seller or workflow rules decide whether to apply the AI-suggested probability.

### Decision: Follow-Up And Calendar Integration From Start

Decision date: 2026-05-20

Sales must support follow-up scheduling from the first implementation.

Required opportunity fields:

- next follow-up date/time,
- next follow-up type,
- next follow-up note/instructions.

Required calendar behavior:

- Seller can schedule next call/follow-up from the opportunity view.
- Quote expiry should create or update a seller calendar reminder/event when enabled.
- Follow-up calls/meetings should create calendar events for the seller.
- Calendar events must link back to the sales opportunity and, where relevant, the quote version.
- Sales settings control defaults such as whether quote expiry reminders are created automatically, reminder offsets, default duration, and target calendar.
- Workflow can require a next follow-up before moving to statuses such as Contacted, Quote sent, Negotiation, or Follow up later.

Ownership:

- Sales owns the follow-up intent and sales context.
- Calendar owns calendar event storage, availability, reminders, and later external calendar sync.

Calendar visibility:

- Sales calendar events are visible work events by default.
- They should block the seller's availability.
- Private visibility can be allowed as an exception, but not as the default.

### Decision: Opportunity Contacts And Stakeholders

Decision date: 2026-05-20

Opportunities should use existing client users/contacts instead of duplicating people in Sales.

Required behavior:

- one primary contact for default email and quote sending,
- multiple stakeholders per opportunity,
- stakeholder role per opportunity, such as decision maker, technical contact, finance, influencer, daily manager, IT responsible, or other.

New Opportunity should let the seller select an existing client contact or create a missing contact inline after the client is selected. Opportunity edit should support changing the primary sales contact when discovery reveals a better commercial or technical decision maker.

If Client users already support roles such as daily manager or IT responsible, Sales should reuse them. If not, Client users must be extended with contact/role metadata or Sales must store opportunity-specific stakeholder roles in a pivot table.

Future AI enrichment:

- AI may help sellers by researching public information about the company and relevant contacts.
- Potential enrichment includes interests, public role/company context, company economy/financial signals, risk signals, and suggested sales tone.
- AI enrichment must be source-aware, reviewable, and suitable for privacy/legal requirements before it is used operationally.
- AI enrichment can later influence probability/risk suggestions, but should not silently change seller-owned fields.

### Decision: Quote Pricing, Cost, And Discount Controls

Decision date: 2026-05-20

Quote builder must show sellers the commercial basis for services and items.

Required values:

- purchase/cost price ex VAT where available,
- sales/unit price ex VAT,
- quantity,
- discount percent and/or discount amount,
- line total ex VAT,
- margin amount,
- margin percent.

Discount support:

- line-level discount,
- quote-level discount if needed,
- workflow/settings can require approval when discount is above a threshold or margin is below a configured minimum.

Customer-facing quote output must not expose internal purchase/cost price unless explicitly configured.

VAT display:

- quote lines show prices ex VAT,
- quote totals show subtotal ex VAT,
- VAT amount is shown when VAT applies,
- grand total inc VAT is shown when VAT applies,
- if VAT is not configured/applicable, VAT rows can be hidden.

### Decision: Quote Sections

Decision date: 2026-05-20

Quotes must support sections/groups.

Default sections:

- Monthly services
- One-time costs
- Equipment
- Implementation
- Optional / alternatives

Sections improve customer readability and make accepted quote splitting easier for contract, Economy order, and onboarding ticket creation.

### Decision: Optional Quote Lines

Decision date: 2026-05-20

Quotes should support optional/alternative sections and lines.

Initial behavior:

- Seller can mark lines or sections as optional.
- Seller decides what is included before sending the quote.
- Customer accepts or asks questions about the whole quote version.

Future behavior:

- Customer portal may allow selecting optional alternatives before acceptance.
- If customer-selectable options are added, the accepted quote version must store exactly which options were accepted.

### Decision: Quote Builder Uses Existing Catalog Sources

Decision date: 2026-05-20

Sales should not create a parallel product catalog.

Quote builder line sources:

- Commercial Services
- Commercial Packages
- Time Rates
- Storage items/catalog items
- Custom lines

Sales can snapshot selected source data into the quote version, but source catalog ownership remains in the existing domains.

### Decision: Quote Snapshots Include Legal, Terms, DPA, And SLA

Decision date: 2026-05-20

When services or packages are added to a quote, relevant legal material must follow automatically as snapshots.

Snapshots can include:

- service terms,
- package terms,
- legal terms,
- DPA/data processing agreement,
- SLA,
- price/rate data,
- service quantity assumptions,
- included/excluded scope.

Sent quote versions are locked. If legal/terms/SLA content changes after sending, a new quote version must be created.

### Decision: Sales Permissions

Decision date: 2026-05-20

Sales should have dedicated permissions.

Initial permissions:

- `sales.view`
- `sales.manage`
- `sales.quote.send`
- `sales.quote.approve_discount`
- `sales.settings`
- `sales.admin`

Technicians may need read access to linked sales/onboarding context without being allowed to edit quotes or change commercial terms.

### Decision: Implementation Order

Decision date: 2026-05-20

Approved implementation order:

1. Data model and migrations for opportunities, activities, stakeholders, quotes, quote versions, and quote lines.
2. Lead candidate list showing clients without an active contract.
3. Start opportunity modal.
4. Opportunity show view with journal, follow-up, forecast, stakeholders, and calendar hooks.
5. Simple quote builder.
6. Quote send, public quote portal, ask-question action, and accept action.
7. Contract, Economy order, and onboarding-ticket generation after accepted quote.

### Decision: Quote Templates

Decision date: 2026-05-20

Sales quotes must use the existing template system where possible.

Required template support:

- quote email template through the `sales_quote_send` Email template,
- customer activity email template through the `sales_activity_email` Email template,
- internal note notification template through the `sales_internal_note` Email template,
- quote public/portal view template,
- quote PDF/export template,
- default intro/cover text,
- default scope/assumptions/exclusions/next-steps text.

Sales should not create a separate template engine. It should extend or reuse the existing tdPSA template system with quote-specific template types/placeholders.

### Decision: Quote PDF Is Required From First Version

Decision date: 2026-05-20

Quote PDF/print support is required in the first implementation.

Reasoning:

- Sellers may print offers and review them manually with leads/customers.
- Customers may expect an attached or downloadable formal quote document.
- The PDF must be generated from the accepted/sent quote snapshot, not from live mutable service data.

Required behavior:

- generate PDF from quote template,
- allow seller to preview/print before sending,
- attach or link PDF when quote is sent,
- keep sent quote PDF/version stable for audit/history,
- public quote portal should provide PDF download/print.

### Decision: Sales Owns Its Own Process Model

Decision date: 2026-05-20

Sales opportunities must use their own main model/table, for example `sales_opportunities`. They must not use the `tickets` table as the primary sales process record.

Reasoning:

- Tickets are service delivery and support work.
- Sales has a different lifecycle: discovery, value, probability, quote, negotiation, forecast, won/lost, and follow-up.
- Keeping Sales separate prevents ticket-specific logic from becoming full of sales exceptions.
- Sales can still reuse ticket-like UI and behavior where it makes sense.

Sales may later create tickets when needed, for example onboarding, implementation, delivery, or support work after a sale is won.

### Sales Process As Ticket-Like Work

An active sales process should behave much like a ticket because it needs:

- customer email conversation,
- internal notes,
- sales journal entries from phone calls and meetings,
- activity timeline,
- owner/assignee,
- workflow and statuses,
- reminders and next follow-up date,
- attachments,
- quote/proposal activity,
- products, services, packages, and custom lines.

The agreed direction is to reuse ticket-style communication and activity behavior, but keep sales-specific fields, storage, and workflow in the Sales domain.

### Lead And Opportunity Statuses

The sales workflow must be configurable, but the first useful default statuses are:

- New lead
- Contact lead
- Contacted
- Needs discovery
- Quote allowed / ready for quote
- Quote sent
- Negotiation
- Won
- Lost
- Not qualified
- No quote allowed
- Follow up later

`Follow up later` must support a callback/follow-up date, for example "contact again next year".

### Sales Metadata

A prospect client may not have all users, assets, and sites registered yet. Sales therefore needs editable estimate metadata:

- employee count,
- user count,
- workstation/computer count,
- server count,
- sites/locations,
- current provider/vendor,
- current pain points,
- needs summary,
- budget/decision information,
- probability,
- expected close date.

When real client data exists, calculations should prefer real users/assets/sites. When it does not, quote/service calculations can use sales estimates.

Example: if a service is priced per user, the quote builder should calculate from actual user count when available, otherwise from the sales estimate user count. The seller can override the estimate.

### Journal Versus Notes

Sales needs a **journal** concept in addition to normal internal notes.

- Customer reply: external email/message to the client.
- Internal note: internal-only comment.
- Sales journal: structured sales activity such as phone call, meeting, discovery, decision maker note, objection, or next step.

Journal entries should appear in the activity timeline but be clearly labeled as sales activity.

### Quote And Contract Direction

Quotes and contracts should be separated, but connected.

The quote is the commercial proposal and should support:

- services,
- packages,
- rates,
- storage/catalog items,
- one-off custom lines,
- quantities based on real or estimated metadata,
- terms/legal/DPA/SLA snapshots,
- quote versioning,
- sent/accepted/rejected/expired statuses.

The contract is the durable agreement created from an accepted quote when the quote contains contract-based services.

Recommended rule:

- Quote acceptance creates a contract from a snapshot of the accepted quote.
- If company settings allow it, acceptance can also mark the generated contract as approved automatically.
- If settings require manual review, acceptance creates a contract draft/awaiting approval.
- The accepted quote and generated contract must remain linked.

Reason: the quote is the sales proposal, while the contract is the operational/legal source used later by tickets, billing, SLA, timebank, and services. Keeping them separate avoids turning every quote draft into a contract too early.

### Legal, Terms, DPA, And SLA On Quotes

Quotes for services should include the same legal/terms/DPA/SLA material that would later become part of the contract.

Important rule:

- The quote must store snapshots of legal text, terms, DPA, SLA, prices, quantities, and included services at the time it is sent.
- If terms or services change later, a new quote version should be created rather than silently changing the sent quote.

### Open Implementation Notes

- Implemented first vertical slice:
  - Sales opportunities are stored in `sales_opportunities`.
  - Lead candidates are clients without an active contract and can be converted through a start modal.
  - Lead candidates can be filtered, sorted, and grouped by sales category, tag, and heat. Operational traits such as missing website should be represented with categories or tags instead of hard-coded list filters.
  - Client lead classification uses shared Taxonomy categories and tags so later campaign segmentation can reuse the same data. Tags are edited as chips from a typeahead input; existing tags are suggested while typing, and new tag names are created on save.
  - New Opportunity can create a missing client inline through a quick modal without redirecting away from the sales form.
  - Inline client creation captures organization number and a Clients-admin-managed client format such as Limited Company (`AS`), Sole Proprietorship (`ENK`), Private Individual (`PRIVATE`), or custom formats like Startup.
  - New Opportunity can select a primary sales contact or create a missing contact inline for the selected client.
  - Opportunities support estimates, probability, owner, primary sales contact, next follow-up date, next action, status, type, activities, and current quote.
  - Follow-up dates can create Calendar events for the opportunity owner.
  - Quotes support version snapshots, custom/service/package/rate/storage lines, discounts, VAT, margin, public portal links, PDF export, accept, and question actions.
  - Opportunity details and quote details are collapsed by default on the opportunity page. Draft quotes are edited through a dedicated modal with searchable catalog source fields, and the `Prepare Quote` action is hidden once a quote exists.
  - Public quote questions are stored as inbound sales activity so they can be handled in the opportunity timeline.
  - Inbound prospect replies and quote questions are unread until marked read from the opportunity activity timeline.
  - Public quote acceptance marks the quote version accepted and moves the opportunity to `won`.
- The first implementation should create a practical vertical slice before advanced workflow editors and AI enrichment.
- Quote acceptance, contract generation, Economy order creation, and onboarding-ticket creation need workflow/settings hooks, even if the first implementation uses conservative defaults.
- Existing historical Sales notes that treated all Sales work as tickets are obsolete. Sales owns opportunities; Ticket owns onboarding/delivery after a won opportunity.
