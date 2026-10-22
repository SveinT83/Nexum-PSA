# RFC: Safe automatic Nextcloud calendar synchronization

Status: Approved - slices A/B Done On Dev; slice C awaits production pilot.
Date: 2026-10-22
Owner: Codex; product, privacy, release and production owner: Svein Tore.
Human-review checklist: HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC (planned; not reviewed).
Evidence: ../plans/2026-10-22-nextcloud-calendar-sync-diagnosis.md.

## Context

Svein Tore authorized repair of automatic Nexum/Nextcloud calendar sync in the voice
conversation on 2026-10-22. Svein subsequently approved this RFC, the
work-calendar-only first scope, implementation and Main merge in chat. The target is reliable
scheduled synchronization, verified in both systems without duplicates or unintended sharing.

The production connection exposes a 15-minute interval, but neither production's registered
Laravel schedule nor Dev implements a Nextcloud background job. Production already has
an exact minutely PHP 8.3 schedule:run cron for the portal; replacing or adding the global
scheduler is unnecessary. This confirms missing application scheduling as the primary cause.

The manual sync path also contains unsafe routing and conflict assumptions. Two active
two-way remote calendars, Svein Tore and Personlig, target the same Nexum calendar 2.
The push query selects every local/Nextcloud event in that local calendar without restricting
its external calendar identity. Local/remote hashes use different payloads; local changes may
be treated as two-sided conflicts and then still be eligible for an unconditional PUT.
The CalDAV writer derives a resource path from UID instead of preserving an existing href
and sends no conditional ETag guard. Import writes Calendar models directly despite the
module's documented Calendar action ownership. Automatic execution must not amplify this.

## Goals

- Run a due calendar-only synchronization at the configured interval and recover safely.
- Preserve local/remote identities, source calendar, owner, privacy, timezone and duration.
- Prevent duplicate creation, accidental cross-calendar export and lost concurrent edits.
- Verify the known Overhalla event and a controlled outbound update through actual read-back.
- Keep truthful skipped/conflict/error evidence and preserve unsupported provider data.

## Non-Goals

SSO, Talk, USK/calendar sharing, user/group/contact synchronization, file browsing, new access
grants, and destructive cleanup of historical calendar data. No automatic deletion or broad
historic event migration. No Main commit, merge or production deployment without Svein's request.

## Current Behavior

Manual RequestNextcloudSync reads capabilities, identities, calendars and files, then pulls/pushes
mapped events across one year back and two years forward. It stores synchronization status and
provider metadata. Last production success remains 2026-05-28. Baseline Dev Nextcloud tests pass
but do not cover due dispatch, ambiguous mapping, duplicates, ETag conflicts or recurrence safety.
Read-only production preflight found 95 Svein Tore remote events, five unlinked exact-title/time
local duplicate candidates, nine recurring events and one event without a parsed DTEND. Personlig
has 16 remote events, one recurring event. These counts cover the module's configured time window.

## Proposed Change

1. Use the existing per-connection interval and calendar enablement for a calendar-only due
   dispatcher/worker. Add an explicit reviewed activation target in persisted settings; existing
   deployments remain inactive until preflight and activation are approved. Recheck connection,
   interval and mapping state in the worker. Serialize manual and automatic sync per connection;
   use durable claims/attempt evidence and bounded retries so queue delay, restarts and repeated
   ticks do not create parallel provider writes. Scheduler registration alone is not health evidence.
2. Extract one shared calendar synchronization action used by the manual and scheduled paths.
   Automatic calendar sync does not import users/groups or depend on file-root availability.
   Use a Calendar-owned integration action for event changes; preserve the existing Workday
   absence protection and overlapping contributor work.
3. Fail closed on ambiguous outbound mapping. Each exported event has one source/target identity
   scoped by connection, mapping, local event and remote UID/href. A bound event never migrates to
   another remote calendar through batch ordering. Unbound local events require a unique reviewed
   destination. Refuse unsafe owner/scope changes rather than broadening access.
4. Preflight all selected mapping events before activation. Existing unlinked title/time matches
   are candidates for explicit reconciliation, never proof that records are the same. Suppress
   creation/export of ambiguous candidates and record review evidence. Preserve existing IDs and
   avoid title/time-only automatic merging. Do not delete either copy to hide duplicates.
5. Compare canonical event fields against the last common snapshot. Pull remote-only edits, push
   local-only edits, skip unchanged events, and retain both-sided edits as conflicts with no PUT.
   Preserve provider href and use conditional ETag writes; a failed/unknown write is reconciled by
   read-back before retry. Existing identity metadata needs a safe first-baseline plan.
6. Preserve valid all-day events, TZID/UTC timestamps and duration semantics. Recurrence, overrides,
   attendees/organizer and unsupported fields must round-trip faithfully or be explicitly retained
   as unsupported/conflict with no destructive overwrite or silent series flattening. Calendar
   recurrence capabilities must be assessed before the final implementation slice is approved.
7. Recommended first live scope: only remote Svein Tore to local calendar 2. Keep Personlig out of
   automatic execution until Svein chooses its intended treatment; do not silently mix private and
   work calendars. Unconfigured Benjamin/holiday mappings remain outside the pilot. This is a
   proposal, not an applied configuration change. Existing Personlig-origin events in calendar 2
   must never be exported to Svein Tore; no automatic relocation of those historical records.

## Impact Analysis

Nextcloud owns dispatch, provider transport, mapping/attempt state and conflicts. Calendar owns
local event mutation, visibility and recurrence. Shared routes/console.php already has unrelated
dirty work; append only the scoped scheduling registration after approval. Existing Calendar,
RequestNextcloudSync, NextcloudReadClient and NextcloudModuleTest modifications must be preserved.
No new public API or permission grants. UI changes, if needed for honest activation/status, require
reading docs/ui-guidelines.md first and English labels. No email invitations during verification.

## Data And Migration Plan

Inventory schema and current metadata before deciding additive claim/binding constraints. Use
additive migrations only if durable uniqueness cannot be expressed safely with existing tables.
Document exact Dev migrations and rollback before applying them. No direct SQL edits to events.
Preserve all historical events, mapping IDs, hashes and conflict evidence. Rollback pauses the
calendar worker/activation and retains bindings/attempt logs; do not delete synchronized data.
Production rollout requires the tested scoped code, required migrations, worker refresh and exact
scheduler/worker execution evidence; never enable the whole application scheduler as a workaround.

## Testing Plan

Dev regressions must prove due/not-due/paused/inactive/unmapped cases, concurrent/manual overlap,
queue retries, stale claims, one connection failing while another proceeds, no identity/file writes,
owner mismatch, several remotes mapped to one local calendar, existing unlinked duplicate candidates,
unchanged/local-only/remote-only/two-sided edits, ETag 412, unknown PUT outcomes, stable resource href,
private/confidential visibility, all-day/DURATION/timezone/recurrence preservation and Workday guards.
Use Http::preventStrayRequests with fakes for tests; no production credentials in the test database.
Run affected Nextcloud and Calendar tests on authoritative Dev. Add regressions that fail before the
fix where practical. Only after approved deployment/activation: bounded read-back of the Overhalla
meeting in both systems, a clearly identified controlled round trip, a second scheduled cycle with
unchanged counts/identities, and mapping/privacy verification. Cron presence alone does not prove
execution, and successful PUT alone does not prove correct final provider state.

## Documentation Plan

Update Nextcloud/Calendar Knowledge sources, module README implementation status, TODO and the
human-review entry. Publish/read back affected employee BookStack articles through the supported
integration; record any blocked publication with owner/action. Employee AI retrieval is a separate
claim. Maintain sanitized operational evidence with no credentials or private calendar contents.

## Feature Slices

- A: safety and identity reconciliation. Goal: shared action, mapping/duplicate/conflict/ETag and
  parser guards proven on Dev. Data: events, binding metadata and conflicts. Preserve access scope.
  Done: focused failing-before regressions and affected tests pass; no live write.
- B: due dispatcher/worker and operational status. Goal: repeatable interval-based calendar-only
  execution. Data: connections, claims/jobs/logs. Done: concurrency/retry/interval tests, scheduler
  registration, docs and review checklist prepared; activation remains off.
- C: approved pilot and verification. Goal: actual scoped synchronization in both systems. Requires
  selected calendar scope, reconciliation decisions, reviewed release and separate Main/production
  authorization. Done: two actual automatic cycles, stable identities/counts, privacy check,
  provider read-back, BookStack read-back and Svein's explicit human review. Expand scope only after
  this evidence. Feature slice details may be refined under this RFC before coding each slice.

## Open Questions

Initial scope approved: automatic sync for Svein Tore only; Personlig remains excluded pending
a separate mapping decision. Exact duplicate candidates require
reconciliation evidence before any affected live event can be adopted or exported.

## Approval

Approved by Svein Tore on 2026-10-22 in chat: Godkjenner. Fix og merge til main.
Approval covers the proposed Svein Tore-only first scope and implementation/Main merge.
Production deployment/activation and completed human scenario verification remain separate.
Implementation refinement: unsupported recurring resources are retained/skipped, never flattened;
nonrecurring resources with provider-only fields can be imported as read-only projections, with
outbound writes blocked. A durable per-connection lease and deterministic conditional resources
serialize manual/automatic work. No provider delete operation is introduced.

## Verified implementation

Slices A/B: Done On Dev. Affected tests: 94 / 510 assertions on Dev and the isolated Main candidate.
Additive Dev migration applied; both lease tables empty and automatic activation off.
Slice C: Pending separately authorized production deployment/activation and human pilot review.
See ../plans/2026-10-22-nextcloud-calendar-sync-verification.md. Main merge explicitly authorized.
