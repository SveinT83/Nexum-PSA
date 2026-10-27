Booking API support is implemented on Dev. Main promotion and production deployment
are separate states; this guide does not claim production availability. Public API
submissions and staff API writes are default-off until an operator activates them.

## Prerequisites

Use a trusted HTTPS installation URL. Staff needs an active internal account, its
native Booking permissions and a token with the exact Booking abilities. An API key
does not grant user permissions. Do not paste tokens into tickets, chat or URLs.
Admins manage native Booking from Admin -> Booking; API documentation is available
at /api/documentation. A published contract alone does not enable writing.

## Public Booking Clients

1. GET /api/v1/booking/public/services for active bookable services.
2. GET /api/v1/booking/public/services/{slug} for public timezone, privacy requirement,
   the configured honeypot field and eligible customer-choice technicians.
3. GET /api/v1/booking/public/services/{slug}/slots?date=YYYY-MM-DD. Customer-choice
   services also need technician_id. No selection returns an empty slot list.
4. POST /api/v1/booking/public/services/{slug}/requests with contact_name,
   contact_email, slot_starts_at, timezone and privacy_acknowledged=true. Optional
   company_name/contact_phone/message and customer-choice technician_id are supported.
   Keep the named honeypot field empty. Send Idempotency-Key with a random UUID
   (at least 32 characters) and retain it for retries of exactly the same input.
5. HTTP 202 returns only a reference and generic acknowledgement. A pending request
   reserves no Calendar capacity; staff confirmation is required. There is no public
   customer history/status lookup. Automatic assignment never exposes technician IDs.

Public reads share a 60/minute IP limit and web/API submissions share 10/minute.
Date ranges and slots remain bounded by service duration, step, notice, horizon,
opening window, company/profile work hours and Calendar conflicts.

## Staff API Workflow

1. Use booking.read plus the native permission for the requested capability.
   GET /api/v1/booking/context?capability=overview uses booking.view;
   capability=configuration uses booking.manage and returns defaults/options;
   capability=review uses booking.request_review. Native manage/review does not
   require an additional booking.view permission. Optional setting_slug preserves
   the edited service in configuration choices.
2. GET summary, settings, requests, settings/{slug}, requests/{id} and
   requests/{id}/events under /api/v1/booking. Lists accept page/per_page (1..100).
   Native view protects overview/lists/request/timeline; native manage protects
   individual setting/edit context.
3. Use booking.settings.write + booking.manage to POST settings, PUT settings/{slug}
   or POST settings/{slug}/toggle. Send the full supported setting form. Existing
   staff-confirmed mode and allow-new-clients behavior remain fixed. Settings include
   service, slug/public copy, technician routing/pool, work-hours source, duration,
   interval, notice, horizon, opening window, location, instructions and honeypot.
4. Use booking.requests.review + booking.request_review to POST requests/{id}/confirm
   or requests/{id}/decline. Decline accepts an optional decline_reason. Confirmation
   rechecks Calendar and may reassign automatic requests; fixed/customer-choice keeps
   the selected technician. Busy time blocks confirmation without creating an event.
5. All staff mutations require Idempotency-Key (16..200 allowed characters; use a
   random UUID). Update/toggle/confirm/decline also require expected_revision copied
   from the current resource. Repeated identical calls return the original encrypted
   local result snapshot; different input with the same key returns HTTP 409.
6. Successful staff writes return command_id. GET commands/{command_id} requires
   booking.commands.read, the original operation ability/native permission and the
   original actor/token. It exposes an allowlisted snapshot, not another user's data.
7. Re-read the exact setting/request after a command for current state and notification
   evidence. The command snapshot may show pending notification submission because the
   transport runs only after local commit. submitted/queued means accepted by the
   existing notification pipeline; it does not prove inbox receipt. unknown means
   reconcile transport logs/receipt before retrying; automatic resend is suppressed.

## Error Handling And Safety

401 means authenticate; 403 means current user/token rights are insufficient; 404
means unavailable service or inaccessible identity; 409 means stale/conflicting
input, so reload/review before issuing a new command. 422 reports invalid fields,
missing key/revision or a newly busy slot. 429 means wait for the shared limiter.
503 means API mutation execution is disabled or the reviewed schema/database is
unavailable. Do not bypass TLS, grant broad permissions or repeatedly send unknown
commands. Unsupported fields are rejected; raw payload, IP/user agent, arbitrary
metadata and provider exceptions are excluded from responses.

## Operator Deployment

Apply only database/migrations/2026_10_27_040000_create_booking_api_commands.php,
regenerate Swagger and clear ordinary caches. Confirm native database locking and
synthetic workflow tests. BOOKING_API_PUBLIC_SUBMISSIONS_ENABLED and
BOOKING_API_STAFF_WRITES_ENABLED default to false; enabling them is a separate
operational decision after the human-review checklist HR-2026-10-27-BOOKING-API.
No general scheduler/worker activation, role grant or customer test send is required.
Existing web Booking continues to use its supported workflow. Preserve encryption
keys, command/dispatch history and audit on rollback. API support adds no cancellation,
rescheduling, payment, portal history or direct reservation product workflow.
