# Feature Slices: Full CustomField API Parity

Status: Done On Dev
Date: 2026-10-27
Parent: ../rfc/2026-10-27-custom-field-full-api-parity.md
Owner: Codex. Approver/reviewer: Svein Tore.

RFC approved 2026-10-27; Knowledge implementation complete/merged. Implement sequentially.
No parallel implementation or new contributor work is authorized by this document.

| Slice | Goal and user-visible scope | Data and permissions | Tests and done criteria |
| --- | --- | --- | --- |
| CF1 | Shared definition authority/validation, complete filtered list/detail and context | Existing definitions; native management or owning-domain visibility plus custom-fields.read; shared browser actions | Equivalent browser settings, invalid aliases/keys/options denied, filtered pagination/counts, all 11 types; no wider visibility |
| CF2 | Create, partial update/activation, native soft delete and own-operation read-back | Definitions plus additive encrypted receipts; create/update/delete/operations token abilities and fresh customfield.manage_settings | Atomic persistence, retained values, native migration/locking, stale/idempotent/concurrent requests, revoked rights, exact read-back |
| CF3 | Complete Client/Site/Ticket value coverage through existing owners | Existing values, aliases and Ticket events; native object/field rights and unchanged API/UI gates | All supported types, clear/required/unique/search, denied inputs, no partial writes, work-context/portal isolation and pinned-rule tests |
| CF4 | Typed Swagger, handbook and final verification | Scope catalog, Swagger, Knowledge sources and verified BookStack pages | Exact route/schema/matrix equality, affected Dev suite, trusted HTTPS, actual handbook/access read-back and Pending human checklist |

Out of scope: new target domains, separate generic value API, production activation,
Main promotion, unrelated contributor changes and unapproved default-value behavior.
Each slice updates the parent RFC, matrix, TODO and affected Knowledge/module docs.
CF4 records exact deploy commands, risks, evidence and remaining human checks.

All CF1-CF4 slices are implemented and verified on Dev. Final Dev 676 / 46696 passes,
including shared owner workflows and internal source publication; actual BookStack
451/449 source/access read-back is verified. Seven HTTP operations / 19 schemas, native
DB and trusted HTTPS pass. Tested Main promotion is authorized; final rebased interoperability
checks pass 236 / 9264 with a dedicated view cache. HR-2026-10-27-CUSTOM-FIELD-API remains Pending; production unchanged.

Main promotion completed in PR #344, independently verified at
f91acc9045794ac4ede80a336f80974c17ab9bc4. The merged tree equals the tested candidate.
No Main implementation action remains. Human review remains Pending; production unchanged.
