Risk manages internal and Client-specific assessments, individual risk items and their history.

## Availability

The expanded API and the approval/history corrections described here are implemented on Dev
as of 2026-10-26. Main promotion and production deployment have not been performed for this change.
HR-2026-10-26-RISK-API was accepted by Svein Tore on 2026-10-26. Main and production
promotion still require separate authorization and verification.

## Open and create an assessment

Prerequisite: an active technician account with Risk access. An API token never grants missing
employee permissions.

1. Open **Risk** in the technician workspace, at /tech/risk.
2. Select the internal or Client context. Open the assessment creation form.
3. Enter the title and optional description. Select internal scope or an active Client.
4. Save, then open the assessment detail page.
5. Check the saved scope, title and status. New records use the configured Risk defaults.

An internal assessment has no Client. A Client-specific assessment must reference an active Client.
The saved Work Context follows that selection. Browser lists use the active workspace context;
API lists use explicit filters and never change the technician's workspace.

## Add and review risks

1. Open an assessment and add an item using its item form.
2. Enter its title, category, description and recommended actions. Confirm likelihood and impact,
   each from 1 to 5, and the next review date.
3. Save. The item receives one initial history entry. Score equals likelihood multiplied by impact.
4. Open the item and use **Add Update** to record a decision, new score or status.
5. Inspect the timeline and current snapshot after saving.

Use descriptive editing for title, description, recommended actions, conclusion, category and review
date. Once history exists, changed likelihood, impact or status must use **Add Update**. A conflicting
edit is rejected without saving the other fields. Saving unchanged hidden score fields remains valid.
Only Superuser or the creator of the first remaining history entry may edit item details.

## Approval and subsequent changes

Approval requires at least one active item and every active item must be **mitigated** or **accepted**.
Open or unknown older statuses block approval until deliberately reviewed. Existing statuses are not
automatically renamed or accepted.

Approve from the assessment detail page after checking all items. The saved approver and time describe
that sign-off. Repeating an already current approval does not replace its time or author.

Adding, editing or deleting signed-off item/history content reopens the assessment to **in_progress**.
The previous approval stamp remains historical evidence. API clients must inspect **approval_valid**
and the current status before describing an assessment as currently approved.

## Delete with care

- Assessment and item deletion requires Superuser and the current Risk delete permission.
  These are soft deletions; no restore or force-delete workflow is added.
- Deleting a history entry requires its exact creator or Superuser, plus Risk delete permission.
  The current item snapshot is restored from the newest remaining entry, using time and ID order.
- Deleting the final history entry leaves the current snapshot unchanged and an empty timeline.
  It does not invent a replacement baseline or grant another employee ownership.
- Item/history API paths become unavailable when their assessment or item has been deleted.

## Reports, API and troubleshooting

Export PDF from the assessment detail page. Browser and API use the same report, category grouping,
history and score thresholds: 16+ Critical, 10+ High, 5+ Medium, otherwise Low.

See **Risk API Support** for API keys, every endpoint, current rights, revision protection and replay.
See **Risk Settings** for defaults. If approval is unavailable, inspect all active item statuses.
If an item edit is denied, check its first remaining history creator and your current permissions.
An old approval timestamp does not by itself mean the changed assessment is still signed off.
