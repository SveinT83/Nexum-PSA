# Clients API Gap Matrix

Status: Implemented and verified on Dev / Human Reviewed / Merged to Main in PR #319
Date: 2026-10-25
Owner: Codex
Parent: ../rfc/2026-10-25-clients-full-api-parity.md
Evidence: 2026-10-25-clients-api-route-baseline.json

## Definition and baseline

100% means every supported current browser/Livewire workflow has a typed official
API with equivalent actor, record, parent and field authority; supported writes
and actions; discovery; relevant side effects; exact result read-back; tests and
published documentation. Existing owner-domain APIs count only after equivalent
semantics and access have been verified. Route counts alone are not a percentage.

Authoritative runtime inventory: 37 Clients browser rows, nine Clients API rows /
11 methods excluding HEAD, plus one Notes Livewire component. Eight legacy Contact
aliases are included in the 37; they do not create a second person domain.

Source references are relative to repository root. The table records the audited
baseline gap and approved target. All C01-C20 are now implemented or mapped to
verified owner APIs. Exact current operation IDs, schemas and tests are recorded in
2026-10-25-clients-api-contract.json and 2026-10-25-clients-api-verification.json.

| ID | Current browser surface / source | Existing official API and gap | Required complete mapping |
| --- | --- | --- | --- |
| C01 | Client index and index alias; Tech/ClientController::index | GET clients; only q/active/custom fields, incomplete browser filter/sort context | GET clients + clients/context: format/status/contract/won/RMM filters, stable sort/pages, authorized totals/options, explicit parameters without session writes |
| C02 | Summary/workspace; Tech/ClientController::show | GET clients/{client}; resource lacks notes/website/format and other editable fields | Complete typed Client resource/read-back; GET clients/{client}/workspace with authorized counts, capabilities and owner-domain links |
| C03 | New Client form; Tech/ClientController::create | No equivalent form/number/provider lookup context | GET clients/context and authorized bounded lookup(s): formats, defaults, constraints, local/provider number state, Contact/Site inputs and RMM capability; number suggestion is unreserved |
| C04 | Client store/default Site/primary Contact; CreateClientWithDefaults | POST clients creates Client/Site but no browser-equivalent Contact/RMM command | Backward-compatible POST clients plus optional canonical primary_contact and explicit RMM action; shared local transaction, stable bridge, identity/read-back and durable provider intent |
| C05 | Client settings GET/PUT and RMM mapping | PUT/PATCH clients supports core subset, no RMM mapping or settings context | GET/PATCH clients/{client}/settings and authorized RMM lookup/link/unlink/create behavior; preserve client.manage_settings and Integration boundaries |
| C06 | Livewire ClientNotesAutosave::saveNotes | PATCH clients can write notes but GET omits them; actor equivalence unproven | Notes-only update through shared action, notes read-back/revision, current client.update plus scope, omission preservation and honest saved status |
| C07 | Site list/create/edit/show; ClientSiteController | GET client-sites and clients/{client}/sites; no detail/form context | GET client-sites/{site}, context and Client/Site list parity; explicit parent and authorized related links without session mutation |
| C08 | Site store/update; ClientSiteController | POST clients/{client}/sites; PUT/PATCH client-sites/{site} | Shared validation/write/default semantics; full typed result, no unintended provider address reassignment or lost fields |
| C09 | Site destroy; ClientSiteController::destroy | No DELETE Site API | DELETE client-sites/{site}, current delete right and parent/reference constraints, revision/replay and actual deletion read-back; no cascade expansion |
| C10 | Client/Site custom-field modal update | Client/Site writes accept custom_fields; per-field parity/read-back unproven | Typed definition/value/options and authorized shared writes, exact changed field read-back, existing CustomField definition owner and visibility/edit policy |
| C11 | Client Contacts/Site Contacts and eight legacy Client User aliases | Contact-owned GET clients/{client}/contacts, Contact CRUD/move/bulk-fix/detach exist | Reuse canonical Contact APIs with verified Client/Site scope and current Contact rights; map aliases explicitly, preserve stable client_users; legacy DELETE must not turn into destructive delete |
| C12 | Assets, Tickets, Tasks, Contracts, Signals and Risk workspace/rightbar | Client Assets API and owner APIs exist; composite workspace parity unproven | Owner-authorized typed lists/counts/links and filters; reuse each owner query/API, never infer other-domain access from clients.read or expose restricted snippets |
| C13 | Client Task template chooser/apply link | GET task-template-targets, template preview/apply/run APIs exist | Reuse Task target/preview/apply contract with owner_type=client, exact Client target, current Task/Client rights and replay-safe effects |
| C14 | Timebank balances and quick-consume modal | Commercial-owned GET commercial/clients/{client}/timebank; POST .../consumptions and exact GET exist | Verify equivalent balances, constraints/rates/notes, owner scopes, overconsume rules and exact persisted consumption; retain Commercial ownership |
| C15 | Unified Time tab and PATCH clients/{client}/time-usage/{source}/{entry} | No Clients time list/detail/correction API in runtime inventory | GET clients/{client}/time-usage[/source/entry] and PATCH source entry; owner-or-higher right, ordered/coupled denial, rate/allocation/revision/replay plus Economy locking |
| C16 | Staff Licences GET, issue, quantity, renewal, status | No equivalent staff licence API; webhook is provider ingress only | GET clients/{client}/licenses + context and operation detail; POST issue; PATCH subscription quantity/renewal/status; Integration ledger and write policy, truthful submitted/confirmed/unknown read-back |
| C17 | Portal Licences GET, issue, quantity, renewal | No equivalent portal licence API | GET portal/licenses + context; POST issue; PATCH subscription quantity/renewal; active client-wide Customer admin context and legal acceptance, current contract/offer/terms and durable replay |
| C18 | Admin Client Format GET/POST/PATCH | No Client Format admin API | GET/POST client-settings/formats and GET/PATCH client-settings/formats/{format}; current admin + client.manage_settings, no unsupported delete, typed options/counts/revisions |
| C19 | Provider-backed number/profile behavior inside create/settings/Sites | Existing Tripletex-backed POST support; no complete Client API discovery/reconciliation path | Preserve approved Integration/DataExchange authority and bound-Site profile reconciliation; one UUID intent, explicit lookup/recovery links, no blind retry/fallback or new activation |
| C20 | Cross-cutting API management, auth, Swagger, read-back and docs | Existing abilities/annotations are partial; several responses only described generically | Full typed contract for all methods/errors, active actor+token+object+field policy, exact operation catalogue, positive/negative/concurrent tests, Knowledge and actual BookStack verification |

## Complete browser route accountability

The baseline JSON lists every registered Clients route with its method, URI, name,
action and middleware. Mapping groups:

- Client index/index alias -> C01.
- Client create/store/show -> C02-C04, C12-C14.
- Client settings/custom fields -> C05/C10; Livewire Notes -> C06.
- Site list/create/store/edit/show/update/destroy/custom fields -> C07-C10.
- Client User users/user_management/show/create/store/edit/update/delete -> C11.
- Client time-usage update -> C15.
- Staff licence index/issue/quantity/renewal/status -> C16.
- Portal licence index/issue/quantity/renewal -> C17.
- Admin format index/store/update -> C18.
- Embedded provider authority -> C19; every row -> C20.

## Scope safeguards

Client archive/delete/retention remains in its Draft RFC; Site deletion already
exists and is included. Canonical Contact is authoritative; Issue #254 invitations
and portal role choice remain separate. Do not add unsupported provider products,
portal staff actions, import jobs, permission widening or production activation.
Preserve legacy namespace and existing dirty Tripletex/allocation work.
Provider failures and unknown outcomes are separate from local persisted success.

## Verification state

All C01-C20 are implemented and verified on authoritative Dev under the approved RFC.
The final operation catalogue contains 51 Clients-owned HTTP operations / 74 typed
schemas. Seventeen reused Contact/Task/Commercial method/path pairs were verified
against runtime routes; their existing owner contracts are not duplicated.
Every row's exact operation IDs and test methods/files are in
[the verification JSON](2026-10-25-clients-api-verification.json).
The [handoff report](2026-10-25-clients-api-verification.md) records test results,
the two corrected broad-run failures, native locks/uniqueness, trusted HTTPS,
actual BookStack read-back and deployment/recovery instructions.

HR-2026-10-25-CLIENTS-API is Reviewed by Svein Tore. Main promotion is authorized;
production migration/deployment and activation remain separate. Dev implementation and design approval do not prove
human acceptance, production availability, real provider writes or external MCP publication.

Promotion authorization: Svein Tore explicitly accepted the completed delivery on
2026-10-25: "Godkjent. Merge til main". HR-2026-10-25-CLIENTS-API is Reviewed.
The review/checklist statements above describe the preceding Dev handoff; Main
promotion evidence is recorded in 2026-10-25-clients-api-main-promotion.md.
Production deployment and provider activation are not authorized by this approval.

Main implementation merge verified: [PR #319](https://github.com/SveinT83/Nexum-PSA/pull/319),
commit 154ca96b47867b5dfe7a21438d626000935fb24b. The complete merge tree equals the independently tested
candidate. HR-2026-10-25-CLIENTS-API is Reviewed. Production remains undeployed.
Current promotion evidence: 2026-10-25-clients-api-main-promotion.md.
