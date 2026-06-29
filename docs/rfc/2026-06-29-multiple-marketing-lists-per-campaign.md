# RFC: Multiple Marketing Lists Per Campaign

Status: Approved
Date: 2026-06-29
Owner: TD-Jonathan / Codex

## Context

Issue #111 requested that one Marketing campaign can target several existing contact lists. Before
this change, a campaign stored one `marketing_list_id`, so users had to create duplicate combined
lists when one advertisement should go to several audiences.

## Goals

- Allow campaign creation and API writes to attach one or more Marketing lists.
- Preserve existing single-list campaign behavior and API payloads using `marketing_list_id`.
- Generate campaign recipients from all selected lists.
- Deduplicate recipients before queueing so a Contact, legacy `client_users` record, or normalized
  email address receives each campaign email only once.
- Show all selected campaign audience lists in technician and API surfaces.
- Protect lists used by campaigns from deletion, including lists attached only through the new pivot
  table.

## Non-Goals

- No per-list scheduling rules.
- No per-list email content variants.
- No automation workflow builder.
- No change to unsubscribe semantics.
- No removal of the legacy `marketing_campaigns.marketing_list_id` column in this slice.

## Current Behavior

Marketing campaigns use one primary `marketing_list_id`. Approval resolves that list and creates
recipient queue rows for active campaign emails. Mailing lists used by the legacy campaign relation
are protected from deletion.

## Proposed Change

Add `marketing_campaign_marketing_list` as a campaign/list pivot table. Campaign create/update
accepts `marketing_list_ids` while keeping `marketing_list_id` as a backward-compatible single-list
payload. The first selected list remains stored in `marketing_campaigns.marketing_list_id` until a
later cleanup removes the legacy column.

Recipient sync loads all selected audience lists. If no pivot rows exist, it falls back to the
legacy primary list. Sync deduplicates by Contact id, legacy client-user id, and normalized email.
Technician views and API resources expose the selected list collection.

## Impact Analysis

Affected module: Marketing.

Affected data:

- New pivot table: `marketing_campaign_marketing_list`.
- Existing campaigns are backfilled into the pivot from `marketing_campaigns.marketing_list_id`.
- Recipient generation reads all selected campaign lists.
- Mailing list deletion checks both the legacy and pivot relationships.

Affected API:

- Campaign create/update may use `marketing_list_ids`.
- Existing `marketing_list_id` payloads continue to work.
- Campaign resources include `marketing_list_ids` and `lists` when the relationship is loaded.

Affected queues:

- Existing due-send job continues to call the same recipient sync action, now using all audience
  lists.

## Data And Migration Plan

Create the pivot table with unique campaign/list pairs. Cascade when campaigns are deleted. Restrict
list deletion at the pivot foreign key and enforce application-level deletion guards so campaign
history is not silently detached. Backfill every existing campaign with a non-null
`marketing_list_id` into the pivot.

Rollback drops the pivot table only. The legacy `marketing_list_id` column remains intact.

## Testing Plan

- Feature test campaign creation with two selected lists.
- Verify pivot rows are stored.
- Verify recipient queue creation deduplicates same Contact and same normalized email across lists.
- Verify API create supports `marketing_list_ids` and returns selected list data.
- Verify lists attached only through the pivot cannot be deleted.
- Run the focused Marketing feature test suite when the local migration state permits it.

## Documentation Plan

- Update Marketing README.
- Update Marketing Knowledge documentation.
- Update `docs/TODO.md` active Marketing workstream.

## Open Questions

None for this slice.

## Approval

Approved by Svein Tore Ramstad in conversation on 2026-06-29 after issue #111 review found broken
multi-list behavior that needed repair before handoff.
