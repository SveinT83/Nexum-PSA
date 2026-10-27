# Booking Browser/API Coverage Matrix

Status: Done On Dev; isolated Main candidate verified; merge authorized; production unchanged. Date: 2026-10-27.
Authoritative runtime: /var/Projects/tdPSA, branch Dev.
13 browser rows: 7 read, 3 write, 3 action. Baseline Booking API operations: 0; implemented: 17.
All 17 HTTP operations are implemented with 43 typed schemas and linked verification.
HEAD is excluded from operation counts. All API paths below start /api/v1/.

## Runtime Browser Mapping

| Row | Method/path | Native route | Kind | Proposed API | Status |
| --- | --- | --- | --- | --- | --- |
| B01 | GET /booking | booking.index | read | P01 | Verified |
| B02 | GET /booking/{setting} | booking.services.show | read | P02, P03 | Verified |
| B03 | POST /booking/{setting} | booking.services.store | write | P04 | Verified |
| B04 | GET /booking/{setting}/thanks | booking.services.thanks | read | P04 | Verified |
| B05 | GET /tech/admin/system/booking | tech.admin.system.booking.index | read | A02, A03, A08 | Verified |
| B06 | GET /tech/admin/system/booking/requests/{bookingRequest} | tech.admin.system.booking.requests.show | read | A09, A10 | Verified |
| B07 | POST /tech/admin/system/booking/requests/{bookingRequest}/confirm | tech.admin.system.booking.requests.confirm | action | A11 | Verified |
| B08 | POST /tech/admin/system/booking/requests/{bookingRequest}/decline | tech.admin.system.booking.requests.decline | action | A12 | Verified |
| B09 | POST /tech/admin/system/booking/settings | tech.admin.system.booking.settings.store | write | A05 | Verified |
| B10 | GET /tech/admin/system/booking/settings/create | tech.admin.system.booking.settings.create | read | A01 | Verified |
| B11 | PUT /tech/admin/system/booking/settings/{setting} | tech.admin.system.booking.settings.update | write | A06 | Verified |
| B12 | GET /tech/admin/system/booking/settings/{setting}/edit | tech.admin.system.booking.settings.edit | read | A04, A01 | Verified |
| B13 | POST /tech/admin/system/booking/settings/{setting}/toggle | tech.admin.system.booking.settings.toggle | action | A07 | Verified |

Thanks has no private request lookup. P04 supplies equivalent safe acknowledgement;
no public history/status API is invented. Create/edit pages map to defaults/options.

## Proposed Operations

| ID | Method/path | Authority | Behavior |
| --- | --- | --- | --- |
| P01 | GET /api/v1/booking/public/services | public | Safe bookable catalogue |
| P02 | GET /api/v1/booking/public/services/{slug} | public | Service form context, public timezone, privacy/honeypot and permitted choices |
| P03 | GET /api/v1/booking/public/services/{slug}/slots | public | Bounded dates/technician selection and Calendar availability |
| P04 | POST /api/v1/booking/public/services/{slug}/requests | public | Shared validated submission/spam and minimal acknowledgement |
| A01 | GET /api/v1/booking/context | booking.read + capability-specific native permission | Defaults, constraints and native service/user options |
| A02 | GET /api/v1/booking/summary | booking.read + booking.view | Current open-request and overview counts |
| A03 | GET /api/v1/booking/settings | booking.read + booking.view | Bounded settings and request counts |
| A04 | GET /api/v1/booking/settings/{setting} | booking.read + booking.manage | Exact setting/edit context |
| A05 | POST /api/v1/booking/settings | booking.settings.write + booking.manage | Create all supported fields and eligible pool |
| A06 | PUT /api/v1/booking/settings/{setting} | booking.settings.write + booking.manage | Update setting/pool with current-state precondition |
| A07 | POST /api/v1/booking/settings/{setting}/toggle | booking.settings.write + booking.manage | Native toggle with current-state precondition |
| A08 | GET /api/v1/booking/requests | booking.read + booking.view | Bounded request list |
| A09 | GET /api/v1/booking/requests/{bookingRequest} | booking.read + booking.view | Safe request/customer/decision/Calendar link |
| A10 | GET /api/v1/booking/requests/{bookingRequest}/events | booking.read + booking.view | Bounded ordered timeline and truthful notification evidence |
| A11 | POST /api/v1/booking/requests/{bookingRequest}/confirm | booking.requests.review + booking.request_review | Atomic recheck/confirm/Calendar event and automatic fallback |
| A12 | POST /api/v1/booking/requests/{bookingRequest}/decline | booking.requests.review + booking.request_review | Atomic decline/reason and customer notification |
| A13 | GET /api/v1/booking/commands/{command} | booking.commands.read + original operation authority | Own actor/token-bound sanitized result with fresh rights |

A01 requires booking.read and the native permission for the requested context:
booking.view for overview, booking.manage for configuration, booking.request_review
for decision context. Do not add extra native permissions absent from current web.

## Embedded Workflow Evidence Required

| Workflow | Authoritative source | Required evidence |
| --- | --- | --- |
| All setting fields/defaults/constraints | Admin BookingServiceSettingController and settings/_form | Strict inputs, forced staff_confirmed/allow_new_clients, slug/pool/toggle parity |
| Date/technician choice and availability | Public BookingController and FindBookingSlots | Public-safe identities, work hours/window/DST/step/notice/horizon and Calendar conflicts |
| Spam/privacy/submit/thanks | StoreBookingRequest and public views | Generic minimal acknowledgement, no raw data/history leak, no spam mail/reservation |
| Staff detail/timeline and source link | Admin BookingRequestController and requests/show | Native field visibility, safe events/Calendar link, no expanded Calendar access |
| Confirm/decline/reroute/notify | ConfirmBookingRequest, DeclineBookingRequest, NotifyBookingCustomer | Native concurrent terminal/slot/dispatch proof and truthful queue outcomes |
| Retry/replay/stale access | Proposed Booking command boundary | Key identity, encrypted safe results, conflicting/stale/revoked replay and lookup |

## Verification State

All 13 browser rows map to 17 implemented HTTP operations. The JSON inventory links
exact runtime names/controllers/middleware, token scopes and Swagger operation IDs.
BookingApiTest exercises every operation with successful schema-validated output,
real bearer/native denials, retry/replay, revocation, privacy and conflict checks.
BookingModuleTest verifies native hours, routing, Calendar conflict and automatic
fallback semantics. BookingApiContractTest verifies all routes/abilities/43 schemas.
See 2026-10-27-booking-api-verification.md/.json for test/native/HTTPS/handbook proof.
Ordinary Dev API write gates stay off; Main merge is authorized. Production,
operational activation and named manual scenario review remain separate.
