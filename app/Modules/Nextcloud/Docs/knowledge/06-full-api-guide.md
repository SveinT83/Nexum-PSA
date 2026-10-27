Availability: verified on Dev. Production deployment and provider activation remain separate.
Production rollout is separate. New provider execution defaults to disabled. Current browser
workflows are covered; this guide does not describe unimplemented upstream server features.

## Prerequisites

Use an active internal Nexum account with the same native rights as the corresponding web
operation. API Management grants narrow Nextcloud token abilities in addition to those rights.
Open the connection under `Admin -> System -> Nextcloud` and verify global/client/site context,
service account, selected folders, default import site and calendar owners before acting.
Use trusted HTTPS and never place real service passwords, bot secrets or API keys in examples.
Swagger is the exact source for request fields, scopes, required headers and typed responses.

## Read, change, verify

1. GET `/api/v1/nextcloud/connections` with optional scope/client/active/search filters.
2. GET `/context?kind=clients|sites|contacts|users|roles|calendars` for bounded form choices.
   Use `/connections/{connection}/context` to restrict choices to a customer connection.
3. GET `/connections/{connection}` and retain its opaque `ETag` header. Responses show only
   configured markers for secrets, sanitized settings and diagnostic state.
4. For a mutation, send a unique 8-200 character `Idempotency-Key` and the connection's exact
   `If-Match`. Creating a new connection needs a key but has no existing connection ETag.
5. PATCH only supplied fields; omitted settings and secrets remain unchanged. Secret writes
   require `nextcloud.credentials.write` in addition to the operation scope. Customer/site
   modes stay read-only and client/site ownership must match.
6. Read the exact resource/mapping and operation receipt. Keep the operation ID until verified.
   Local writes, audit and encrypted receipt commit together. Remote intent commits before I/O.

All paths below use `/api/v1/nextcloud`. List/lookups accept `page` and `per_page` (1-100).
All new write/action routes require the original native permission plus their named abilities.
Additional `nextcloud.providers.execute` and the default-off runtime switch protect full sync
and Talk tests. Health only reads provider capabilities and records sanitized local health.

## Complete operational API

| Method | Path | Token abilities | Result |
|---|---|---|---|
| GET | `/context` | `nextcloud.connections.read` | Context |
| GET | `/connections` | `nextcloud.connections.read` | ConnectionPage |
| POST | `/connections` | `nextcloud.connections.write` | Connection |
| GET | `/connections/{connection}` | `nextcloud.connections.read` | Connection |
| GET | `/connections/{connection}/context` | `nextcloud.connections.read` | Context |
| PATCH | `/connections/{connection}` | `nextcloud.connections.write` | Connection |
| DELETE | `/connections/{connection}` | `nextcloud.connections.write` | Deleted |
| GET | `/connections/{connection}/users` | `nextcloud.mappings.read` | UserMappingPage |
| POST | `/connections/{connection}/users` | `nextcloud.mappings.write` | UserMapping |
| GET | `/connections/{connection}/users/{userMapping}` | `nextcloud.mappings.read` | UserMapping |
| DELETE | `/connections/{connection}/users/{userMapping}` | `nextcloud.mappings.write` | Deleted |
| GET | `/connections/{connection}/groups` | `nextcloud.mappings.read` | GroupMappingPage |
| POST | `/connections/{connection}/groups` | `nextcloud.mappings.write` | GroupMapping |
| GET | `/connections/{connection}/groups/{groupMapping}` | `nextcloud.mappings.read` | GroupMapping |
| DELETE | `/connections/{connection}/groups/{groupMapping}` | `nextcloud.mappings.write` | Deleted |
| GET | `/connections/{connection}/calendars` | `nextcloud.mappings.read` | CalendarMappingPage |
| POST | `/connections/{connection}/calendars` | `nextcloud.mappings.write` | CalendarMapping |
| GET | `/connections/{connection}/calendars/{calendarMapping}` | `nextcloud.mappings.read` | CalendarMapping |
| DELETE | `/connections/{connection}/calendars/{calendarMapping}` | `nextcloud.mappings.write` | Deleted |
| GET | `/connections/{connection}/folders` | `nextcloud.folders.read` | FolderMappingPage |
| POST | `/connections/{connection}/folders` | `nextcloud.folders.write` | FolderMapping |
| GET | `/connections/{connection}/folders/{folderMapping}` | `nextcloud.folders.read` | FolderMapping |
| DELETE | `/connections/{connection}/folders/{folderMapping}` | `nextcloud.folders.write` | Deleted |
| GET | `/connections/{connection}/files` | `nextcloud.folders.read` | Files |
| PATCH | `/connections/{connection}/folders` | `nextcloud.folders.write` | Connection |
| POST | `/connections/{connection}/folders/auto-match/preview` | `nextcloud.folders.write` | MatchPreview |
| POST | `/connections/{connection}/folders/auto-match/apply` | `nextcloud.folders.write` | MatchResult |
| POST | `/connections/{connection}/health` | `nextcloud.health` | Health |
| POST | `/connections/{connection}/sync/preview` | `nextcloud.sync` | SyncPreview |
| POST | `/connections/{connection}/sync` | `nextcloud.sync`, `nextcloud.providers.execute` | Attempt |
| GET | `/connections/{connection}/attempts` | `nextcloud.connections.read` | AttemptPage |
| GET | `/connections/{connection}/attempts/{attempt}` | `nextcloud.connections.read` | Attempt |
| GET | `/connections/{connection}/talk-bot` | `nextcloud.talk.read` | Talk |
| PATCH | `/connections/{connection}/talk-bot` | `nextcloud.talk.write` | Talk |
| POST | `/connections/{connection}/talk-bot/test` | `nextcloud.talk.execute`, `nextcloud.providers.execute` | TalkResult |
| GET | `/operations/{operation}` | `nextcloud.operations.read` | Operation |

## Mapping behavior

User/group/calendar/folder POST operations create or update the existing native mapping key;
there is no second register. Each type has bounded collection/exact item read-back and deletion.
Global user mappings link existing internal identities or customer contacts. Customer user
mappings require `mapping_action`: skip, map_existing, or import. Import requires a default site
belonging to the same customer and uses canonical Contact identity with a stable compatibility
bridge. Skip is preserved during group imports. Customer roles remain customer roles.
Calendar mapping preserves active personal-owner validation, idle leases and activation pause.
Folder mapping remains local configuration and does not create a remote folder.

## Browsing and matching folders

GET `/connections/{connection}/files?path=/Clients` reads one live WebDAV level. Invalid traversal
and disabled features are rejected. A folder with over 500 entries requires a smaller path.
PATCH `/folders` selects `folder_type: root|documents` and `remote_path`.
POST `/folders/auto-match/preview` proposes deterministic and optional existing configured AI
matches without storing mappings. Review the exact client/path pairs. POST `/apply` with the
returned `preview_token` and current ETag stores only that proposal; it never reruns AI.
A changed connection/mapping, wrong token/actor or expired proposal is refused. Ambiguous names,
duplicate clients and already mapped paths must not be applied arbitrarily.

## Full manual sync versus calendar-only sync

POST `/connections/{connection}/sync/preview` shows whether contact import/calendar effects
are enabled and which groups/calendars are selected. It does not sync or activate them.
POST `/sync` requires that preview token, `confirm: true`, key and current ETag, native sync
rights, `nextcloud.sync`, `nextcloud.providers.execute` and enabled API provider execution.
It runs the existing full manual workflow, including enabled identities/groups/files/calendars.
Read `/attempts/{attempt}` for sanitized counts and success/partial/failed state; partial means
some conflict, identity error or unsupported resource requires review. Accepted is not delivery.

The existing 14 `/calendar/connections/...` API operations remain compatible: connection/mapping/
attempt/conflict reads, preview, mapping upsert/delete, activation, health, sync and exact conflict
preview/apply. Their existing scopes, Calendar visibility, durable leases, selected-target consent,
conditional ETags and runtime gates remain authoritative. New general grants do not expand the
production pilot or authorize deletion, historical reconciliation or recurrence flattening.

## Talk configuration and test

GET/PATCH `/talk-bot` exposes safe configuration with a write-only shared secret. POST
`/talk-bot/test` sends a fixed test message only to the selected connection's configured default
conversation with explicit confirmation and the necessary native/token/runtime rights.
An acknowledgement returns a provider message ID, connection/conversation and request reference.
`delivery_verified: false` states that recipient receipt is unproven. An HTTP 200 page or an OCS
failure is not message delivery. The incoming HMAC webhook retains separate authentication.
Notification owns channel/user preferences; this API does not grant new notification destinations.

## Retry, stale changes and uncertain outcomes

- 401/403: authenticate or resolve current actor/token/target rights; never widen grants to bypass.
- 404: the record is absent or belongs to another connection. Exact successful delete requests
  can replay with the original key, payload and If-Match; current authority is still required.
- 409: reused key with changed payload, expired/stale preview, runtime disabled or active lease.
- 412: connection/mapping state changed; obtain a new read and review before a new command.
- 422: invalid/unsupported fields, wrong customer/site, path or proposal. Correct the exact cause.
- 428: missing idempotency key or required connection If-Match header.
- 502/503: unavailable provider, migration or audit dependency; inspect status before retry.

For a lost response retry the identical method, path, payload, key and original If-Match.
Read `/operations/{operation_id}` with the same token and current original operation rights.
`unknown` or `running` requires reconciliation; the same key never sends another remote message
or writes again. Inspect the exact provider identity/conversation and retained Calendar evidence
before creating a new intent. Never infer synchronization from queue acceptance alone.

## Availability and recovery

Human-review checklist HR-2026-10-27-NEXTCLOUD-API remains Pending until named acceptance;
Main merge is separately authorized. Production deployment/provider activation is not authorized
by a Main merge. Retain APP_KEY, encrypted receipts, mappings and Calendar claims on rollback.
Handbook publication and successful employee AI retrieval are independent verification claims.
