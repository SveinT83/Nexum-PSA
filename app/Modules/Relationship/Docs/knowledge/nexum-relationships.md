Nexum relationships connect this Nexum installation to another independent
Nexum installation without sharing a database.

Use **Admin > Integrations > Nexum relationships** to create a relationship,
link it to a Client or Vendor, set the remote URL and organization identity, and
configure signed tokens.

## Relationship Types

Direction decides which local master data record the relationship belongs to:

- **We are provider for a client** links the relationship to a Client.
- **We use an upstream provider** links the relationship to a Vendor.
- **Collaboration** is reserved and cannot be activated until matching behavior
  exists.

Relationship status controls runtime behavior:

- Draft stores setup safely without sync.
- Active allows enabled capabilities to run.
- Paused keeps configuration but stops normal use.
- Disabled keeps audit history without exposing the relationship as available.

## Credentials

Each relationship uses:

- An outbound token for calls to the remote Nexum.
- An inbound token hash for calls received from the remote Nexum.
- A webhook signing secret for HMAC signatures.

Incoming payloads must include `X-Nexum-Token`, `X-Nexum-Timestamp`, and
`X-Nexum-Signature`. Signatures are checked against the raw JSON body and expire
after a short time window.

Available sync behavior is controlled by relationship capabilities:

- Ticket sync sends escalated tickets and public replies.
- Status sync exchanges mapped ticket status values.
- Attachment sync only sends files allowed by the size and content-type policy.
- Documentation sync accepts only non-internal documentation.
- Knowledge sync accepts only non-internal articles.

Internal notes, assignments, time entries, cost, margin, private workflow state,
credentials, and internal documentation remain local.

If an outbound sync fails, Nexum records the failure on the relationship audit
trail and health fields. Existing ticket email behavior continues to run for
public replies.

## Ticket Sync

Technicians with `relationships.escalate` can escalate an eligible ticket from
the ticket page when an active ticket-capable relationship exists. The remote
ticket identity is stored in the sync link table to prevent duplicates.

Public customer replies are sent to the linked remote ticket. Internal notes,
assignment, local workflow details, time, cost, and margin are not sent.

Status sync uses the relationship status mapping. Remote status payloads do not
execute arbitrary workflow transitions; they are mapped to local Ticket statuses
and then applied through the Ticket domain action.

## Documentation And Knowledge Sync

Documentation sync accepts only non-internal Documentation records. Knowledge
sync accepts only non-internal Knowledge articles. Both sides keep their own
database rows, with sync links storing remote identity and checksums.

When a local copy has changed since the last remote checksum, incoming updates
are marked as conflicts instead of overwriting local edits.

## Audit And Health

Every sync attempt records an event with direction, capability, object
reference, outcome, and error details. Relationship health fields show the most
recent success or failure so admins can diagnose sync problems without checking
queue logs first.
