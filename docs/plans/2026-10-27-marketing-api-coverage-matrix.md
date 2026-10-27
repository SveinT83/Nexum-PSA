# Marketing Browser/API Coverage Matrix

Status: Done On Dev / Main candidate under final verification / human scenarios Pending.
Date: 2026-10-27. Runtime: `/var/Projects/tdPSA`, branch `Dev`.
28 staff browser route rows + 3 public capability rows; 24 API route rows / 29 HTTP
operations excluding HEAD. Baseline Swagger had zero Marketing paths. Current coverage: 42 protected HTTP
operations, 62 typed schemas and the three preserved public tracking capabilities.
Every existing API operation remains in scope, including both PUT and PATCH aliases.
An existing route does not establish complete workflow, authorization or typed-contract parity.

| ID | Class | Browser method/path | Current or planned API | Baseline gap |
| --- | --- | --- | --- | --- |
| M01 | action | `GET /marketing/c/{token}/{url}` | `GET /marketing/c/{token}/{url}` | Existing public token capability; preserve URLs/behavior and document/test separately. No bearer replacement. |
| M02 | action | `GET /marketing/o/{token}` | `GET /marketing/o/{token}` | Existing public token capability; preserve URLs/behavior and document/test separately. No bearer replacement. |
| M03 | action | `GET /marketing/unsubscribe/{token}` | `GET /marketing/unsubscribe/{token}` | Existing public token capability; preserve URLs/behavior and document/test separately. No bearer replacement. |
| M04 | read | `GET /tech/admin/settings/marketing` | `GET /api/v1/marketing/settings` | Partial: settings/options exist; safe current Marketing sender summary absent. |
| M05 | write | `PUT /tech/admin/settings/marketing` | `PUT / PATCH /api/v1/marketing/settings` | Existing write; native admin/settings authority and complete contract still to verify. |
| M06 | read | `GET /tech/marketing` | `GET /api/v1/marketing/dashboard` | Missing dashboard summaries, sender status, due recipients and recent events. |
| M07 | read | `GET /tech/marketing/campaigns` | `GET /api/v1/marketing/campaigns` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M08 | write | `POST /tech/marketing/campaigns` | `POST /api/v1/marketing/campaigns` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M09 | read | `GET /tech/marketing/campaigns/create` | `GET /api/v1/marketing/context/campaigns` | Missing lists, safe active sender accounts, defaults and schedule choices. |
| M10 | read | `GET /tech/marketing/campaigns/{campaign}` | `GET /api/v1/marketing/campaigns/{campaign}` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. Missing content-source/interest summary, template/preview context, safe AI availability and independently paginated queue. |
| M11 | action | `POST /tech/marketing/campaigns/{campaign}/ai-plan` | `POST /api/v1/marketing/campaigns/{campaign}/ai-plan` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M12 | action | `POST /tech/marketing/campaigns/{campaign}/approve` | `POST /api/v1/marketing/campaigns/{campaign}/approve` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M13 | action | `POST /tech/marketing/campaigns/{campaign}/content-sources/wordpress` | `POST /api/v1/marketing/campaigns/{campaign}/content-sources/wordpress` | Missing WordPress pull and exact persisted content-source read-back. |
| M14 | write | `POST /tech/marketing/campaigns/{campaign}/emails` | `POST /api/v1/marketing/campaigns/{campaign}/emails` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M15 | action | `POST /tech/marketing/campaigns/{campaign}/emails/ai-draft` | `POST /api/v1/marketing/campaigns/{campaign}/emails/ai-draft` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M16 | action | `POST /tech/marketing/campaigns/{campaign}/emails/preview` | `POST /api/v1/marketing/campaigns/{campaign}/emails/preview` | Missing authoritative unsaved/snapshot renderer; must not save, send or track. |
| M17 | write | `PUT /tech/marketing/campaigns/{campaign}/emails/{email}` | `PUT / PATCH /api/v1/marketing/campaigns/{campaign}/emails/{email}` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M18 | write | `DELETE /tech/marketing/campaigns/{campaign}/emails/{email}` | `DELETE /api/v1/marketing/campaigns/{campaign}/emails/{email}` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M19 | write | `POST /tech/marketing/campaigns/{campaign}/emails/{email}/test-send` | `POST /api/v1/marketing/campaigns/{campaign}/emails/{email}/test-send` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M20 | write | `PUT /tech/marketing/campaigns/{campaign}/schedule` | `PUT / PATCH /api/v1/marketing/campaigns/{campaign}/schedule` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M21 | action | `POST /tech/marketing/campaigns/{campaign}/send-due` | `POST /api/v1/marketing/campaigns/{campaign}/send-due` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M22 | read | `GET /tech/marketing/lists` | `GET /api/v1/marketing/lists` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M23 | write | `POST /tech/marketing/lists` | `POST /api/v1/marketing/lists` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M24 | read | `GET /tech/marketing/lists/create` | `GET /api/v1/marketing/context/lists` | Missing consent categories, tags, industry categories, eligible Contact and legacy choices. |
| M25 | read | `GET /tech/marketing/lists/{list}` | `GET /api/v1/marketing/lists/{list}` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M26 | write | `PUT /tech/marketing/lists/{list}` | `PUT / PATCH /api/v1/marketing/lists/{list}` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M27 | write | `DELETE /tech/marketing/lists/{list}` | `DELETE /api/v1/marketing/lists/{list}` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M28 | action | `POST /tech/marketing/lists/{list}/contacts` | `POST /api/v1/marketing/lists/{list}/contacts` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M29 | action | `DELETE /tech/marketing/lists/{list}/contacts/{contact}` | `DELETE /api/v1/marketing/lists/{list}/contacts/{contact}` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |
| M30 | read | `GET /tech/marketing/lists/{list}/edit` | `GET /api/v1/marketing/lists/{list}/context` | Missing current criteria, addable contacts, selected labels, usage/deletion guards and choices. |
| M31 | action | `POST /tech/marketing/lists/{list}/refresh` | `POST /api/v1/marketing/lists/{list}/refresh` | Existing route; validate native rights, full payload, replay, errors and typed OpenAPI. |

## Embedded And API-Only Workflows

- **ME01 Dashboard and campaigns index metrics**: `GET /api/v1/marketing/dashboard; GET /api/v1/marketing/campaigns`. Dashboard missing; index totals/settings/context must match web.
- **ME02 List forms, selected criteria and addable Contacts**: `GET /api/v1/marketing/context/lists; GET /api/v1/marketing/lists/{list}/context`. Bounded searchable choices with same eligibility/exclusion and stable Contact/legacy identities.
- **ME03 Campaign form/editor, templates and unsaved preview variables**: `GET /api/v1/marketing/context/campaigns; GET /api/v1/marketing/campaigns/{campaign}/context; POST emails/preview`. Safe active sender/template fields, schedule catalogs, current capabilities and exact-parent snapshots.
- **ME04 Campaign recipient queue and delivery progress**: `GET /api/v1/marketing/campaigns/{campaign}/recipients`. Existing include_recipients is unbounded; add scoped pagination/filtering and exact delivery read-back.
- **ME05 Cached WordPress sources, events and interest summary**: `GET /api/v1/marketing/campaigns/{campaign}/content-sources; GET /api/v1/marketing/campaigns/{campaign}/events; GET /api/v1/marketing/campaigns/{campaign}`. Content/interest absent from campaign API; restrict metadata and tracking capabilities.
- **ME06 Existing API-only operations**: `PUT|PATCH campaigns/{campaign}; GET lists/{list}/members; PUT/PATCH aliases`. Retain and verify every one of the 29 existing HTTP operations, not just browser matches.
- **ME07 Command/provider preview and own-operation read-back**: `POST /api/v1/marketing/campaigns/{campaign}/actions/preview; GET /api/v1/marketing/operations/{operation}`. Planned durable actor/token-bound commands; cannot infer completion from queue or SMTP response.

## Evidence And Completion Rules

The JSON contains complete route middleware/actions and SHA-256 source fingerprints.
Marketing's campaign view has an existing one-line contributor fix; preserve it byte-for-byte
unless an approved overlapping edit is necessary. Other source files were clean in Marketing.
The shared Dev checkout contains unrelated active work, including Knowledge API implementation;
this plan neither switches that contributor's task nor authorizes shared-file overwrites.
GitHub's open Marketing issue search returned no matches; no Issue or comment was created.

After approval replace Planned status only with evidence linking each operation to current
module route, ability/native permission, shared action/query, typed request/success/error
schema, relevant positive/negative test and exact read-back. Record actual operation totals
after implementation, not a projected 100% claim. Public tracking/unsubscribe are capabilities
with their existing URLs and response media types, not authenticated admin CRUD endpoints.

## Verified Implementation (2026-10-27)

All 31 browser rows and seven embedded/API-only groups are implemented. The JSON retains
initial gaps as historical baseline and records each current route/ability/native mapping,
operation ID, test files and exact success-method evidence. 42/42 protected HTTP methods
have successful synthetic payloads validated against the fixed published schema snapshot.
Public pixel/click tests and positive unsubscribe/suppression regression preserve capability
semantics. The standalone Marketing tests pass 88 / 2018 on Dev. Native MySQL mutex,
uniqueness, encrypted read-back and rollback pass; exact Dev migration is installed.
Seven trusted HTTPS reads, anonymous 401 and 42-operation Swagger are independently verified.
Three Knowledge sources are exact-read-back internal published articles; full guide is
published/read back in BookStack 449 with existing employee permissions unchanged.
Standalone page creation is blocked by connector validation; existing-page publication
is verified and is not a handbook-content blocker. OpenWebUI retrieval is untested.
Main candidate broad tests: 488 / 7469 passed; final Marketing verification also passes 88 / 2018. Main/production and human scenario gates are separate.
