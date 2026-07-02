This plan verifies Nexum-to-Nexum relationships between two independent Nexum
PSA installations after the Relationship module is deployed.

## Test Environments

Use two separate Nexum PSA installations with different application URLs,
databases, storage paths, queues, and schedulers.

- Provider installation: represents the upstream service provider.
- Customer installation: represents the downstream client or partner.
- Both installations must run the same branch or release candidate.
- Both installations must have HTTPS enabled and outbound access to the other
  instance.
- Both installations must have BookStack configured when documentation sync is
  included in the acceptance test.

## Setup Checklist

On both installations:

- Run all migrations.
- Seed permissions and roles.
- Confirm the Relationship admin menu is visible only to users with the
  relationship permissions.
- Confirm `relationships.read` and `relationships.sync` are available in the
  API ability catalog.
- Create one active Relationship record pointing at the other installation.
- Generate and exchange the Relationship key pair and shared secret.
- Enable only the sync directions that will be tested.

## Connectivity And Authentication

1. Open the Relationship admin page on both installations.
2. Confirm each Relationship shows a configured endpoint URL, local key, remote
   key fingerprint, enabled directions, and active status.
3. Run a health check from provider to customer.
4. Run a health check from customer to provider.
5. Confirm both checks create successful sync event audit rows.
6. Rotate the shared secret on one side only and verify the opposite side rejects
   signed requests.
7. Restore the matching secret and verify health checks succeed again.

Acceptance:

- Invalid signatures are rejected.
- Valid signatures are accepted.
- Audit events show direction, endpoint, status, and error details without
  exposing secrets.

## Ticket Escalation

1. Create a customer ticket with title, description, priority, status, contact,
   and public reply enabled.
2. Escalate the ticket to the provider Relationship.
3. Confirm the provider receives a linked ticket with customer context and a
   sync link.
4. Add a provider internal note and confirm it does not sync back.
5. Add a provider public reply and confirm it syncs back to the customer ticket.
6. Add a customer public reply and confirm it syncs to the provider ticket.
7. Change provider status to a mapped customer status and confirm the customer
   ticket updates through `ChangeTicketStatus`.
8. Change customer status to a mapped provider status and confirm the provider
   ticket updates.
9. Confirm unmapped statuses do not overwrite the remote ticket.

Acceptance:

- One local ticket maps to one remote ticket per Relationship.
- Public replies sync both ways when enabled.
- Internal notes never sync.
- Status mapping is explicit and does not guess.
- Ticket history shows honest Relationship context.

## Attachments

1. Attach a small allowed file type to a public ticket reply.
2. Confirm the attachment syncs when attachment sync is enabled.
3. Attach a blocked or oversized file.
4. Confirm the remote side records a skipped event and does not store the file.
5. Disable attachment sync and verify no further files sync.

Acceptance:

- Only selected, allowed attachments sync.
- Blocked files create visible audit information.
- Attachment settings can be changed without breaking ticket message sync.

## Documentation Sync

1. Create or update a Documentation record on the provider side.
2. Sync provider to customer.
3. Confirm the customer receives the document with source metadata.
4. Edit the same document locally on the customer side.
5. Sync provider to customer again.
6. Confirm the local customer change is preserved and the incoming change is
   marked as a conflict or skipped according to the Relationship settings.
7. Resolve the conflict manually and sync again.

Acceptance:

- Remote documentation does not silently overwrite local edits.
- Conflicts are visible to technicians.
- Source metadata identifies the originating Relationship.

## Knowledge And BookStack

1. Run repository Knowledge sync for the Relationship, Ticket, Integration,
   Documentation, and Knowledge modules.
2. Trigger BookStack push through the Nexum API endpoint.
3. Confirm BookStack contains the Relationship overview and this test plan.
4. Confirm updated Ticket, Integration, Documentation, and Knowledge articles
   describe Relationship behavior.
5. Confirm article titles are not duplicated as the first Markdown heading.

Acceptance:

- Knowledge records are updated in Nexum.
- BookStack receives the same documentation.
- Failed pushes are visible in the BookStack integration status.

## Final Acceptance

The feature is ready for real use when:

- Two installations can authenticate each other.
- Ticket escalation works both directions.
- Public replies and mapped statuses sync correctly.
- Internal notes and unmapped values do not leak or overwrite remote data.
- Attachment policy is enforced.
- Documentation and Knowledge sync preserve local ownership and expose
  conflicts.
- BookStack contains the final operator documentation.
- Relationship audit events explain successful and failed operations clearly.
- The relevant automated tests pass on the dev server.
