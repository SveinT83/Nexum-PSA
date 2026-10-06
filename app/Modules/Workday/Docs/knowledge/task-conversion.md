Use **My workdays > a saved day > Create internal Task from saved activity** when recorded internal work needs a separate Task.

## Preview before creating

1. Save the workday first. Choose one saved interval, or a smaller range inside it.
2. Enter a Task title. The form suggests the saved activity description; review the exact text that will become both the Task description and its time note.
3. If the current day is confirmed, give a correction reason.
4. Choose **Preview internal Task**. Check the internal Work Context, owner/assignee, open status, visibility and actual minutes. Excluded breaks are removed; included breaks remain part of actual time.
5. Explicitly accept the statement and choose **Create internal Task**.

The Task is standalone, owned by and assigned to you, with internal visibility under existing Task permissions. It has one non-billable actual time entry. Conversion never completes the Task, confirms the day or creates Ticket billing, invoice basis or Commercial time consumption. Task descriptions may be visible to other users with ordinary Task access; review the text before publishing it into that domain.

The receipt links to the created Task. The saved workday contains source attribution for those minutes, and its total actual time stays unchanged. A range crossing an excluded break has several source placements referencing the same single Task time entry. The Task entry stays date-level in that case; no continuous interval across an unpaid break is invented.

## Existing time and corrections

If Task or Ticket time already exists, use **Review sources and allocations** to link it. Conversion is for new internal Task time. It requires remaining unattributed actual minutes and no overlap with existing placements. Date-level allocations must be placed before conversion so their minutes can be distinguished. Concurrent labels belong in the work description.

A prior confirmed allocation remains effective while a correction is pending. Review and confirm its replacement before reusing time it reserves. Converted ranges also remain protected against repeated creation after allocation removal or a workday edit, including adjacent overnight work dates. Restore the original allocation or review the existing Task instead of creating its time again.

A confirmed day receives a correction draft and retains its previous confirmation. Explicitly review and confirm the replacement when ready. Later Task/source edits require the existing reconciliation process. Editing a Workday never rewrites the original Task time automatically.

## Access, retries and retention

Both UI and employee API require workday.manage_own plus task.view, task.create and task.update. The authenticated active employee owns the target; no delegated worker, Client or Ticket input is accepted. Coordinator-bound and system credentials are denied.

A preview is tied to the saved version and expires after at most 30 minutes. Expired, consumed or stale previews cannot create time. Changed target configuration or source access/content requires a fresh review. Identical retries reuse the saved receipt and do not duplicate Task/time.

The retained receipt describes the original creation, not later Task edits. Open the Task and saved Workday for current state. Workday previews, duplicate-prevention evidence and responses expire with the workday's original three-year deadline. The Task and its time remain Task-owned records under that domain's policy.

## Employee API

All three operations require workday-task-conversion.write, tasks.read, tasks.create and tasks.update, in addition to the permissions above:

| Method | Path | Result |
| --- | --- | --- |
| POST | /api/v1/workdays/{id}/task-conversions/preview | Exact saved activity and target preview |
| POST | /api/v1/workdays/{id}/task-conversions | Task/time IDs and the new draft revision |
| GET | /api/v1/workdays/{id}/task-conversions/{token} | Owned preview or retained creation receipt |

Both POST operations require **Idempotency-Key** and the current workday version. On a timeout, retry the exact same key and body. Read the latest state separately with GET. A reused key with a different request or stale version returns 409; unavailable/overlapping source time or invalid input returns 422. Additional source read scopes are needed when the saved day already references other domains.

Example preview body:

```json
{
  "version": 1,
  "interval_index": 0,
  "start": "2026-10-01T08:00+02:00",
  "end": "2026-10-01T10:00+02:00",
  "title": "Internal maintenance",
  "description": "Reviewed the internal monitoring configuration."
}
```

interval_index is zero-based. Omit start/end, or use null, to select the whole saved interval. Dates follow the normal Workday timezone/DST rules. Add reason when converting a confirmed revision.

After showing preview.activity and obtaining the employee's explicit instruction, create:

```json
{"version": 1, "preview_token": "UUID-from-preview", "create_task": true}
```

Read conversion.task_id, task_time_entry_id, source_key and minutes, then GET the conversion token, Workday and Task. Do not set create_task=true from background activity, day confirmation or an AI suggestion. Personal bearer API behavior is verified independently. LiteLLM/MCP adapter work and live tool verification are deferred.

Knowledge/BookStack sync: include this article with the Workday and Task guides after approved rollout.
