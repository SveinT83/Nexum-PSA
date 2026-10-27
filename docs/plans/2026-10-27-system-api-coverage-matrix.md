# System Browser/API Coverage Matrix

Date: 2026-10-27
Status: Done On Dev / Human Review Pending. RFC approved by Svein Tore, 2026-10-27.
Runtime: authoritative /var/Projects/tdPSA, Dev; HEAD 36918edbcaae1ce7b1208ba34f84a3a1719f0e98

Original baseline: 13 browser rows (5 read, 2 write, 6 action), zero System API operations.
Completed runtime: all 13 browser rows covered by 19 typed operations and 44 schemas.
HEAD is excluded. Every operation has positive typed HTTP/schema and read-back evidence.

## Browser mapping

| Browser route | Class | Proposed operations |
| --- | --- | --- |
| GET|HEAD /tech/admin | read | SY01 |
| GET|HEAD /tech/admin/system/branding | read | SY05, SY08, SY09 |
| PUT /tech/admin/system/branding | write | SY06, SY07 |
| POST /tech/admin/system/branding/preset | action | SY10 |
| PUT /tech/admin/system/branding/reset | action | SY11 |
| GET|HEAD /tech/admin/system/company-profile | read | SY03 |
| PUT /tech/admin/system/company-profile | write | SY04 |
| GET|HEAD /tech/admin/system/queues-workers | read | SY12 |
| POST /tech/admin/system/queues-workers/clear | action | SY13, SY15 |
| POST /tech/admin/system/queues-workers/failed/flush | action | SY13, SY17 |
| POST /tech/admin/system/queues-workers/failed/retry | action | SY13, SY16 |
| POST /tech/admin/system/queues-workers/restart | action | SY13, SY14 |
| GET|HEAD /tech/admin/system/version-status | read | SY02 |

## Verified complete contract

Paths are relative to /api/v1. Nineteen HTTP operations: eight reads, three writes,
eight actions. Each requires fresh native authority and independent token scope.
Settings-only and queue-only actors must not require unrelated system.view.

| ID | Method/path | Scope | Native permission | Result |
| --- | --- | --- | --- | --- |
| SY01 | GET /system/context | system.read | system.view | Authorized admin discovery and local build metadata |
| SY02 | GET /system/version-status | system.read | system.view | Cached GitHub release and branch comparison |
| SY03 | GET /system/company-profile | system.settings.read | system.settings_manage | Organization data, constraints and state version |
| SY04 | PUT /system/company-profile | system.settings.write | system.settings_manage | Validated company update and exact read-back |
| SY05 | GET /system/branding | system.settings.read | system.settings_manage | Colors, theme, safe logo metadata and state version |
| SY06 | PUT /system/branding | system.settings.write | system.settings_manage | JSON visual settings and explicit removals |
| SY07 | POST /system/branding | system.settings.write | system.settings_manage | Multipart visual settings and three logo uploads |
| SY08 | GET /system/branding/presets | system.settings.read | system.settings_manage | Preset keys and values |
| SY09 | POST /system/branding/preview | system.settings.read | system.settings_manage | Side-effect-free unsaved visual settings preview |
| SY10 | POST /system/branding/preset | system.settings.write | system.settings_manage | Apply preset, preserving company and logos |
| SY11 | PUT /system/branding/reset | system.settings.write | system.settings_manage | Reset all branding/logos, preserve company data |
| SY12 | GET /system/queues-workers | system.queue.read | system.queue_manage | Driver-aware status, bounded safe failed-job metadata and guidance |
| SY13 | POST /system/queues-workers/preview | system.queue.read | system.queue_manage | Explicit maintenance target, impact and expiring confirmation |
| SY14 | POST /system/queues-workers/restart | system.queue.restart | system.queue_manage | Graceful restart signal receipt, no inferred worker restart |
| SY15 | POST /system/queues-workers/clear | system.queue.clear | system.queue_manage | Confirmed named-queue clear and exact outcome receipt |
| SY16 | POST /system/queues-workers/failed/retry | system.queue.retry | system.queue_manage | Confirmed failed job or explicit all, no implicit backlog release |
| SY17 | POST /system/queues-workers/failed/flush | system.queue.flush | system.queue_manage | Confirmed failed-store flush and outcome evidence |
| SY18 | GET /system/operations | system.operations.read | action-specific | Bounded own receipts with current source native/scope rights |
| SY19 | GET /system/operations/{operation} | system.operations.read | action-specific | Actor/token-bound result and reconciliation status |

## Embedded and related surfaces

- Branding local color/light/dark/logo preview maps to SY05/SY08/SY09 and existing public logo URLs. Uploaded image preview can remain client-local; no durable upload on preview.
- All three logo slots, replacement/removal/fallback: SY06/SY07; verify public bytes after writes.
- Shared branding feeds Booking, CustomerPortal, PWA metadata, Commercial PDF/delivery and Notification SMS; regress these consumers without changing their domain behavior.
- Admin hub destinations retain their own ownership/native authority, not a duplicate System management API.
- Process-manager and scheduler examples are guidance, not health evidence. #262 remains separate.
- PWA manifest/service worker/static asset URLs and private-response no-cache stay intact; personal theme/avatar belongs to UserManagement.
- No System Livewire class, public capability or hidden API was found. Session version-status JSON is a browser endpoint.

## Fields and evidence

Company fields (11): company_name, legal_name, organization_number, address_line_1, address_line_2, postal_code, city, country, support_email, phone, website.

Branding fields (46): primary_color, secondary_color, accent_color, default_theme, light_header_background, light_header_color, light_footer_background, light_footer_color, light_left_sidebar_background, light_left_sidebar_color, light_main_background, light_right_sidebar_background, light_right_sidebar_color, light_page_header_background, light_page_header_color, light_card_header_background, light_card_header_color, light_content_background, light_primary_button_background, light_primary_button_color, light_secondary_button_background, light_secondary_button_color, dark_header_background, dark_header_color, dark_footer_background, dark_footer_color, dark_left_sidebar_background, dark_left_sidebar_color, dark_main_background, dark_right_sidebar_background, dark_right_sidebar_color, dark_page_header_background, dark_page_header_color, dark_card_header_background, dark_card_header_color, dark_content_background, dark_primary_button_background, dark_primary_button_color, dark_secondary_button_background, dark_secondary_button_color, logo, logo_light, logo_dark, remove_logo, remove_light_logo, remove_dark_logo.

Native source: EnforceTechRoutePermission. Preserve permission existence and current
Superuser/legacy Admin fallback, active internal actor and current token authority.
Each operation requires runtime method/path, OpenAPI request/result/error schemas,
positive/negative tests, replay/concurrency where applicable and exact read-back.
The JSON matrix now includes exact runtime routes, OpenAPI operations/content types,
positive tests and successful response read-back for every operation. Final affected
regression: 121 passed / 1529 assertions, zero failures/skips. The two original SSO
baseline failures were fixed and all System tests are included. Native MySQL, trusted
HTTPS and actual BookStack content/access evidence are separate linked artifacts.


## Completion Evidence And Gates

- Full verification: 2026-10-27-system-api-verification.md/.json.
- Native MySQL: 2026-10-27-system-api-native-evidence.json.
- Trusted HTTPS and real multipart validation: 2026-10-27-system-api-https-evidence.json.
- Actual BookStack 366/367/438/546 exact read-back and employee access:
  2026-10-27-system-api-handbook-evidence.json. Complete API guide is a section in 366.
- Human-review checklist HR-2026-10-27-SYSTEM-API remains Pending for production
  deployment/release. Tested scoped Main promotion is explicitly authorized by Svein Tore,
  2026-10-27: "Godkjenner og merge"; no manual scenario is inferred from that authorization.
- API maintenance remains disabled by default. No live queued jobs were retried/deleted.
- Native Dev BookStack integration is absent. Its six Knowledge records stay pending_push;
  actual handbook publication through the supported connector is independently verified.
  Svein Tore/Integration owner should configure/reconcile mappings before future native sync.
