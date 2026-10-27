# Documentation Browser/API Coverage Matrix

Status: Verified on Dev; merged to Main in PR #329; production unchanged.
Human review: Reviewed by Svein Tore, 2026-10-27.
Owner: Codex. Date: 2026-10-27.
Baseline: 30 browser route rows (28 staff + 2 portal), 16 API HTTP operations.
Final: all 30 browser rows mapped; 33 typed API HTTP operations and 40 schemas.
GET forms/navigation map to context, catalogs, defaults and exact details.
Livewire field add/remove/move/save maps to ordered template field arrays and writes.
Tracking preview and command lookup additionally support safe API use.
No unsupported partner/carrier/category/template deletion, provider booking or #275
revision lifecycle is claimed. Full permissions/scopes/evidence are in the adjacent JSON.

| ID | Browser route | Official API routes | Behavior |
| --- | --- | --- | --- |
| D01 | customer-portal.documents.index | documentation.portal.index | Read; current account/contact/membership and visible client/site records. |
| D02 | customer-portal.documents.show | documentation.portal.show | Read; safe captured fields and 404 outside membership scope. |
| D03 | tech.context.set | documentation.context; documentation.lookups; knowledge.documentations.index | Action; explicit stateless filters replace mutable browser session context. |
| D04 | tech.documentations.index | knowledge.documentations.index; documentation.vendors.index; documentation.suppliers.index | Read; search, category/register aliases and internal/client/site filters. |
| D05 | tech.documentations.create | documentation.context; documentation.lookups; knowledge.documentation-categories.index; knowledge.documentation-templates.index | Read form; explicit template choice, active schemas and client/site selection. |
| D06 | tech.documentations.shipping-carriers.index | documentation.shipping-carriers.index | Read; search, lifecycle and bounded pagination. |
| D07 | tech.documentations.shipping-carriers.create | documentation.context; documentation.lookups | Read form; tracking/lifecycle/verification defaults and minimal vendor lookup for narrow carrier managers. |
| D08 | tech.documentations.shipping-carriers.store | documentation.shipping-carriers.store | Write; shared browser validator/action, no provider execution. |
| D09 | tech.documentations.shipping-carriers.show | documentation.shipping-carriers.show | Read; current carrier configuration and vendor relation. |
| D10 | tech.documentations.shipping-carriers.edit | documentation.shipping-carriers.show; documentation.context | Read edit form; current view or carrier management right. |
| D11 | tech.documentations.shipping-carriers.update | documentation.shipping-carriers.update | Write; shared validation/persistence; historical shipment snapshots unchanged. |
| D12 | tech.documentations.vendors.index | documentation.vendors.index | Read; canonical register with role, search and status filters. |
| D13 | tech.documentations.vendors.create | documentation.context | Read form; matching partner role defaults and typed fields. |
| D14 | tech.documentations.vendors.store | documentation.vendors.store | Write; shared fixed validator/action; unique code and protected provenance. |
| D15 | tech.documentations.vendors.show | documentation.vendors.show | Read detail/edit; all maintained fields and current view or update right. |
| D16 | tech.documentations.vendors.edit | documentation.vendors.show | Read detail/edit; all maintained fields and current view or update right. |
| D17 | tech.documentations.vendors.update | documentation.vendors.update | Write; partial changes/deactivation; same supplier IDs in Storage. |
| D18 | tech.documentations.suppliers.create | documentation.context | Read form; matching partner role defaults and typed fields. |
| D19 | tech.documentations.suppliers.store | documentation.suppliers.store | Write; shared fixed validator/action; unique code and protected provenance. |
| D20 | tech.documentations.suppliers.index | documentation.suppliers.index | Read; canonical register with role, search and status filters. |
| D21 | tech.documentations.store | knowledge.documentations.store | Write; exact active template snapshot and validated scope. |
| D22 | tech.documentations.show | knowledge.documentations.show | Read; captured fields/content, scope and portal metadata. |
| D23 | tech.documentations.portal-visibility.update | documentation.portal-visibility.update | Action; shared visibility/audit/notification; internal refused. |
| D24 | tech.documentations.edit | knowledge.documentations.show; documentation.context; documentation.lookups | Read edit form; update-only actors can inspect exact records without index rights. |
| D25 | tech.documentations.update | knowledge.documentations.update | Write; captured schema, normal portal update notification; scope changes hide until republished. |
| D26 | tech.documentations.destroy | knowledge.documentations.destroy | Write; soft delete and encrypted command read-back/replay. |
| D27 | tech.admin.system.templatesManagement.index | documentation.context | Read administrative navigation; exact form/system permissions, guarded destination links. |
| D28 | tech.admin.system.templatesManagement.doc.index | knowledge.documentation-templates.index; knowledge.documentation-categories.index | Read admin list; include_inactive needs the corresponding administration right. |
| D29 | tech.admin.system.templatesManagement.doc.create | documentation.context; knowledge.documentation-categories.index; knowledge.documentation-templates.store | Read form plus write; ordered field/layout array supports add/remove/reorder/save. |
| D30 | tech.admin.system.templatesManagement.doc.edit | knowledge.documentation-templates.show; knowledge.documentation-categories.index; knowledge.documentation-templates.update | Read form plus write; exact category/template and ordered schema replacement. |

## Verification
DocumentationApiParityTest validates actual successful JSON for all 33 HTTP
operations against generated Swagger, including PUT/PATCH, revocation, encrypted
replay and narrow form ownership. DocumentationApiContractTest verifies exact
runtime/Swagger equality, path parameters, scopes and typed references/errors.
The domain/browser, CustomerPortal, Storage and affected Integration tests verify
normal operation, visibility/audit/notification, template snapshots and isolation.
Native MySQL and trusted HTTPS provide separate deployment/runtime evidence.
Exact totals, handbook read-back, limitations and pending manual review are in
2026-10-27-documentation-api-verification.md.
