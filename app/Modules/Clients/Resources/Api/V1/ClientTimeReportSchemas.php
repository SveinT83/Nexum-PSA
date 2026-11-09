<?php

namespace App\Modules\Clients\Resources\Api\V1;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ClientTimeReportTotals', type: 'object', properties: [new OA\Property(property: 'work_minutes', type: 'integer', minimum: 0),
    new OA\Property(property: 'basis_minutes', type: 'integer', minimum: 0),
    new OA\Property(property: 'covered_minutes', type: 'integer', minimum: 0),
    new OA\Property(property: 'processed_billable_minutes', type: 'integer', minimum: 0),
    new OA\Property(property: 'unresolved_minutes', type: 'integer', minimum: 0)])]
#[OA\Schema(schema: 'ClientTimeReportItem', type: 'object', properties: [new OA\Property(property: 'type', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', nullable: true),
    new OA\Property(property: 'parent', type: 'string', nullable: true),
    new OA\Property(property: 'id', type: 'integer', nullable: true),
    new OA\Property(property: 'archived', type: 'boolean'),
    new OA\Property(property: 'entry_count', type: 'integer'),
    new OA\Property(property: 'work_minutes', type: 'integer'),
    new OA\Property(property: 'basis_minutes', type: 'integer'),
    new OA\Property(property: 'covered_minutes', type: 'integer'),
    new OA\Property(property: 'processed_billable_minutes', type: 'integer'),
    new OA\Property(property: 'unresolved_minutes', type: 'integer')])]
#[OA\Schema(schema: 'ClientTimeReportEntry', type: 'object', properties: [new OA\Property(property: 'source', type: 'string'),
    new OA\Property(property: 'object_key', type: 'string'),
    new OA\Property(property: 'reference', type: 'string'),
    new OA\Property(property: 'employee', type: 'string'),
    new OA\Property(property: 'provenance', type: 'string'),
    new OA\Property(property: 'billing_status', type: 'string'),
    new OA\Property(property: 'date', type: 'string', nullable: true),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'invoice_text', type: 'string', nullable: true),
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'ordered', type: 'boolean'),
    new OA\Property(property: 'gross_basis_minutes', type: 'integer'),
    new OA\Property(property: 'customer_charge_reversed', type: 'boolean'),
    new OA\Property(property: 'correction_id', type: 'integer', nullable: true),
    new OA\Property(property: 'correction_reason', type: 'string', nullable: true),
    new OA\Property(property: 'work_minutes', type: 'integer'),
    new OA\Property(property: 'basis_minutes', type: 'integer'),
    new OA\Property(property: 'covered_minutes', type: 'integer'),
    new OA\Property(property: 'processed_billable_minutes', type: 'integer'),
    new OA\Property(property: 'unresolved_minutes', type: 'integer')])]
#[OA\Schema(schema: 'ClientTimeReportMeta', type: 'object', properties: [new OA\Property(property: 'date_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'date_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'units', type: 'string'),
    new OA\Property(property: 'truncated', type: 'boolean'),
    new OA\Property(property: 'cross_request_snapshot', type: 'boolean'),
    new OA\Property(property: 'totals', ref: '#/components/schemas/ClientTimeReportTotals'),
    new OA\Property(property: 'total', type: 'integer'),
    new OA\Property(property: 'returned', type: 'integer'),
    new OA\Property(property: 'page', type: 'integer'),
    new OA\Property(property: 'per_page', type: 'integer'),
    new OA\Property(property: 'last_page', type: 'integer')])]
#[OA\Schema(schema: 'ClientTimeReportSummary', type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
    new OA\Property(property: 'client', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'client_number', type: 'string', nullable: true)]),
    new OA\Property(property: 'totals', ref: '#/components/schemas/ClientTimeReportTotals'),
    new OA\Property(property: 'corrections', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'kind', type: 'string'),
        new OA\Property(property: 'source_type', type: 'string', nullable: true), new OA\Property(property: 'source_id', type: 'integer', nullable: true),
        new OA\Property(property: 'reason', type: 'string'), new OA\Property(property: 'effective_date', type: 'string', format: 'date'),
        new OA\Property(property: 'recorded_at', type: 'string'), new OA\Property(property: 'adjustment_minutes', type: 'integer'),
        new OA\Property(property: 'reversed_usage_minutes', type: 'integer')], additionalProperties: false)),
    new OA\Property(property: 'object_count', type: 'integer'), new OA\Property(property: 'entry_count', type: 'integer'),
    new OA\Property(property: 'metric_definitions', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'links', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'))]),
    new OA\Property(property: 'meta', ref: '#/components/schemas/ClientTimeReportMeta')])]
#[OA\Schema(schema: 'ClientTimeReportValidation', type: 'object', properties: [new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'errors', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')))])]
#[OA\Schema(schema: 'ClientTimeReportItems', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientTimeReportItem')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/ClientTimeReportMeta'),
    new OA\Property(property: 'links', type: 'object', properties: [new OA\Property(property: 'next', type: 'string', nullable: true), new OA\Property(property: 'previous', type: 'string', nullable: true)])])]
#[OA\Schema(schema: 'ClientTimeReportEntries', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientTimeReportEntry')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/ClientTimeReportMeta'),
    new OA\Property(property: 'links', type: 'object', properties: [new OA\Property(property: 'next', type: 'string', nullable: true), new OA\Property(property: 'previous', type: 'string', nullable: true)])])]
/** Typed identified report responses; all durations are integer minutes. */
class ClientTimeReportSchemas {}
