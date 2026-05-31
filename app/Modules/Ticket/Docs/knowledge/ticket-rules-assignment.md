Ticket Rules and Assignment determine how newly created tickets are classified, routed, and owned.

Ticket Rules are field-routing rules. Assignment Rules and the Assignment Engine decide ownership.

## Ticket Rules

Ticket Rules are managed under Ticket Settings.

Current trigger support focuses on ticket creation.

Rules can evaluate conditions and apply actions such as:

- Set ticket type.
- Set queue.
- Set priority.
- Set category.
- Add tags.
- Set SLA.

Rules are useful for deterministic routing based on channel, inbound email context, tags, queue, customer context, or other supported fields.

Ticket Rules run before assignment so the final queue, category, priority, type, tags, and SLA can influence owner selection.

## Rule Ordering

Rules have weight/order and active state.

Use ordering to keep specific rules above broad fallback rules. For example, a customer-specific backup warning rule should run before a generic inbound email rule.

Rules may support stop-processing behavior depending on the configured rule shape.

## Email Tag Inheritance

Inbound Email tags are copied to tickets before Ticket Rules and assignment finish.

This allows Email preprocessing to drive ticket classification. For example, Email Rules may tag messages as backup, monitoring, invoice, no-ticket, or customer-specific tags before Ticket Rules decide queue, category, and owner.

## Assignment Rules

Assignment Rules are explicit owner-routing rules managed under Ticket Settings.

They can assign based on conditions such as:

- Client.
- Contact.
- Queue.
- Category.
- Priority.
- Ticket type.
- Channel.

Use Assignment Rules when ownership should be deterministic.

Example:

- All tickets from a VIP client go to a named technician.
- A security queue routes to a specific owner.
- A certain ticket type starts with the sales technician.

## Assignment Engine

When no explicit Assignment Rule assigns the ticket, the Assignment Engine can score assignable technicians.

Scoring uses technician profile data such as:

- Assignable state.
- Working hours.
- Capacity.
- Category skills.
- Tag skills.

This gives the system a reasonable fallback without hardcoding every customer or queue.

## Technician Profiles

Technician Profiles support assignment scoring.

Technicians can manage their own profile, and admins can manage profiles under Ticket Settings.

Profiles can include:

- Capacity.
- Working hours.
- Category skills.
- Tag skills.
- Assignable state.

Technician profile scoring is intentionally a helper. Explicit Assignment Rules should still be used for hard business requirements.

## Manual Assignment

Ticket show includes assignment context and a manual re-run assignment action.

Manual assignment and re-run assignment should respect action guards and current ticket state.

## Recommended Rule Strategy

Use Email Rules for raw email classification.

Use Ticket Rules for ticket field routing.

Use Assignment Rules for explicit ownership.

Use Assignment Engine scoring as fallback.

Keep broad fallback rules at the bottom and customer-specific or severity-specific rules at the top.

## Implementation References

Important files:

- `app/Modules/Ticket/Services/TicketRuleEngine.php`
- `app/Modules/Ticket/Services/TicketAssignmentEngine.php`
- `app/Modules/Ticket/Models/TicketRule.php`
- `app/Modules/Ticket/Models/TicketAssignmentRule.php`
- `app/Modules/Ticket/Models/TicketTechnicianProfile.php`
- `app/Modules/Ticket/Controllers/Admin/AssignmentRuleAdminController.php`
- `app/Modules/Ticket/Controllers/Admin/TechnicianProfileAdminController.php`
- `app/Modules/Ticket/Controllers/Tech/TechnicianProfileController.php`
