# Storage Browser/API Coverage Matrix

Status: Done On Dev / verified; named human review Pending. Final evidence: 615 / 7717, Storage 287 / 4556.
Parent: docs/rfc/2026-10-26-storage-full-api-parity.md.
Human checklist: HR-2026-10-26-STORAGE-API (Pending).
Baseline: 64 browser route rows and 24 API route rows / 27 HTTP operations.
Target/current registry: 78 API HTTP operations (HEAD excluded). Main/production unchanged.

The map covers form context, supporting docs, settings, reads, writes and actions.
An API equivalent is functional data/action parity; it need not duplicate HTML pages.
New read contexts preserve narrow form/receiving permissions. No role is granted by scopes.
Issue #286 contributor code is preserved and its numbering redesign stays separate.

## Functional groups

| Group | Supported behavior | Verification sources |
| --- | --- | --- |
| S01 | Discovery, docs, current actor/token authorization and pure reads | StorageFullApiTest; StorageApiContractTest |
| S02 | Canonical supplier/manufacturer, warehouse, room, carrier and item reference lookup | StorageFullApiTest supplier_lookup / supplier_search_item_creation_order_registration |
| S03 | Inventory lists, all operational filters/sorts, counts and incoming quantities | StorageModuleTest inventory sorting/incoming; StorageFullApiTest |
| S04 | Item creation/edit context, shared fields/supplier mapping, adjustment and soft deletion | StorageModuleTest inventory CRUD/stock guards; StorageFullApiTest |
| S05 | Safe item detail, supplier lines, locked tracking configuration and movement history | StorageDetailAndAdminTableSortingTest; StorageFullApiTest |
| S06 | Boxes, placement, current items, event history and detail sorting | StorageModuleTest; StorageDetailAndAdminTableSortingTest; StorageFullApiTest |
| S07 | Warehouse APIs, inventory administration/defaults and sortable settings | StorageModuleTest; StorageFullApiTest settings/revisions |
| S08 | Picking queue/read-only scope, guarded Ticket stock consumption and Economy effects | StorageFullApiTest picking_http; StorageModuleTest; TicketModuleTest/TicketWorkflowV3Test |
| S09 | Unified supplier-order queue and per-branch read authority | SupplierOrderWorkspaceTest; StorageFullApiTest |
| S10 | Order registration, edit context, lines, details, lifecycle and independent detail sorts | PurchaseOrderWorkflowTest; PurchaseOrderApiTest; PurchaseOrderTicketScopeTest; StorageFullApiTest |
| S11 | Shipment registration/status and carrier/tracking identifiers | PurchaseOrderApiTest; PurchaseOrderWorkflowTest |
| S12 | Outstanding line cancellation, close and eligible order cancellation | PurchaseOrderApiTest; PurchaseOrderWorkflowTest |
| S13 | Receiving-only queue/context, control slip, partial receipt/overage and immutable reversal | PurchaseReceivingWorkflowTest/ActionsTest; PurchaseOrderApiTest; StorageFullApiTest |
| S14 | Import inspection, safe evidence, attempt history and governed projection | SupplierOrderWorkspaceTest; SupplierOrderProfileMetadataAndRepairHistoryTest; StorageFullApiTest |
| S15 | Import line mapping and distinct zero-stock Item creation | SupplierOrderManualCorrectionAndFixtureTest; StorageFullApiTest |
| S16 | Audited correction/retry/finalize/reject and explicitly gated AI repair | SupplierOrderManualCorrectionAndFixtureTest; SupplierOrderImportPipelineTest; StorageFullApiTest |
| S17 | Policy reads/revisions, business normalization, exact preview and gated activation | SupplierOrderAutomationPolicyValidationTest; SupplierOrderAutomationUiTest; StorageFullApiTest |
| S18 | Profile list/filter/sort, context, shared creation and audited metadata edits | SupplierOrderProfileMetadataAndRepairHistoryTest; StorageFullApiTest |
| S19 | Protected fixtures, immutable versions, validation, activation/rollback/pause/retire | SupplierOrderProfileEngineTest; SupplierOrderManualCorrectionAndFixtureTest; StorageFullApiTest |
| S20 | Portable export and inactive draft import with local routing | SupplierOrderProfileEngineTest; StorageFullApiTest |

## Every current browser route

| Group | Browser method and path | Class | API route names (api.v1.storage prefix) |
| --- | --- | --- | --- |
| S07 | GET|HEAD /tech/admin/settings/storage/inventory | Read | settings.show, warehouses.index |
| S07 | POST /tech/admin/settings/storage/inventory/default-warehouse | Write | settings.update |
| S07 | POST /tech/admin/settings/storage/inventory/warehouses | Write | warehouses.store |
| S17 | GET|HEAD /tech/admin/settings/storage/purchase-order-automation | Read | policy.show, context |
| S17 | PUT /tech/admin/settings/storage/purchase-order-automation | Write | policy.preview, policy.update |
| S18 | GET|HEAD /tech/admin/settings/storage/supplier-order-profiles/create | Read | profiles.context, suppliers.index |
| S18 | GET|HEAD /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/edit | Read | profiles.show |
| S20 | GET|HEAD /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/export | Read | profiles.export |
| S19 | POST /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/fixtures | Action | profiles.fixtures.store |
| S20 | GET|HEAD /tech/admin/settings/storage/supplier-order-profiles/import | Read | profiles.context |
| S20 | POST /tech/admin/settings/storage/supplier-order-profiles/import | Action | profiles.import |
| S18 | GET|HEAD /tech/admin/settings/storage/supplier-order-profiles | Read | profiles.index |
| S19 | POST /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/pause | Action | profiles.pause |
| S19 | POST /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/retire | Action | profiles.retire |
| S18 | GET|HEAD /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile} | Read | profiles.show |
| S18 | POST /tech/admin/settings/storage/supplier-order-profiles | Write | profiles.store |
| S18 | PUT /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile} | Write | profiles.update |
| S19 | POST /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/{profileVersion}/activate | Action | profiles.activate.preview, profiles.versions.activate |
| S19 | GET|HEAD /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/create | Read | profiles.show, profiles.context |
| S19 | POST /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/{profileVersion}/rollback | Action | profiles.rollback.preview, profiles.versions.rollback |
| S19 | POST /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions | Write | profiles.versions.store |
| S19 | POST /tech/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/{profileVersion}/test | Action | profiles.versions.test |
| S06 | GET|HEAD /tech/storage/boxes/create | Read | context |
| S06 | GET|HEAD /tech/storage/boxes/{box} | Read | boxes.show |
| S06 | POST /tech/storage/boxes | Write | boxes.store |
| S01 | GET|HEAD /tech/storage/docs | Read | docs |
| S03 | GET|HEAD /tech/storage | Read | context, items.index, summary, boxes.index, suppliers.index |
| S04 | POST /tech/storage/items/{item}/adjust | Action | items.adjust |
| S04 | GET|HEAD /tech/storage/items/create | Read | context, lookups.items, suppliers.index |
| S04 | DELETE /tech/storage/items/{item} | Write | items.destroy |
| S04 | GET|HEAD /tech/storage/items/{item}/edit | Read | items.edit-context, suppliers.index |
| S05 | GET|HEAD /tech/storage/items/{item} | Read | items.show, items.movements |
| S04 | POST /tech/storage/items | Write | items.store |
| S04 | PATCH /tech/storage/items/{item} | Write | items.update |
| S08 | GET|HEAD /tech/storage/picking | Read | picking.index |
| S01 | GET|HEAD /tech/storage/picking/docs | Read | docs |
| S08 | POST /tech/storage/picking/{costEntry}/pick | Action | picking.pick |
| S16 | POST /tech/storage/supplier-order-imports/{purchaseOrderImport}/manual-correction | Action | imports.correct |
| S16 | POST /tech/storage/supplier-order-imports/{purchaseOrderImport}/finalize | Action | imports.finalize |
| S14 | GET|HEAD /tech/storage/supplier-order-imports | Read | imports.index, supplier-orders.index |
| S15 | POST /tech/storage/supplier-order-imports/{purchaseOrderImport}/lines/{importLine}/create-item | Write | imports.lines.create-item |
| S15 | POST /tech/storage/supplier-order-imports/{purchaseOrderImport}/lines/{importLine}/map | Write | imports.lines.map |
| S16 | POST /tech/storage/supplier-order-imports/{purchaseOrderImport}/reject | Action | imports.reject |
| S16 | POST /tech/storage/supplier-order-imports/{purchaseOrderImport}/repair | Action | imports.repair.preview, imports.repair |
| S16 | POST /tech/storage/supplier-order-imports/{purchaseOrderImport}/retry | Action | imports.retry.preview, imports.retry |
| S14 | GET|HEAD /tech/storage/supplier-order-imports/{purchaseOrderImport} | Read | imports.show |
| S12 | POST /tech/storage/purchase-orders/{purchaseOrder}/cancel | Action | purchase-orders.cancel |
| S12 | POST /tech/storage/purchase-orders/{purchaseOrder}/close | Action | purchase-orders.close |
| S13 | GET|HEAD /tech/storage/purchase-orders/{purchaseOrder}/control-slip | Read | purchase-orders.control-slip |
| S10 | GET|HEAD /tech/storage/purchase-orders/create | Read | context, lookups.items, suppliers.index |
| S10 | GET|HEAD /tech/storage/purchase-orders/{purchaseOrder}/edit | Read | purchase-orders.edit-context |
| S09 | GET|HEAD /tech/storage/purchase-orders | Read | supplier-orders.index, purchase-orders.index |
| S12 | POST /tech/storage/purchase-orders/{purchaseOrder}/lines/{purchaseOrderLine}/cancel | Action | purchase-orders.lines.cancel |
| S13 | POST /tech/storage/purchase-orders/{purchaseOrder}/receipts | Action | purchase-orders.receipts.store |
| S13 | GET|HEAD /tech/storage/purchase-orders/{purchaseOrder}/receive | Read | purchase-orders.receiving-context |
| S11 | GET|HEAD /tech/storage/purchase-orders/{purchaseOrder}/shipments/create | Read | purchase-orders.show, carriers.index |
| S11 | PATCH /tech/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/status | Write | purchase-orders.shipments.status.update |
| S11 | POST /tech/storage/purchase-orders/{purchaseOrder}/shipments | Write | purchase-orders.shipments.store |
| S11 | POST /tech/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/trackings | Write | purchase-orders.shipments.trackings.store |
| S10 | GET|HEAD /tech/storage/purchase-orders/{purchaseOrder} | Read | purchase-orders.show |
| S10 | POST /tech/storage/purchase-orders | Write | purchase-orders.store |
| S10 | PATCH /tech/storage/purchase-orders/{purchaseOrder} | Write | purchase-orders.update |
| S13 | POST /tech/storage/receipts/{receipt}/reverse | Action | purchase-orders.receipts.reverse |
| S13 | GET|HEAD /tech/storage/receiving | Read | receiving.index |

## Every API HTTP operation

| Method and path | Token abilities | Response schema |
| --- | --- | --- |
| POST /api/v1/storage/automation-policy/preview | storage.policy.update | StorageApiPreviewResponse |
| GET /api/v1/storage/automation-policy | storage.policy.read | StorageApiPolicyResponse |
| PUT /api/v1/storage/automation-policy | storage.policy.update | StorageApiPolicyResponse |
| GET /api/v1/storage/boxes/{box} | storage.read | StorageApiBoxResponse |
| PUT /api/v1/storage/boxes/{box} | storage.update | StorageApiBoxResponse |
| PATCH /api/v1/storage/boxes/{box} | storage.update | StorageApiBoxResponse |
| GET /api/v1/storage/boxes | storage.read | StorageApiBoxResourcePage |
| POST /api/v1/storage/boxes | storage.create | StorageApiBoxResponse |
| GET /api/v1/storage/carriers | storage.purchase.read | StorageApiCarrierPage |
| GET /api/v1/storage/context | storage.read | StorageApiContextResponse |
| GET /api/v1/storage/docs | storage.read | StorageApiPreviewResponse |
| POST /api/v1/storage/items/{item}/adjust | storage.update | StorageApiItemResponse |
| GET /api/v1/storage/items/{item}/edit-context | storage.update | StorageApiItemEditContextResponse |
| GET /api/v1/storage/items/{item}/movements | storage.read | StorageApiMovementPage |
| DELETE /api/v1/storage/items/{item} | storage.update | StorageApiItemResponse |
| GET /api/v1/storage/items/{item} | storage.read | StorageApiItemResponse |
| PUT /api/v1/storage/items/{item} | storage.update | StorageApiItemResponse |
| PATCH /api/v1/storage/items/{item} | storage.update | StorageApiItemResponse |
| GET /api/v1/storage/items | storage.read | StorageApiItemResourcePage |
| POST /api/v1/storage/items | storage.create | StorageApiItemResponse |
| GET /api/v1/storage/lookups/items | storage.read | StorageApiItemPage |
| GET /api/v1/storage/operations/{operation} | storage.operations.read | StorageApiOperationResponse |
| POST /api/v1/storage/picking/{costEntry}/pick | storage.pick | StorageApiPickingResponse |
| GET /api/v1/storage/picking | storage.picking.read | StorageApiPickingPage |
| POST /api/v1/storage/purchase-orders/{purchaseOrder}/cancel | storage.purchase.manage | StorageApiOrderResponse |
| POST /api/v1/storage/purchase-orders/{purchaseOrder}/close | storage.purchase.manage | StorageApiOrderResponse |
| GET /api/v1/storage/purchase-orders/{purchaseOrder}/control-slip | storage.purchase.read | StorageApiControlSlipResponse |
| GET /api/v1/storage/purchase-orders/{purchaseOrder}/edit-context | storage.purchase.manage | StorageApiOrderEditContextResponse |
| POST /api/v1/storage/purchase-orders/{purchaseOrder}/lines/{purchaseOrderLine}/cancel | storage.purchase.manage | StorageApiOrderResponse |
| POST /api/v1/storage/purchase-orders/{purchaseOrder}/receipts/{purchaseReceipt}/reverse | storage.purchase.reverse | StorageApiReceiptResponse |
| POST /api/v1/storage/purchase-orders/{purchaseOrder}/receipts | storage.purchase.receive | StorageApiReceiptResponse |
| GET /api/v1/storage/purchase-orders/{purchaseOrder}/receiving-context | storage.purchase.receive | StorageApiReceivingContextResponse |
| PATCH /api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/status | storage.purchase.manage | StorageApiShipmentResponse |
| POST /api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/trackings | storage.purchase.manage | StorageApiTrackingResponse |
| POST /api/v1/storage/purchase-orders/{purchaseOrder}/shipments | storage.purchase.manage | StorageApiShipmentResponse |
| GET /api/v1/storage/purchase-orders/{purchaseOrder} | storage.purchase.read | StorageApiOrderResponse |
| PUT /api/v1/storage/purchase-orders/{purchaseOrder} | storage.purchase.manage | StorageApiOrderResponse |
| GET /api/v1/storage/purchase-orders | storage.purchase.read | StorageApiOrderResourcePage |
| POST /api/v1/storage/purchase-orders | storage.purchase.manage | StorageApiOrderResponse |
| GET /api/v1/storage/receiving | storage.purchase.receive | StorageApiWorkspaceRowPage |
| GET /api/v1/storage/rooms | storage.read | StorageApiRoomPage |
| GET /api/v1/storage/settings | storage.settings.read | StorageApiSettingsResponse |
| PUT /api/v1/storage/settings | storage.settings.update | StorageApiSettingsResponse |
| GET /api/v1/storage/summary | storage.read | StorageApiSummaryResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/finalize | storage.imports.execute | StorageApiImportFinalizeResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/lines/{line}/create-item | storage.imports.execute | StorageApiImportResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/lines/{line}/map | storage.imports.resolve | StorageApiImportResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/manual-correction | storage.imports.resolve | StorageApiImportResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/reject | storage.imports.execute | StorageApiImportResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/repair/preview | storage.imports.execute,storage.profiles.manage | StorageApiPreviewResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/repair | storage.imports.execute,storage.profiles.manage | StorageApiImportResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/retry/preview | storage.imports.execute | StorageApiPreviewResponse |
| POST /api/v1/storage/supplier-order-imports/{import}/retry | storage.imports.execute | StorageApiImportResponse |
| GET /api/v1/storage/supplier-order-imports/{import} | storage.imports.read | StorageApiImportResponse |
| GET /api/v1/storage/supplier-order-imports | storage.imports.read | StorageApiWorkspaceRowPage |
| GET /api/v1/storage/supplier-order-profiles/context | storage.profiles.read | StorageApiProfileContextResponse |
| POST /api/v1/storage/supplier-order-profiles/import | storage.profiles.manage | StorageApiProfileResponse |
| GET /api/v1/storage/supplier-order-profiles/{profile}/export | storage.profiles.read | StorageApiProfileExport |
| POST /api/v1/storage/supplier-order-profiles/{profile}/fixtures | storage.profiles.manage | StorageApiProfileFixtureResultResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/pause | storage.profiles.manage | StorageApiProfileResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/retire | storage.profiles.manage | StorageApiProfileResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/versions/{version}/activate/preview | storage.profiles.manage | StorageApiPreviewResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/versions/{version}/activate | storage.profiles.manage | StorageApiProfileResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/versions/{version}/rollback/preview | storage.profiles.manage | StorageApiPreviewResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/versions/{version}/rollback | storage.profiles.manage | StorageApiProfileResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/versions/{version}/test | storage.profiles.manage | StorageApiProfileTestResponse |
| POST /api/v1/storage/supplier-order-profiles/{profile}/versions | storage.profiles.manage | StorageApiProfileResponse |
| GET /api/v1/storage/supplier-order-profiles/{profile} | storage.profiles.read | StorageApiProfileResponse |
| PUT /api/v1/storage/supplier-order-profiles/{profile} | storage.profiles.manage | StorageApiProfileResponse |
| GET /api/v1/storage/supplier-order-profiles | storage.profiles.read | StorageApiProfilePage |
| POST /api/v1/storage/supplier-order-profiles | storage.profiles.manage | StorageApiProfileResponse |
| GET /api/v1/storage/supplier-orders | storage.purchase.read | StorageApiWorkspaceRowPage |
| GET /api/v1/storage/suppliers/{supplier} | storage.suppliers.read | StorageApiSupplierResponse |
| GET /api/v1/storage/suppliers | storage.suppliers.read | StorageApiSupplierPage |
| PUT /api/v1/storage/warehouses/{warehouse} | storage.update | StorageApiWarehouseResponse |
| PATCH /api/v1/storage/warehouses/{warehouse} | storage.update | StorageApiWarehouseResponse |
| GET /api/v1/storage/warehouses | storage.read | StorageApiWarehouseResourcePage |
| POST /api/v1/storage/warehouses | storage.create | StorageApiWarehouseResponse |

All operations also have route/ability assertions and exact runtime-versus-Swagger checks.
Successful HTTP scenario bodies are checked against the generated response schemas.
Native MySQL lock/uniqueness, trusted HTTPS, handbook publication and exact test results
are recorded in the final verification report; source/matrix presence alone is not proof.
