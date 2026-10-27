# Warroom Browser/API Coverage Matrix

Status: Verified On Dev / Human Review Reviewed by Svein Tore, 2026-10-27. Date: 2026-10-27.
4 browser HTTP operations (3 read, 1 write), one overdue query variant and embedded
navigation/action discovery map to 7 official HTTP operations with 25 typed schemas.
Baseline 15/103; final domain 31/619 passed. Main/production promotion not performed.

| ID | Browser surface | Runtime API route names | Native authority / token ability | Coverage |
| --- | --- | --- | --- | --- |
| W01 | GET tech/dashboard | warroom.context, warroom.dashboard | warroom.view + source authority / warroom.read | Verified: tests + typed Swagger + HTTPS read-back |
| W02 | GET tech/my-day | warroom.context, warroom.my-day | warroom.view + source authority / warroom.read | Verified: tests + typed Swagger + HTTPS read-back |
| W03 | GET tech/my-day?focus=overdue | warroom.my-day, warroom.my-day.overdue | warroom.view + source authority / warroom.read | Verified: tests + typed Swagger + HTTPS read-back |
| W04 | GET tech/admin/settings/warroom | warroom.settings.show | warroom.manage_settings / warroom.settings.read or .manage | Verified: tests + typed Swagger + HTTPS read-back |
| W05 | PUT tech/admin/settings/warroom | warroom.settings.update, warroom.operations.show | warroom.manage_settings / warroom.settings.read or .manage | Verified: tests + typed Swagger + HTTPS read-back |
| W06 | Embedded cards, lanes and source links | warroom.context, warroom.my-day | warroom.view + source authority / warroom.read | Verified: tests + typed Swagger + HTTPS read-back |

## Exact Evidence

The adjacent JSON links every row to current runtime method/path/name, explicit
native permission/token ability, successful-response schema, named tests and
independent trusted HTTPS read-back. warroom-api-contract.json records the full
inventory (all evidence files are prefixed 2026-10-27-).

My Day preserves own open lifecycle, complete totals, 8/8/12 previews, unread and
combined overdue filters/pagination. Source destination links keep native ownership.
Workday/Absence discovery follows enabled runtime and own-work permissions.
Settings covers all seven fields and nine panel keys, browser/API revision conflicts,
atomic encrypted receipts/replay, authority revocation and exact native read-back.

Dashboard source counts are permission-filtered. Calendar uses native masking;
ordinary mailbox owner/shared/delegated scope excludes emergency-only access, which
remains in Mail with its audit/use guard. Source/schema failures are unavailable,
not healthy zeros; configured hidden and inaccessible states remain distinct.

Native migration, singleton contention/rollback, all seven HTTPS results and actual
BookStack publication/read-back are recorded in the verification report. Human
checklist HR-2026-10-27-WARROOM-API is Reviewed by Svein Tore ("Godkjenner", 2026-10-27). Main/production actions remain separately unauthorized.
