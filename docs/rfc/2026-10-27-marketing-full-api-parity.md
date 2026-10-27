# RFC: Full Marketing Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex. Product/release owner and intended human reviewer: Svein Tore.

## Context

Svein requested: Marketing domain, 100% API support. The authoritative Dev inventory
finds 28 staff browser route rows, 3 public tracking/unsubscribe capability routes and
24 API route rows / 29 HTTP operations excluding HEAD. Generated Swagger contains no
Marketing paths. Existing API routes are operational but are not full browser parity.
The approved 2026-06-17 Marketing API RFC covers the existing API foundation; it does
not approve the complete design below. The 2026-06-09 ownership ADR and approved
2026-08-24 evergreen-contact-sequence RFC remain authoritative for business behavior.

All routes/actions and embedded form/detail workflows are mapped in
../plans/2026-10-27-marketing-api-coverage-matrix.md and .json. The full existing
Marketing test baseline passes 65 tests / 811 assertions on Dev. No implementation,
external provider call, real email send or production change is part of this plan.

## Goals

Expose every supported Marketing browser read, write, action, settings operation,
form/editor context and outcome through the authoritative versioned API. Keep all
existing 29 HTTP operations, provide typed request/result/error contracts and exact
read-back, enforce current native and token authority, and verify the complete
route-to-workflow matrix. Full support means operational equivalence, not only reads
or an endpoint count. Preserve supported public recipient capabilities unchanged.

## Non-Goals

New campaign lifecycle, repeat/resend, social/Google integrations, new segmentation
engine, sender/provider administration, Contact identity or consent redesign, role
grants, AI activation, real customer sends, queue backlog processing, frontend redesign,
Main commit/push/merge or production deployment. No new list/campaign deletion,
pause/resume or recovery operation is invented where it lacks a supported workflow.
Existing future integration backlog remains separate. No localization changes.

## Current Behavior

Lists resolve automatic/manual Contact and legacy Client User audiences with tags,
industry, location, contract, consent, suppression and explicit list exclusions.
Campaigns target multiple lists with deduplication. Email snapshots, campaign-level
calendar scheduling, recipient batch spacing, approval and evergreen per-contact
progression are implemented. Lifetime campaign-email/person/mailbox delivery claims
prevent automatic retransmission across list refreshes and historical cycles.
Uncertain provider outcomes block automatic retry and later sequence progression.

The API already supports lists/members, campaigns/schedules/emails, AI plan/draft,
approval, due-send enqueueing and settings. Dashboard, list/campaign editor context,
authoritative unsaved email preview and WordPress pull are missing. Campaign API
read-back omits cached content sources and interest summaries; recipient expansion
is optional but unbounded. Settings omit the safe sender summary visible in web.
API routes declare Sanctum authentication and scopes; unlike web, they do not declare
the native Marketing permission/admin boundary. API tests currently allow scope-only
actors. Test-send shares campaigns.update with normal editing; AI does too.
Read controllers invoke EnsureMarketingDefaults, which also assigns default role
permissions and may create/activate a Marketing AI agent. Ordinary reads should not
acquire those configuration side effects. No full typed Marketing OpenAPI exists.

## Proposed Change

1. Move route ownership into Marketing/routes.php with the established API loading
   branch; keep api.php as a compatibility loader. Preserve URLs, names and PUT/PATCH
   aliases. Do not add domain endpoints to routes/api.php or routes/web.php.
2. Add dashboard, list/campaign contexts, bounded searchable reference choices,
   campaign recipients/events/content-source reads and authoritative preview.
   Return the same consent categories, selected tags/industry labels, addable Contact
   and legacy choices, schedule options, active templates/snapshots, renderer variables,
   sender summaries, safe AI availability and interest/progression summaries as web.
   Use exact parent scoping for campaign emails/recipients/content. Never expose sender
   credentials, AI secrets or raw recipient tracking tokens. Keep Contact/Email/Taxonomy
   and Integration as the owners of their records; return only workflow-needed fields.
3. Add API WordPress content pull via the existing domain action, including exact
   cached-source read-back. Reuse the approved TLS/network policy and a shared URL
   boundary: reject unsafe schemes, embedded credentials, private/loopback/link-local
   and metadata destinations, including redirects/resolution. Fetch configured public
   WordPress sources without disabling certificate checks. Do not introduce a second
   external fetch engine or silently call a provider during discovery/preview.
4. Apply fresh native actor/status/object authorization as well as token abilities to
   every existing and new endpoint, including replay. Proposed native mapping:
   marketing.view for operational reads; marketing.list.manage for list changes/forms;
   marketing.campaign.create for campaign creation/form; marketing.campaign.edit for
   campaign/email/schedule changes, rendering and AI requests; marketing.campaign.approve
   for approval; marketing.campaign.send for due sending. Preserve the web admin plus
   marketing.settings.manage boundary for settings administration. Keep narrow writer
   form-context access available without granting broad read or other object access.
   Do not auto-grant rights to make existing integration tokens pass.
5. Retain the seven existing abilities and add marketing.context.read,
   marketing.providers.execute and marketing.operations.read only with corresponding
   routes/tests. Context also requires the applicable existing read/write scope and
   native action right; a context scope alone does not grant global recipient access.
   AI, WordPress and test-send require explicit provider execution authority in addition
   to their normal operation scope. Keep native test-send authority aligned with its
   existing web editor workflow; do not silently redefine that business permission.
   Add a default-off Marketing API provider-execution switch, independent of ordinary
   web sending/scheduling. Document the intentional compatibility tightening for
   scope-only actors/provider calls; no token or role is changed automatically.
6. Extract shared queries, validators and mutations where current web/API duplication
   risks divergent list eligibility, template snapshots, schedules, deletion/history
   retention or approval. Reuse existing actions, evergreen delivery claims, Email
   renderer and provider binding snapshots. Keep inactive/unknown historical records
   and canonical Contact evidence intact. Reads must not seed permissions or activate
   agents/providers; use explicit supported setup for defaults instead.
7. Add durable encrypted actor/token-bound command receipts, canonical payload
   fingerprints, unique command keys and opaque state preconditions. New mutations
   require Idempotency-Key; updates/deletes/actions that depend on current state use
   If-Match from current read-back. For legacy routes, accept unkeyed calls for
   compatibility and document their weaker retry guarantee; keyed calls use the shared
   boundary, reject changed payloads and reauthorize replay. Lock affected list/campaign
   state through the shared mutation path, including browser changes that can race.
   Preserve refreshed member identity references and prevent stale edits from overwriting
   concurrently changed audiences/schedules. No concurrent approval or create replay may
   duplicate campaign emails, recipient queues or external sends.
8. Provide side-effect-free action previews and exact own-operation read-back. A send
   preview describes current approval, suppression, quiet hours, sender and eligible due
   state; it does not seed recipients or authorize execution. Execute rechecks current
   authority and state. Due-send returns queued intent; SMTP test returns provider
   acceptance evidence, never inbox delivery. API-initiated queued work must retain the
   API origin and recheck applicable execution guard/current binding before external
   transmission. Existing web/scheduler behavior remains independent. A default-off
   provider guard must permit an authorized enabled success path; a permanent denial
   does not count as parity. Persist ambiguous external outcomes, never blindly replay.
9. Cover all Marketing operations in production-safe Swagger scanning with typed
   requests, paginated results, enums/references, success/error states, compatibility
   notes and safe examples. Preserve public pixel/redirect/unsubscribe media types and
   URLs; do not create bearer endpoints that bypass recipient suppression/capabilities.
   Public URLs are separately documented and tested, not counted as missing admin CRUD.

## Impact Analysis

Marketing owns routes, shared queries/actions, validation, resources, command storage,
provider entry boundaries and tests. Integration owns ability discovery and shared
Swagger integration; coordinate those shared files with active Knowledge and other
approved parity work before editing. Email owns accounts/templates/rendering/SMTP
and provider bindings; Contact owns consent/identity; LeadIntelligence owns existing
suppression inputs; Sales/Signal consume existing events. Preserve all these contracts.
Risks: scope-only integration compatibility, PII/credential exposure, stale concurrent
edits, audience member refresh references, accidental provider activation, URL fetch
abuse, repeat sends and ambiguous SMTP outcomes. Existing Marketing campaign Blade
contributor fix and all unrelated dirty Dev files must be preserved.

## Data And Migration Plan

Plan one additive Marketing-owned encrypted command/replay schema with unique
actor/token/key identity and a durable external-operation state. Record the final
migration filename only after approved implementation. Reuse existing campaign,
recipient, delivery, identity, content, event and settings tables; no historical
rewrite, role grant, consent backfill or destructive cleanup. If a queued intent needs
an additive reference, include it in the same reviewed migration and verify its
retention/revocation behavior. Preserve APP_KEY and retained command/delivery evidence.

Run only exact approved new migrations on Dev, not broad migrate in the dirty checkout.
Verify native MySQL uniqueness, locking, rollback and ciphertext. Future deployment
needs that exact migration, regenerated Swagger and normal cache handling, with worker
restart if job code changes. No frontend build or new scheduler cadence is planned.
Do not start workers or run marketing:send-due as a verification shortcut. Roll back
entry code/switches while retaining receipts and reconciling unknown sends.

## Testing Plan

Existing Dev baseline: 65 tests / 811 assertions, all passing, 2026-10-27.
Add meaningful positive/negative coverage for every matrix row, exact runtime/Swagger
method equality, real successful JSON response schema validation and request/errors.
Cover narrow rights, revoked/inactive actors/tokens, settings admin, foreign campaign
email access, least-data contexts, bounded pagination, unchanged consent/suppression,
manual/legacy segmentation, multi-list deduplication, template snapshots, unsaved
preview no-write/no-send, WordPress TLS/URL/redirect policy and AI provider fakes.
Test approved enabled provider paths as well as default-off denial, queue/read-back,
provider binding/revocation, same-key replay/conflict, stale/concurrent commands,
claimed/unknown delivery and no-resend after list refresh or email deactivation.

Run all Marketing tests plus affected Email renderer/provider, Contact, LeadIntelligence,
Integration ability/Swagger, Sales and Signal regressions based on changed contracts.
Use Dev PHP/Artisan, umask 0002 for view rendering, syntax/Pint/whitespace checks,
native DB contracts and trusted HTTPS auth/read-back. No real email or AI/WordPress
provider calls are needed for automated tests. Record any current failure rather than
claiming full completion. Human scenario approval stays separate from tests.

## Documentation Plan

Update Marketing README/Knowledge, add the full API guide, update API Management and
any affected operating guidance. Publish affected handbook articles through the
supported BookStack integration and read back actual content and staff access without
changing permissions. Record blocked publication, owner and next action in TODO;
pending_push/queued sync is not publication. Employee OpenWebUI retrieval is separate.
Maintain matrix/evidence, RFC/ADR/slices/TODO and human-review state in the same session.
Follow the public website handoff process after completion with explicit Dev-only and
do-not-publish status until production is verified. Planning itself changes no user
workflow and requires no handbook publication.

## Open Questions

No new marketing product behavior is needed. Approval of this concrete Level 3 design
is required before new implementation. The old approved API RFC remains historical
implementation authorization, not approval of new authorization/provider/schema changes.

## Approval

Approved by Svein Tore on 2026-10-27: "Godkjenner og merge til main".
Implement and verify sequentially on authoritative Dev, then promote a scoped tested
candidate to Main. Human scenario review remains Pending; this authorization permits
Main promotion with those checks outstanding, not production rollout or activation.
