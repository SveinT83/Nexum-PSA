# Nexum Relationship Two-Instance Test Plan

Date: 2026-07-02
Owner: Codex / Svein
Scope: Real-world validation of Discussion #150 between two independent Nexum
installations.

## Goal

Prove that two separate Nexum installations can exchange explicitly shared work
without sharing a database, SSH access, private workflow state, time, cost,
margin, credentials, or internal documentation.

## Environments

Use two independent Nexum installations:

- Provider Nexum: the installation acting as the service provider.
- Customer Nexum: the installation escalating selected work to the provider.

Each installation needs:

- Its own database.
- Its own `APP_KEY`.
- Its own HTTPS URL reachable by the other installation.
- Queue workers running.
- Email configured for normal ticket reply fallback.
- BookStack configured if documentation push verification includes BookStack.

## Setup Checklist

1. Deploy the same code revision to both installations.
2. Run migrations and seed permissions on both installations.
3. Build frontend assets on both installations.
4. Confirm `php artisan test app/Modules/Relationship/Tests/Feature/RelationshipModuleTest.php`
   passes in both environments.
5. Create a Client on the provider Nexum representing the customer.
6. Create a Vendor on the customer Nexum representing the provider.
7. Create active Nexum relationships on both sides:
   - Provider: `we_are_provider`, linked to the Client.
   - Customer: `we_use_provider`, linked to the Vendor.
8. Exchange outbound token, inbound token, webhook secret, remote base URL, and
   remote organization identity manually.
9. Enable only the capabilities being tested first.
10. Configure status mapping on both sides.
11. Configure attachment policy on both sides.
12. Confirm both relationship admin pages show the expected status and no
    failure summary.

## Test Matrix

### 1. Authentication

Expected: unsigned, expired, wrong-token, and wrong-signature payloads are
rejected and produce failed authentication audit events without creating domain
records.

Steps:

1. Send a valid signed ticket payload from Customer to Provider.
2. Repeat with an invalid signature.
3. Repeat with an old timestamp.
4. Repeat with an invalid token.

Pass criteria:

- Valid payload succeeds.
- Invalid payloads return 401.
- No ticket/documentation/article is created from invalid payloads.
- Relationship audit contains failed authentication events.

### 2. Manual Ticket Escalation

Expected: a customer-side ticket can be escalated to the provider, and duplicate
escalation does not create duplicate provider tickets.

Steps:

1. Create a customer ticket with public description.
2. Escalate it to the provider relationship.
3. Verify the provider ticket is created under the linked Client and expected
   queue.
4. Repeat the same escalation.

Pass criteria:

- One provider ticket exists.
- Both installations have sync links with the correct remote identity.
- Both installations have sync audit events.
- Internal notes and local workflow details are not present remotely.

### 3. Public Reply Sync

Expected: public replies sync both ways while normal email fallback remains
active.

Steps:

1. Add a public reply on the provider ticket.
2. Verify the reply appears on the customer ticket as an external public reply.
3. Add a public reply on the customer ticket.
4. Verify the reply appears on the provider ticket.
5. Disable remote connectivity temporarily and add another provider public
   reply.

Pass criteria:

- Synced replies are idempotent.
- Replies do not loop back as new messages.
- Internal notes are not synced.
- Failure is recorded on the relationship.
- The normal ticket email path still queues/sends the customer reply.

### 4. Status Mapping

Expected: mapped statuses sync without exposing arbitrary local workflow
transitions.

Steps:

1. Map customer `waiting-provider` to provider `open`.
2. Map provider `resolved` to customer `provider-resolved`.
3. Change status on provider.
4. Change status on customer.
5. Send an unmapped remote status.

Pass criteria:

- Mapped statuses apply locally.
- Unmapped status is skipped and audited.
- Local workflow internals are not copied.

### 5. Attachment Policy

Expected: selected public reply attachments sync only when policy allows them.

Steps:

1. Send a small allowed attachment.
2. Send a blocked content type.
3. Send a file over the configured max size.

Pass criteria:

- Allowed file arrives with checksum and file record.
- Blocked files do not arrive.
- Ticket reply still sends through normal email behavior.

### 6. Documentation Sync

Expected: non-internal Documentation syncs; internal Documentation is rejected;
conflicts are detected.

Steps:

1. Create client-scoped Documentation and push it.
2. Create internal Documentation and attempt to push it.
3. Edit the receiving local copy.
4. Send an updated remote copy.

Pass criteria:

- Client-scoped Documentation syncs.
- Internal Documentation is rejected.
- Diverged local copy is marked conflict and not overwritten.

### 7. Knowledge Sync

Expected: non-internal Knowledge articles sync; internal articles are rejected;
conflicts are detected.

Steps:

1. Push a public Knowledge article.
2. Push a client-wide Knowledge article.
3. Attempt to push an internal Knowledge article.
4. Edit receiving local copy and send remote update.

Pass criteria:

- Public/client-wide articles sync.
- Internal article is rejected.
- Diverged local copy is marked conflict.

### 8. BookStack Documentation Publishing

Expected: repository-owned Relationship documentation is present in Knowledge
and, when BookStack two-way sync is enabled, pushed through the normal BookStack
worker.

Steps:

1. Run `HOME=/tmp php artisan knowledge:sync-docs --module=Relationship --push`.
2. Run the queue worker or dispatch the BookStack push job.
3. Check BookStack for the Nexum Relationships page under the Nexum PSA book.

Pass criteria:

- Knowledge contains the Relationship chapter/article.
- BookStack push summary has no failed or skipped records.
- BookStack page content matches the repository article.

## Final Acceptance

Accept the feature only when:

- Authentication failure tests pass.
- Manual ticket escalation works in both directions required by the pilot.
- Public replies and mapped statuses sync without loops.
- Attachment policy is enforced.
- Internal notes, time, cost, margin, assignments, credentials, and internal
  docs do not cross the relationship boundary.
- Documentation and Knowledge conflict handling prevents blind overwrites.
- Admin health/audit surfaces identify successes and failures.
- BookStack documentation has been published from repository Knowledge docs.
