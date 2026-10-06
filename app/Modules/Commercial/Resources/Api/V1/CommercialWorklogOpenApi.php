<?php

namespace App\Modules\Commercial\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Only explicitly allowed facts and opaque joins are documented for coordinator disclosure. */
#[OA\Schema(schema: 'CommercialWorklogContract', type: 'object', required: ['link_status', 'contract_alias', 'contract_item_alias', 'start_date', 'end_date', 'approval_status'], properties: [
    new OA\Property(property: 'link_status', type: 'string', enum: ['linked', 'unlinked', 'inconsistent']),
    new OA\Property(property: 'contract_alias', type: 'string', nullable: true),
    new OA\Property(property: 'contract_item_alias', type: 'string', nullable: true),
    new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'approval_status', description: 'Current contract state, not historical approval or a customer document.', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CommercialWorklogAllocation', type: 'object', required: ['link_status', 'allocation_alias', 'status', 'covered_minutes', 'billable_minutes', 'contract'], properties: [
    new OA\Property(property: 'link_status', type: 'string', enum: ['linked', 'inconsistent']),
    new OA\Property(property: 'allocation_alias', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', nullable: true),
    new OA\Property(property: 'covered_minutes', type: 'integer', nullable: true),
    new OA\Property(property: 'billable_minutes', type: 'integer', nullable: true),
    new OA\Property(property: 'contract', type: 'object', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/CommercialWorklogContract')]),
])]
#[OA\Schema(schema: 'CommercialConsumptionFact', type: 'object', required: ['entry_alias', 'client_alias', 'technician_alias', 'fact_type', 'source', 'work_date', 'minutes', 'contract'], properties: [
    new OA\Property(property: 'entry_alias', type: 'string'),
    new OA\Property(property: 'client_alias', type: 'string'),
    new OA\Property(property: 'technician_alias', type: 'string', nullable: true),
    new OA\Property(property: 'fact_type', type: 'string', enum: ['direct_timebank_consumption']),
    new OA\Property(property: 'source', type: 'string', enum: ['quick_client']),
    new OA\Property(property: 'work_date', type: 'string', format: 'date'),
    new OA\Property(property: 'minutes', description: 'Direct registered consumption, separate from Ticket/Task actual minutes.', type: 'integer'),
    new OA\Property(property: 'contract', ref: '#/components/schemas/CommercialWorklogContract'),
])]
#[OA\Schema(schema: 'CommercialContractLinkFact', type: 'object', required: ['entry_alias', 'record_alias', 'record_link_status', 'client_alias', 'fact_type', 'source', 'work_date', 'basis_minutes', 'billable', 'contract', 'allocation'], properties: [
    new OA\Property(property: 'entry_alias', description: 'Same alias as Report for direct Ticket rows; Task billing projection has no actual-time entry match.', type: 'string'),
    new OA\Property(property: 'record_alias', description: 'Ticket alias for direct entries; Task alias for consistent authorized Task billing groups. Do not allocate rounded deltas to individual Task entries.', type: 'string', nullable: true),
    new OA\Property(property: 'record_link_status', type: 'string', enum: ['linked', 'inconsistent']),
    new OA\Property(property: 'client_alias', type: 'string', nullable: true),
    new OA\Property(property: 'fact_type', type: 'string', enum: ['ticket_billing_basis']),
    new OA\Property(property: 'source', type: 'string', enum: ['ticket', 'task_billing_projection']),
    new OA\Property(property: 'work_date', type: 'string', format: 'date'),
    new OA\Property(property: 'basis_minutes', description: 'Billing basis; do not add to actual work or direct consumption totals.', type: 'integer'),
    new OA\Property(property: 'billable', type: 'boolean'),
    new OA\Property(property: 'contract', ref: '#/components/schemas/CommercialWorklogContract'),
    new OA\Property(property: 'allocation', type: 'object', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/CommercialWorklogAllocation')]),
])]
#[OA\Schema(schema: 'CommercialConsumptionResponse', type: 'object', required: ['data', 'meta'], properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommercialConsumptionFact')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/WorklogPageMeta'),
])]
#[OA\Schema(schema: 'CommercialContractLinksResponse', type: 'object', required: ['data', 'meta'], properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommercialContractLinkFact')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/WorklogPageMeta'),
])]
final class CommercialWorklogOpenApi {}
