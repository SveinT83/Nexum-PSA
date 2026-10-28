# RFC: Per-Connection Talk Notification Destinations

Status: Approved
Date: 2026-10-28
Owner: Codex / Svein Tore
Issue: #205; direct audited completion/Main authorization: "Fix alt og merge til main".
Parents: existing Nextcloud and Notification API parity RFCs explicitly exclude this new workflow.

## Target Behavior And Scope

Each Nextcloud connection exposes a Talk Notifications panel using its existing encrypted service
credentials: refresh accessible conversations, select multiple enabled destinations and one local
default, opt in/out separately, optionally create a private group conversation, send an explicit
fixed test to one exact selected destination, and inspect safe last-test state/time.
Notification Channels owns the global switch and a global default chosen from these paired
destinations. It lists connections/destinations with enabled/availability state and direct settings
links. Missing/disabled/unhealthy targets fail visibly; no implicit first-active connection fallback.
Global disable prevents new notification sends without disabling unrelated Nextcloud functions.

Ordinary delivery uses the service-user Chat API. Bot settings/tests remain optional advanced
features. Existing legacy webhook configuration is retained only as a hidden compatibility path;
normal setup/test never asks for a webhook URL or treats HTTP 2xx from an ordinary web page as a
successful Talk test. Selecting the new target explicitly migrates normal delivery. Existing
personal legacy targets are never silently rerouted to a different conversation.

## Pre-Implementation Baseline And Provider Evidence

NextcloudTalkClient has list/get/create room and user-context send methods, but room calls use
removed v1 endpoints and room type labels/default are wrong. Chat sending itself uses the v1
Chat API. Notification admin still renders Default Webhook URL and Settings > Webhooks instructions.
ManageNotificationChannels test accepts a generic HTTP-success webhook as provider-accepted,
while legacy runtime returns a confirmed-delivery reason. Channel routing supports explicit
connection selection but falls back to a default when no ID is configured. No destination table
or per-connection notification enable/default selection exists.

Verified primary contracts on 2026-10-28:
- https://nextcloud-talk.readthedocs.io/en/latest/conversation/ : room API v4 since Nextcloud 22.
- https://nextcloud-talk.readthedocs.io/en/latest/chat/ : v1 Chat POST, typed message receipt,
  bounded history read with read-marker/notification/presence side effects disabled.
- https://nextcloud-talk.readthedocs.io/en/latest/constants/ : private group type 2, read-only
  room state and chat permission bit. No external sending is authorized by reading those docs.

## Ownership, Authority And Technical Defaults

Nextcloud owns destination/receipt persistence, room discovery/creation, service-user transport and
connection settings. Notification owns the global switch/default, existing preferences, payload
minimization and runtime channel. Existing native nextcloud.connection_manage/talk_manage and
notification.manage_channels authority remain separate. No role/token/credential grant is added.
Connections must already permit writes for posting; private room creation requires managed mode.
Existing sender credentials, TLS verification and mode policies remain authoritative.

New browser actions stay in Nextcloud/routes.php. Mirror the new panel/actions with five typed
API operations under the same owner, fresh native permissions, explicit existing Talk scopes,
If-Match/Idempotency-Key/receipts, and default-off external API execution gates. Existing Notification
channel update/context/test APIs share the new global configuration/result shape and safe schemas.
No general broadcast body input, arbitrary URL input, per-user/client routing system or recipient
selection is introduced. Future routing must store connection plus conversation.

## Data And Concurrency

Two exact additive migrations create Nextcloud-owned paired destinations and durable delivery
receipts. Destination identity is unique on connection_id + conversation_token. Each cached destination is bound to an opaque provider-identity revision; hostname/account/credential changes require a fresh discovery. Available provider
names/tokens are bounded/escaped and visible only to current administrators. Refresh updates
availability for only that connection; missing rooms become unavailable, never another connection's
default. Connection settings hold an independently default-off Talk switch, local default ID and
a fresh revision marker, merging rather than replacing unrelated settings.

Receipts store hashed operation/payload identity, bound destination/connection metadata, safe
state/reason, provider message ID/reference and timestamps; no credentials or notification text.
A unique identity claims before network effects. Retrying completed/unknown claims cannot send
again or reroute after target changes. Existing API command mechanisms protect API replay; explicit
web test/create requests carry their own action identity. Ambiguous provider outcomes remain
unknown and require reconciliation; there is no blind retry.

Locks/preconditions protect local config, global default and send claims. Provider calls run
outside long SQL transactions, with current config/binding checked before claim/execution and
stale discovery results rejected. Disable stops new dispatch; already accepted/in-flight messages
cannot be recalled. Deleting connections must not erase historical receipt evidence.
Back up current relevant config encrypted and verify schema/cohort before exact Dev migration.
No legacy secret/target backfill, automatic enablement, provider send or old backlog release.

## Delivery Evidence And Migration

Normal tests require the global channel, selected connection and selected destination enabled and
valid. Use the service-user Chat POST and require a structured message ID plus matching token.
Read the exact message back through bounded history with setReadMarker=0, noStatusUpdate=1 and
markNotificationsAsRead=0 before reporting confirmed conversation storage. This does not prove
human receipt. Accepted-but-unverified or ambiguous outcomes are distinct and never resent blindly.

Keep legacy fallback inert when disabled and clearly marked accepted/unverified, never confirmed.
Existing explicit personal legacy targets require retain/clear/migration; new global mode must not
ignore an old different target or inspect another notification type's settings. Ordinary setup hides
obsolete instructions/URL inputs. Preserve optional advanced Bot API operations and signed inbound
semantics. All installed settings/provider gates stay unchanged during implementation/verification.

## Slices

### Slice TN1: Current Provider Contract And Paired Destination Storage

Status: Done On Dev. Owner: Codex. Parent: this RFC.
Goal/user behavior: correct service-user room discovery and stable per-connection choices.
Scope/data: strict v4 room/v1 chat contracts, current constants, additive destinations/receipts,
default-off connection settings, bounded typed context. No actual room/message creation.
Permissions: existing current Nextcloud administration. No source access or role expansion.
Tests: wrong-page 200/malformed OCS rejection, two connections with identical tokens, unavailable
room handling, safe fields/TLS, MySQL schema/unique/rollback and preserved config.
Documentation: this RFC, ADR, TODO, Knowledge and HR entry.
Done criteria: provider contract/storage/context regressions pass on Dev; no legacy rewrite.

### Slice TN2: Shared Service-User Delivery And Global Routing

Status: Done On Dev. Owner: Codex. Parent: this RFC.
Goal/user behavior: explicit exact destination, independent global/connection disable, truthful test.
Scope/data: guarded config, durable no-replay send/read-back, safe last result, runtime channel and
legacy migration behavior. No new live enablement, recipient grant or arbitrary payload endpoint.
Permissions: Notification channel administration and current connection Talk authority as applicable.
Tests: both kill switches, stale/missing/unhealthy targets, no fallback, failed/unknown/read-back,
replay/changed target/binding, current preference/legacy target isolation and secret canaries.
Documentation: source channel/Talk guides, handbook verification and production review.
Done criteria: shared web/API/runtime boundary and negative authority/delivery tests pass on Dev.

### Slice TN3: Normal Admin UI And Typed API

Status: Done On Dev. Owner: Codex. Parent: this RFC.
Goal/user behavior: discover/select/default/test per connection, global overview/default and optional
explicit private-room create using the service user. Existing Bot settings remain advanced.
Scope/data: Bootstrap panels, routes/controllers, typed API/OpenAPI and existing channel schemas.
No broad UI redesign, future per-user routing, new recipient grants or automatic test sends.
Permissions: fresh native permissions plus existing dedicated scopes; guarded provider writes
reuse default-off switches, explicit confirmation and exact replay/precondition boundaries.
Tests: real-route forms/API read-back, stale/foreign/scope denial, disabled/malformed provider
outcomes, no secret echoes, typed Swagger and existing Nextcloud/Notification consumers.
Documentation: exact menus/steps/troubleshooting; source/BookStack access/body read-back.
Done criteria: all new UI actions have typed/tested API coverage; normal webhook instructions gone.

### Slice TN4: Verified Main Candidate And Handoff

Status: Tested Main Candidate - merge/read-back pending. Owner: Codex / Svein Tore. Parent: this RFC.
Goal/user behavior: tested implementation available in Main with honest deployment status.
Scope/data: exact manifest/hunks from shared Dev, latest-Main reconciliation, relevant tests,
Issue/TODO/RFC/ADR/slice/handbook and public-safe handoff. No other contributor overwrite.
Permissions: explicit user tested-Main authorization; production/live sends remain separate.
Tests: scoped latest-Main suites, exact merged-tree read-back, no secret/artifact spill.
Documentation: HR-2026-10-28-TALK-DESTINATIONS remains Pending for named production review.
Done criteria: code/docs verified, Main merged and read back, Issue closed only when acceptance can
be proven. If a real external result cannot be proven safely, ask the author one concrete question
and retain that criterion/Issue open without repeating unchanged requests.

## Verification Evidence - 2026-10-28

- Final authoritative Dev batch: 46 tests / 1,389 assertions, all passing. This includes five
  new scoped API/native-surface cases, ten destination/runtime/negative-delivery cases, four
  service-user protocol cases, exact 55-operation/23-native-route Swagger inventory, Notification
  API failure/replay tests and existing Notification system workflows.
- Existing Nextcloud full API regressions passed in the preceding batch; the one new runtime
  fixture import failure was corrected and passed in the final batch. Existing inbound Email
  fanout/durability scenarios passed in the earlier broader batch; its obsolete Talk setup
  fixture expectations were updated and passed separately. Batches overlap. No full suite claim.
- Sixteen new PHP files pass explicitly scoped Pint; 27 owned PHP syntax checks pass. A broad
  formatter option was stopped after touching two pre-existing Contact test files; those files
  independently match existing Main exactly and are excluded from this manifest.
- Applied only 2026_10_28_020000_create_nextcloud_talk_notification_destinations and
  2026_10_28_021500_bind_talk_destinations_to_provider_identity on Dev. Protected encrypted
  configuration preflight was decrypted/read back. The native MySQL paired-binding/receipt/
  no-duplicate probe passed with all provider HTTP faked; all fixture rows rolled back and
  the exact global configuration hash matched afterward. Native Dev still has no configured
  Nextcloud connection; no actual provider or external recipient was used.
- Existing internal Knowledge articles 56/59/64 published through KnowledgeDocumentationPublisher
  and independently matched source, owner/visibility/placement retained (revisions 204/205/206;
  native outbound sync status remains pending_push). Actual BookStack articles 419/383/425
  read back as revisions 5/8/6 with the exact own guide sections (server trailing-newline
  normalization only); original content and current page permissions preserved. This is not
  a claim of employee OpenWebUI retrieval or a native sync job completing.
- Isolated latest-Main candidate starts at 891913cdc1c7713c84f398aba1dc61c5f2dc0393; 39 owned
  files copied/section-applied, private dependency copy and synthetic SQLite test environment.
  Dev source hashes and shared index digest were independently unchanged after extraction.
- Exact isolated latest-Main verification: 78 tests / 10,459 assertions, all passing,
  including existing full Nextcloud/Notification API, signed inbound webhook and system workflows.
- No real test message/group, new credential/role/provider grant, global activation, backlog
  release, production migration/deploy or browser human-review approval occurred.

## Deployment, Risks And Human Review

Exact additive migration and ordinary cache/Swagger refresh; no full pending migration run,
worker/scheduler activation, default changes, real recipient test or production deployment.
Retain encrypted config backup and durable receipt history. Rollback refuses destructive history
loss; disable entry points rather than dropping live evidence. Risks include provider permissions/
version differences, stale discovery/binding, duplicate/ambiguous effects, legacy migration and
cross-connection misrouting. Meaningful negative/concurrency tests and current read-back cover them.

HR-2026-10-28-TALK-DESTINATIONS gates production/manual scenarios; tests never mark it Reviewed.
An external real-message test needs an explicitly authorized exact recipient/conversation before
sending. No hard recipient choice or live activation is inferred from the broad code/Main request.
