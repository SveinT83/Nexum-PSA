The System API covers current company, branding, admin discovery, version and queue workflows.

## Availability

Implemented on Dev under the 2026-10-27 System API RFC. This article does not claim Main or production deployment. Queue execution through the API is disabled by default. Existing browser controls keep their normal availability.

## Prerequisites

1. Have an active internal account with the native permission for the operation.
2. An authorized administrator opens **Admin > Integrations > API management** and creates a narrowly scoped key.
3. Use the installation's trusted HTTPS address followed by /api/v1/system. Send credentials through the Authorization: Bearer header; never put keys in URLs, ordinary chat, examples or logs.
4. Open /api/documentation for exact current System request, response and error schemas.

Scopes restrict existing user authority. Settings require system.settings_manage, queue operations require system.queue_manage, and context/version require system.view. Settings-only and queue-only actors do not need unrelated system.view. Every call and receipt read rechecks current authority. Disabled users and expired/revoked keys are denied.

## Discover And Read

- GET /context: authorized admin destinations and local build metadata. Destination modules retain their own permissions and API ownership.
- GET /version-status: cached GitHub release/branch comparison, not proof that updates are installed.
- GET /company-profile: all organization fields, accepted field names and version.
- GET /branding: visual fields, default theme, three logo slots, public URLs, byte counts/checksums and version.
- GET /branding/presets: supported preset keys, labels and color values.
- GET /queues-workers: timestamped driver-aware observations. Unknown counts remain unknown; queue tables and setup examples do not prove worker/scheduler health.

## Update Company Profile And Branding

1. Read the current resource and retain its exact ETag response header.
2. Prepare supported fields from Swagger. PUT /company-profile requires company_name; omitted optional fields retain values, explicit nullable values can be cleared.
3. For branding, send every required color and default_theme (light, dark or system).
4. Use PUT /branding for JSON visual settings/removal flags. Use POST /branding with real multipart/form-data for visual settings and fallback logo, logo_light and logo_dark uploads, each limited to 2048 KiB. Do not submit output-only logos, URLs, checksums, version or storage paths.
5. Send a new Idempotency-Key of 8 to 200 characters and If-Match equal to the exact ETag.
6. Read returned fields/version and operation_id, then GET the resource again. For images check resolved public URL and checksum/byte count. Missing bytes have a null checksum.
7. A 412 response means settings changed. Reload and review before a new command; never overwrite automatically.

POST /branding/preview accepts partial unsaved visual settings/removal flags, returns persisted=false and changes no settings/files. Newly selected image preview can remain client-local. POST /branding/preset takes a supported preset key and preserves organization data/logos. PUT /branding/reset restores visual defaults/removes all three logos while retaining organization data. Preset/reset also require both command headers.

Company and branding share one version. Browser changes invalidate API preconditions and both entry points preserve unrelated fields. New logos are staged before references change; obsolete files are cleaned only after commit. Storage failure must retain the prior usable logo.

## Queue Maintenance

Use these actions only in an explicitly approved operational context. Retrying jobs can send messages or perform business changes; clearing/flushing removes work.

1. Read GET /queues-workers and review backend/target. An authorized deployment operator must explicitly enable SYSTEM_API_MAINTENANCE_ENABLED before API execution. Installing the API does not activate it or alter the scheduler/workers.
2. Send POST /queues-workers/preview with action restart, clear, retry or flush.
3. Clear requires an explicit queue. Retry requires either one job_id or all=true; omission never selects all. Restart/flush have no caller-selected connection/queue.
4. Review target, affected count/evidence, warning and expiry. Preview does not mutate queues. Database and failed-store cohorts have snapshot hashes; other supported drivers expose explicitly labelled count-only evidence.
5. Using a fresh action-specific scope, send the same target and confirmation to POST /queues-workers/restart, /clear, /failed/retry or /failed/flush. Include a new Idempotency-Key. Confirmation normally expires after 120 seconds. Changed target/configuration/cohort or earlier maintenance invalidates it.
6. Read the receipt. Accepted restart means graceful signal issued, not observed process restart. Accepted retry means re-enqueued, not processed or delivered. Completed describes successful clear/flush command, not overall queue health.
7. Nonzero command result or ambiguous interruption stays unresolved and requires reconciliation. Read the retained receipt; do not submit another key to bypass it. The shared maintenance lane blocks further dispatch while an outcome is unresolved.

Scopes are system.queue.read for status/preview and system.queue.restart, system.queue.clear, system.queue.retry or system.queue.flush for the action. Native permission is always required. Preview and execution must use the same actor/key. Tests use fakes and isolated synthetic database queues. Real process behavior and installation-specific remote backends need separate operational review.

## Receipts And Troubleshooting

GET /operations and GET /operations/{operation} require system.operations.read plus original operation scope and current native permission. Receipts are bound to the original actor/key; another key for the same user cannot retrieve them. Pagination uses page and per_page (1 to 100).

Identical request/key replays the retained result without repeating effects. Changed input under the same key returns 409. Replay contains the original result/version even if later settings changed; GET current state before editing again.

- 401: authentication/key unavailable.
- 403: user permission or scope insufficient.
- 404: target or actor/key-bound receipt unavailable.
- 409: replay conflict, expired/changed confirmation, disabled execution or unresolved operation.
- 412: stale settings ETag.
- 422: unsupported field, invalid value/image or confirmation.
- 428: required command header missing.
- 503: exact additive System migration, storage or backend unavailable.

Raw job payloads, exceptions, console output, filesystem paths and credentials are excluded from ordinary API results. For unknown worker/scheduler health use approved operational checks. This API does not start processes, change cron or prove delivery.
