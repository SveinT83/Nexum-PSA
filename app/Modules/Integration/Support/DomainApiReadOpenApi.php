<?php

namespace App\Modules\Integration\Support;

use OpenApi\Attributes as OA;

/** Schemas for the exact typed read contract, not an unrestricted execution API. */
#[OA\Schema(schema: 'ParityPageMeta', type: 'object', required: ['current_page', 'last_page', 'per_page', 'total'], properties: [
    new OA\Property(property: 'current_page', type: 'integer', minimum: 1), new OA\Property(property: 'last_page', type: 'integer', minimum: 1),
    new OA\Property(property: 'per_page', type: 'integer', minimum: 1, maximum: 100), new OA\Property(property: 'total', type: 'integer', minimum: 0),
    new OA\Property(property: 'from', type: 'integer', nullable: true), new OA\Property(property: 'to', type: 'integer', nullable: true),
    new OA\Property(property: 'path', type: 'string'), new OA\Property(property: 'links', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'ParityPageLinks', type: 'object', properties: [
    new OA\Property(property: 'first', type: 'string'), new OA\Property(property: 'last', type: 'string'),
    new OA\Property(property: 'prev', type: 'string', nullable: true), new OA\Property(property: 'next', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ParityCapability', type: 'object', required: ['name', 'methods', 'path', 'ability', 'token_authorized', 'actor_authorized', 'object_and_runtime_checks_required'], properties: [
    new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'path', type: 'string'), new OA\Property(property: 'ability', type: 'string'),
    new OA\Property(property: 'methods', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'token_authorized', type: 'boolean'), new OA\Property(property: 'actor_authorized', type: 'boolean'),
    new OA\Property(property: 'object_and_runtime_checks_required', type: 'boolean', example: true),
    new OA\Property(property: 'required_scopes', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'required_permissions', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'coordinator_profile', type: 'string', nullable: true), new OA\Property(property: 'conditional_authorization', type: 'array', items: new OA\Items(type: 'string'))])]
#[OA\Schema(schema: 'ParityCapabilities', type: 'object', required: ['domain', 'surface', 'operations', 'limits'], properties: [
    new OA\Property(property: 'domain', type: 'string', enum: ['ticket', 'task']), new OA\Property(property: 'surface', type: 'string', enum: ['domain_api']),
    new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityCapability')),
    new OA\Property(property: 'contract', type: 'string'), new OA\Property(property: 'limits', ref: '#/components/schemas/ParityReadLimits'),
])]
#[OA\Schema(schema: 'ParityReadLimits', type: 'object', required: ['default_per_page', 'maximum_per_page'], properties: [
    new OA\Property(property: 'default_per_page', type: 'integer', example: 25), new OA\Property(property: 'maximum_per_page', type: 'integer', example: 100),
])]
#[OA\Schema(schema: 'ParityFormContext', type: 'object', required: ['catalogs', 'limits'], properties: [
    new OA\Property(property: 'catalogs', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'limits', ref: '#/components/schemas/ParityReadLimits'),
    new OA\Property(property: 'context_fields', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'defaults', type: 'object', properties: [new OA\Property(property: 'status_id', type: 'integer'), new OA\Property(property: 'priority_id', type: 'integer'), new OA\Property(property: 'estimated_minutes', type: 'integer')]),
    new OA\Property(property: 'owner_types', type: 'array', items: new OA\Items(type: 'string', enum: ['client', 'ticket'])),
])]
#[OA\Schema(schema: 'TicketRecordApiContext', type: 'object', required: ['ticket_id', 'ticket_key', 'actions', 'catalogs'], properties: [
    new OA\Property(property: 'ticket_id', type: 'integer'), new OA\Property(property: 'ticket_key', type: 'string'),
    new OA\Property(property: 'client_id', type: 'integer', nullable: true), new OA\Property(property: 'site_id', type: 'integer', nullable: true),
    new OA\Property(property: 'contact_id', type: 'integer', nullable: true), new OA\Property(property: 'work_context_id', type: 'integer', nullable: true),
    new OA\Property(property: 'actions', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'object')),
    new OA\Property(property: 'catalogs', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
])]
#[OA\Schema(schema: 'TaskRecordApiContext', type: 'object', required: ['task_id', 'completed', 'is_blocked'], properties: [
    new OA\Property(property: 'task_id', type: 'integer'), new OA\Property(property: 'owner_type', type: 'string', nullable: true), new OA\Property(property: 'owner_id', type: 'integer', nullable: true),
    new OA\Property(property: 'client_id', type: 'integer', nullable: true), new OA\Property(property: 'site_id', type: 'integer', nullable: true), new OA\Property(property: 'work_context_id', type: 'integer', nullable: true),
    new OA\Property(property: 'completed', type: 'boolean'), new OA\Property(property: 'is_blocked', type: 'boolean'), new OA\Property(property: 'ticket_rate_options_url', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TicketEventReadback', type: 'object', required: ['id', 'ticket_id', 'type'], properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_id', type: 'integer'), new OA\Property(property: 'actor_id', type: 'integer', nullable: true),
    new OA\Property(property: 'type', type: 'string'), new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'TaskDependencyReadback', type: 'object', required: ['id', 'task_id', 'dependency_type', 'is_required', 'target', 'target_restricted'], properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'task_id', type: 'integer'), new OA\Property(property: 'dependency_type', type: 'string', enum: ['blocks_start', 'blocks_completion']),
    new OA\Property(property: 'is_required', type: 'boolean'), new OA\Property(property: 'target', ref: '#/components/schemas/TaskSummaryReadback', nullable: true), new OA\Property(property: 'target_restricted', type: 'boolean'),
])]
#[OA\Schema(schema: 'ParityReadError', type: 'object', required: ['message'], properties: [
    new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'errors', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
])]
#[OA\Schema(schema: 'ParityReferenceOption', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string', nullable: true), new OA\Property(property: 'slug', type: 'string', nullable: true), new OA\Property(property: 'sort_order', type: 'integer', nullable: true), new OA\Property(property: 'is_closed', type: 'boolean', nullable: true), new OA\Property(property: 'is_default', type: 'boolean', nullable: true), new OA\Property(property: 'is_done', type: 'boolean', nullable: true), new OA\Property(property: 'is_cancelled', type: 'boolean', nullable: true), new OA\Property(property: 'level', type: 'integer', nullable: true), new OA\Property(property: 'type', type: 'string', nullable: true), new OA\Property(property: 'client_number', type: 'string', nullable: true), new OA\Property(property: 'client_id', type: 'integer', nullable: true), new OA\Property(property: 'client_site_id', type: 'integer', nullable: true), new OA\Property(property: 'site_id', type: 'integer', nullable: true), new OA\Property(property: 'user_id', type: 'integer', nullable: true), new OA\Property(property: 'email', type: 'string', nullable: true), new OA\Property(property: 'phone', type: 'string', nullable: true), new OA\Property(property: 'hostname', type: 'string', nullable: true), new OA\Property(property: 'serial_number', type: 'string', nullable: true), new OA\Property(property: 'ip_address', type: 'string', nullable: true), new OA\Property(property: 'timezone', type: 'string', nullable: true), new OA\Property(property: 'is_active', type: 'boolean', nullable: true), new OA\Property(property: 'published_version_id', type: 'integer', nullable: true), new OA\Property(property: 'sku', type: 'string', nullable: true), new OA\Property(property: 'warehouse_id', type: 'integer', nullable: true), new OA\Property(property: 'box_id', type: 'integer', nullable: true), new OA\Property(property: 'sale_price', type: 'string', nullable: true), new OA\Property(property: 'can_be_ordered', type: 'boolean', nullable: true), new OA\Property(property: 'requires_customer_quote', type: 'boolean', nullable: true), new OA\Property(property: 'short_description', type: 'string', nullable: true), new OA\Property(property: 'parent_id', type: 'integer', nullable: true), new OA\Property(property: 'title', type: 'string', nullable: true), new OA\Property(property: 'status_id', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true), new OA\Property(property: 'created_by', type: 'integer', nullable: true), new OA\Property(property: 'work_context_id', type: 'integer', nullable: true), new OA\Property(property: 'due_at', type: 'string', nullable: true), new OA\Property(property: 'estimated_minutes', type: 'integer', nullable: true), new OA\Property(property: 'completed_at', type: 'string', nullable: true), new OA\Property(property: 'completed_by', type: 'integer', nullable: true), new OA\Property(property: 'blocks_owner_completion', type: 'boolean', nullable: true), new OA\Property(property: 'ticket_key', type: 'string', nullable: true), new OA\Property(property: 'subject', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', nullable: true), new OA\Property(property: 'updated_at', type: 'string', nullable: true)])]
#[OA\Schema(schema: 'TicketTimeRateOptionReadback', type: 'object', required: ['key', 'label'], properties: [new OA\Property(property: 'key', type: 'string', nullable: true), new OA\Property(property: 'source', type: 'string', nullable: true), new OA\Property(property: 'label', type: 'string', nullable: true), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'contract_id', type: 'integer', nullable: true), new OA\Property(property: 'contract_item_id', type: 'integer', nullable: true), new OA\Property(property: 'contract_item_time_rate_id', type: 'integer', nullable: true), new OA\Property(property: 'time_rate_id', type: 'integer', nullable: true), new OA\Property(property: 'rate_name', type: 'string', nullable: true), new OA\Property(property: 'rate_code', type: 'string', nullable: true), new OA\Property(property: 'rate_type', type: 'string', nullable: true), new OA\Property(property: 'rate_unit', type: 'string', nullable: true), new OA\Property(property: 'rate_amount_ex_vat', type: 'string', nullable: true), new OA\Property(property: 'rate_currency', type: 'string', nullable: true), new OA\Property(property: 'billing_basis', type: 'string', nullable: true)])]
#[OA\Schema(schema: 'TicketScheduleReadback', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer', nullable: true), new OA\Property(property: 'ticket_id', type: 'integer', nullable: true), new OA\Property(property: 'task_template_group_id', type: 'integer', nullable: true), new OA\Property(property: 'schedule_type', type: 'string', nullable: true), new OA\Property(property: 'planned_start_at', type: 'string', nullable: true), new OA\Property(property: 'planned_end_at', type: 'string', nullable: true), new OA\Property(property: 'timezone', type: 'string', nullable: true), new OA\Property(property: 'recurrence_rule', type: 'string', nullable: true), new OA\Property(property: 'recurrence_ends_at', type: 'string', nullable: true), new OA\Property(property: 'calendar_event_id', type: 'integer', nullable: true), new OA\Property(property: 'sla_mode', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', nullable: true), new OA\Property(property: 'updated_at', type: 'string', nullable: true)])]
#[OA\Schema(schema: 'ParityDeletedRecord', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'deleted', type: 'boolean')])]
final class DomainApiReadOpenApi {}
