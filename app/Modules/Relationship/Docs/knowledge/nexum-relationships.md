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

A ticket must be Published before it can be escalated to a Nexum relationship.
Unpublished tickets are externally silent and are not pushed to remote Nexum
portals. Outbound public reply sync and status sync also check the Published
state before sending updates.

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

## Official Relationship API

Availability: implemented on Dev; Main promotion is authorized and verification is in
progress. Production deployment and outbound activation have not been performed.
A Main merge does not make these operations available in production.

Prerequisites:
1. Use an active internal account with the native right for the operation.
   Admin settings access is permission-based; a fixed Admin role is not required.
2. Create a scoped API token through the existing API management surface.
3. Read /api/v1/relationships/context and bounded /lookups?type=clients, vendors or
   queues with relationships.manage. Narrow operators do not need broad view rights.
4. For export, retain relationships.sync and documentation.view or knowledge.view.
   Knowledge must be published. Internal and other-client records are excluded.
5. Every new write needs an Idempotency-Key header (8-200 characters).
   Update, rotation and push additionally need the exact quoted version in If-Match.
   Push needs the preview source.version as source_version. Refresh stale previews.

| Method | Path | Token scope |
| --- | --- | --- |
| GET | /api/v1/relationships | relationships.read |
| GET | /api/v1/relationships/context | relationships.manage |
| GET | /api/v1/relationships/lookups | relationships.manage |
| POST | /api/v1/relationships | relationships.manage |
| GET | /api/v1/relationships/operations | relationships.operations.read |
| GET | /api/v1/relationships/operations/{operation} | relationships.operations.read |
| GET | /api/v1/relationships/{relationship} | relationships.read |
| GET | /api/v1/relationships/{relationship}/context | relationships.manage |
| PATCH | /api/v1/relationships/{relationship} | relationships.manage |
| POST | /api/v1/relationships/{relationship}/rotate-secrets | relationships.manage |
| GET | /api/v1/relationships/{relationship}/health | relationships.read |
| GET | /api/v1/relationships/{relationship}/sync-links | relationships.read |
| GET | /api/v1/relationships/{relationship}/sync-links/{link} | relationships.read |
| GET | /api/v1/relationships/{relationship}/sync-events | relationships.read |
| GET | /api/v1/relationships/{relationship}/sync-events/{event} | relationships.read |
| GET | /api/v1/relationships/{relationship}/eligible-documentations | relationships.sync |
| GET | /api/v1/relationships/{relationship}/eligible-knowledge | relationships.sync |
| POST | /api/v1/relationships/{relationship}/documentations/{source}/preview | relationships.sync |
| POST | /api/v1/relationships/{relationship}/documentations/{source}/push | relationships.sync |
| POST | /api/v1/relationships/{relationship}/knowledge/{source}/preview | relationships.sync |
| POST | /api/v1/relationships/{relationship}/knowledge/{source}/push | relationships.sync |

Configuration uses relationships.manage. Credential input and rotation additionally
need relationships.credentials.write. Outbound push additionally needs
relationships.providers.execute and the default-off deployment setting
RELATIONSHIP_API_OUTBOUND_EXECUTION_ENABLED. Merging does not activate export.

### Configure or update
1. Read context/lookups. Choose a Client for we_are_provider or Vendor for we_use_provider.
2. POST a draft with name, direction, relationship_type and Client/Vendor ID.
   Supply active-connection prerequisites only when deliberately activating.
3. Read the command and result.relationship. Save its opaque version for updates.
4. PATCH only intended fields. Omitted settings/credentials remain unchanged; blank
   credential inputs neither reveal nor erase existing secrets.
5. Verify the returned settings and command read-back before completing the action.
   Unsupported fields return 422; missing state/key returns 428; stale state returns
   412; changed reuse of a key returns 409.

capabilities, service_areas and attachment_allowed_content_types are string arrays.
status_mapping is an object such as {"open":"new"}. Existing routing/policy fields are
ticket_queue_id, ticket_auto_create_queue, documentation_two_way, attachment_max_mb
and attachment_allowed_content_types. States include draft, active, paused and
disabled. Collaboration remains reserved. Active API configuration requires HTTPS.

### Set or rotate credentials
1. Use manage and credentials.write scopes plus native relationships.manage.
2. Set outbound_token, inbound_token or webhook_secret only through guarded writes.
   Never place real secrets in URLs, notes or documentation.
3. POST rotate_inbound_token and/or rotate_webhook_secret with If-Match and a new
   Idempotency-Key to rotate-secrets.
4. Only a newly generated inbound token appears in the authorized encrypted command
   result. Ordinary reads expose configured/present metadata. Same-key replay returns
   the same authorized result and never rotates again.
5. Refresh context and exchange remote configuration through the approved secret
   procedure. Existing signing/outbound secrets are never readable. Command replies
   are no-store and require the original actor/token and current original rights.

### Preview and push
1. Read eligible-documentations or eligible-knowledge under the exact relationship.
2. POST the selected record preview. Review source, capability, relationship_ready,
   source version and relationship version. Preview has no writes or network effects.
3. Execute the corresponding push only after authorized runtime activation, with
   source_version, If-Match and Idempotency-Key.
4. Read command and sync link. remote_accepted means the receiver returned its object
   identity, not independently verified remote content. remote_readback_verified
   explicitly remains false.
5. conflict requires review; failed is a definite failure; unresolved (HTTP 202) means
   the remote outcome is ambiguous. Keep its receipt and compare exact remote state
   before any approved reconciliation. Never resend blindly, including after a source
   edit: unresolved/reserved source attempts block later exports.
6. The same source-version/destination is reserved once across browser/API. Claims
   retain encrypted immutable results. A new source version represents a new export
   only after previous attempts have a definite outcome.

### Health and troubleshooting
Read health, sync-links and sync-events under the exact parent. Safe codes/error
presence replace raw provider text, credentials, machine IPs and arbitrary metadata.
Receipt reads need operations.read plus original action/source rights, original actor
and original token. Tokens never replace current native rights.

Disabled execution produces a rejected command with no remote call. For certificate
failure, fix the trusted HTTPS destination; never disable validation. Credentials
are never forwarded through redirects. No generic reset/retry API is provided.

### Existing Ticket and machine transports
Ticket APIs retain relationship-targets, escalation, relationship-escalations list/
detail and relationship-links with Published checks, native rights and durable
receipts. The five /api/v1/nexum/relationships endpoints retain machine token,
timestamp and raw-body HMAC; bearer tokens do not replace their authentication.
Public message/status/selected attachment policy remains intact. New received
Knowledge uses the relationship's configured local creator as ownership metadata;
a missing local owner produces validation rather than choosing an unrelated user.
No login identity or role grant is created.

### Deployment and review
Use only additive migration 2026_10_27_060000_create_relationship_api_commands.php,
normal optimize:clear and l5-swagger:generate. No new scheduler/worker/build.
Preserve APP_KEY, commands and claims on rollback and reconcile ambiguous effects.
Human-review checklist HR-2026-10-27-RELATIONSHIP-API remains Pending for real
two-instance/browser checks, gating production release. Main promotion is expressly
authorized separately. Employee OpenWebUI retrieval remains untested.
