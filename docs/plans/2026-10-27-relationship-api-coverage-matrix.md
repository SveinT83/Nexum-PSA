# Relationship Browser/API Coverage Matrix

Status: Implemented on Dev; all 21 staff and 5 signed operation success contracts verified.
The final current-Main release suite passes 338 / 7406; Dev scoped passes 29 / 2821.
Date: 2026-10-27. Owner: Codex.
Parent: ../rfc/2026-10-27-relationship-full-api-parity.md

Authoritative runtime: /var/Projects/tdPSA on Dev. Ten owned browser route rows,
five signed machine ingress operations and five existing Ticket-owned operations.
Counts exclude HEAD and unrelated Email relationships. Exact runtime data and
source hashes are in the companion JSON. Future route/schema/test/read-back evidence
now include positive HTTP/schema checks for every staff and signed operation; Main and
production status remain separate.

| ID | Method | Browser URI | Class | API target | Required evidence |
| --- | --- | --- | --- | --- | --- |
| R01 | GET | tech/admin/system/relationships | read | GET /api/v1/relationships | List, pagination, client/vendor labels, counts and active/failing stats |
| R02 | GET | tech/admin/system/relationships/create | read | GET /api/v1/relationships/context; GET /api/v1/relationships/lookups | Defaults, enums and authorized active Client/Vendor/queue choices |
| R03 | POST | tech/admin/system/relationships | write | POST /api/v1/relationships | Create all supported fields, credential custody and exact receipt |
| R04 | GET | tech/admin/system/relationships/{relationship} | read | GET /api/v1/relationships/{relationship}; health; sync-links; sync-events; eligible-documentations; eligible-knowledge | Safe identity/settings/health, scoped history and eligible source catalogs |
| R05 | GET | tech/admin/system/relationships/{relationship}/edit | read | GET /api/v1/relationships/{relationship}/context | Current editable fields/options and opaque version; no credentials |
| R06 | PATCH | tech/admin/system/relationships/{relationship} | write | PATCH /api/v1/relationships/{relationship} | All form settings, pause/disable, omission preservation and preconditions |
| R07 | POST | tech/admin/system/relationships/{relationship}/rotate-secrets | action | POST /api/v1/relationships/{relationship}/rotate-secrets | Selected inbound/signing-secret rotation, one authorized generated inbound result |
| R08 | POST | tech/admin/system/relationships/{relationship}/documentations/{documentation}/push | action | POST /api/v1/relationships/{relationship}/documentations/{documentation}/preview; POST same /push | Exact eligible document/source/destination preview, guarded execution and outcome |
| R09 | POST | tech/admin/system/relationships/{relationship}/knowledge/{article}/push | action | POST /api/v1/relationships/{relationship}/knowledge/{article}/preview; POST same /push | Published/audience-authorized source, guarded execution, revision/conflict preservation |
| R10 | POST | tech/tickets/{ticket}/relationships/escalate | action | Existing Ticket target/escalate/receipt/link APIs | Published Ticket scope, active/capable destination, existing receipt/idempotency |

## Embedded configuration
Cover name, direction/type, Client/Vendor, remote URL/instance/organization identity,
status, capabilities, ticket queue/auto-create, service areas, status mapping,
two-way Documentation policy, attachment maximum/types and three credential inputs.
PATCH preserves omitted values; explicit clear is validated. Read projections never
include stored credentials, hashes, raw metadata or unfiltered provider errors.
Context and eligibility must respect narrow native permissions and source access.

## Signed ingress (preserved)
- POST /api/v1/nexum/relationships/tickets
- POST /api/v1/nexum/relationships/tickets/{remoteTicketId}/messages
- POST /api/v1/nexum/relationships/tickets/{remoteTicketId}/status
- POST /api/v1/nexum/relationships/documentation
- POST /api/v1/nexum/relationships/knowledge/articles

Document exact raw-body HMAC/token/timestamp authentication, request/success/error
contracts and receiver idempotency; do not relabel these as staff bearer endpoints.

## Reused Ticket operations
- GET /api/v1/tickets/{ticket}/relationship-targets
- POST /api/v1/tickets/{ticket}/relationships/escalate
- GET /api/v1/tickets/{ticket}/relationship-escalations
- GET /api/v1/tickets/{ticket}/relationship-escalations/{escalation}
- GET /api/v1/tickets/{ticket}/relationship-links

Keep existing scopes, native Ticket/Relationship rights and exact receipt semantics.

## Additional API read-back
Proposed GET /api/v1/relationships/operations and /operations/{operation} provide
safe actor/token/action-bound receipts. Detail reads for sync-links/{link} and
sync-events/{event} must enforce exact parent ownership. A health subsection may
share detail projection but must preserve all browser evidence. These support parity,
not new business workflows.

## Completion evidence
For each operation record runtime method/path, native/scoped authority, request/
response schemas, positive feature test, denial test, actual HTTP response-schema
validation and exact persisted result/side effects. Record Dev/Main/production,
migration, provider activation, BookStack publication and human review separately.


## Verified implementation evidence

RelationshipApiTest covers all 21 staff operations and five signed success bodies;
RelationshipApiContractTest proves exact runtime/Swagger methods. Existing Ticket
Relationship tests remain reused. Source creation owner regression is fixed with the
configured Relationship creator, without impersonation or grants. Native MySQL
unique replay/encryption/concurrent locking/rollback pass. Actual BookStack 510/511
were read back exactly and retain inherited access. Final current-Main release verification passes 338 / 7406, no failures/errors/skips.
