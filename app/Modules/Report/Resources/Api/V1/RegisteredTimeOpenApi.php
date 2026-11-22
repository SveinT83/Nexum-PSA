<?php

namespace App\Modules\Report\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Typed, identified personal reporting; does not modify coordinator Worklog contracts. */
#[OA\Schema(schema: 'TimeReportLookupItem', type: 'object', required: ['id', 'label', 'hint'], properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'hint', type: 'string'),
])]
#[OA\Schema(schema: 'TimeReportLookupResponse', type: 'object', required: ['data', 'meta'], properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TimeReportLookupItem')),
    new OA\Property(property: 'meta', type: 'object', required: ['total', 'page', 'next_page'], properties: [
        new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'page', type: 'integer'),
        new OA\Property(property: 'next_page', type: 'integer', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'RegisteredTimeEntry', type: 'object', required: ['source', 'id', 'record_id', 'reference', 'title', 'employee', 'client', 'minutes', 'billable', 'provenance'], properties: [
    new OA\Property(property: 'source', type: 'string', enum: ['ticket', 'task']),
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'record_id', type: 'integer'),
    new OA\Property(property: 'reference', type: 'string'), new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'url', type: 'string', nullable: true), new OA\Property(property: 'archived', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', nullable: true),
    new OA\Property(property: 'parent_ticket_id', type: 'integer', nullable: true),
    new OA\Property(property: 'parent_ticket_reference', type: 'string', nullable: true),
    new OA\Property(property: 'employee_id', type: 'integer', nullable: true), new OA\Property(property: 'employee', type: 'string'),
    new OA\Property(property: 'client_id', type: 'integer', nullable: true), new OA\Property(property: 'client', type: 'string'),
    new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'billable', type: 'boolean'),
    new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'provenance', type: 'string'),
])]
#[OA\Schema(schema: 'RegisteredTimeGroup', type: 'object', required: ['key', 'label', 'minutes', 'entry_count'], properties: [
    new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'entry_count', type: 'integer'),
])]
#[OA\Schema(schema: 'RegisteredTimeTotals', type: 'object', required: ['minutes', 'entry_count', 'ticket_minutes', 'task_minutes'], properties: [
    new OA\Property(property: 'minutes', type: 'integer'), new OA\Property(property: 'entry_count', type: 'integer'),
    new OA\Property(property: 'ticket_minutes', type: 'integer'), new OA\Property(property: 'task_minutes', type: 'integer'),
])]
#[OA\Schema(schema: 'RegisteredTimeMeta', type: 'object', properties: [
    new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'page', type: 'integer'),
    new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'),
    new OA\Property(property: 'groups_total', type: 'integer'), new OA\Property(property: 'group_page', type: 'integer'),
    new OA\Property(property: 'groups_last_page', type: 'integer'),
    new OA\Property(property: 'totals_scope', type: 'string', enum: ['all_matching_entries']),
    new OA\Property(property: 'truncated', type: 'boolean', enum: [false]),
    new OA\Property(property: 'consistency', type: 'string'),
    new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'RegisteredTimeFilters', type: 'object', properties: [
    new OA\Property(property: 'date_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'date_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'employee_id', type: 'integer', nullable: true),
    new OA\Property(property: 'client_id', type: 'integer', nullable: true),
    new OA\Property(property: 'ticket_id', type: 'integer', nullable: true),
    new OA\Property(property: 'task_id', type: 'integer', nullable: true),
    new OA\Property(property: 'source', type: 'string', nullable: true),
    new OA\Property(property: 'context', type: 'string', nullable: true),
    new OA\Property(property: 'billable', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', nullable: true),
    new OA\Property(property: 'group_by', type: 'string', nullable: true),
    new OA\Property(property: 'all_history', type: 'boolean', nullable: true),
    new OA\Property(property: 'page', type: 'integer', nullable: true),
    new OA\Property(property: 'group_page', type: 'integer', nullable: true),
    new OA\Property(property: 'per_page', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'RegisteredTimeResponse', type: 'object', required: ['data', 'groups', 'totals', 'filters', 'meta'], properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RegisteredTimeEntry')),
    new OA\Property(property: 'groups', type: 'array', items: new OA\Items(ref: '#/components/schemas/RegisteredTimeGroup')),
    new OA\Property(property: 'totals', ref: '#/components/schemas/RegisteredTimeTotals'),
    new OA\Property(property: 'filters', ref: '#/components/schemas/RegisteredTimeFilters'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/RegisteredTimeMeta'),
])]
#[OA\Schema(schema: 'RegisteredTimeError', type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'errors', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
])]
#[OA\Get(path: '/api/v1/reports/time/entries', operationId: 'getRegisteredTimeReport', tags: ['Reports'], summary: 'Read identified registered Ticket and Task time',
    description: 'Personal active internal actor only; requires report.view, ticket.view, task.view and client.view. Coordinator-bound tokens denied. Private Tasks/context mismatches excluded; Task billing projections excluded. One bounded request snapshot; totals cover all matching entries, entries/groups paginate independently. At most 10,000 matching entries; narrow the period on 422.',
    security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', description: 'Inclusive original work date; defaults to start of current Oslo month.', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive original work date; defaults to today in Oslo.', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'employee_id', in: 'query', description: 'Exact employee ID; omit for all permitted employees.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'client_id', in: 'query', description: 'Exact Client ID; omit to report across Clients.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'ticket_id', in: 'query', description: 'Ticket including its visible Task effort.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'task_id', in: 'query', description: 'Exact Task, excluding descendants.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'source', in: 'query', description: 'Source filter; all, ticket or task.', schema: new OA\Schema(type: 'string', enum: ['all', 'ticket', 'task'])),
        new OA\Parameter(name: 'context', in: 'query', description: 'Work context; all, client or internal.', schema: new OA\Schema(type: 'string', enum: ['all', 'client', 'internal'])),
        new OA\Parameter(name: 'billable', in: 'query', description: 'Source billable flag; all, yes or no; not an invoice total.', schema: new OA\Schema(type: 'string', enum: ['all', 'yes', 'no'])),
        new OA\Parameter(name: 'status', in: 'query', description: 'Source-qualified status: ticket:ID or task:ID.', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'group_by', in: 'query', description: 'employee, client, record, date or employee_client.', schema: new OA\Schema(type: 'string', enum: ['employee', 'client', 'record', 'date', 'employee_client'])),
        new OA\Parameter(name: 'all_history', in: 'query', description: 'Allow open-ended dates instead of month defaults. Oversized reports fail.', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'page', in: 'query', description: 'One-based entry page.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'group_page', in: 'query', description: 'One-based group page; groups summarize all matching entries.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', description: 'Page size, 1 to 100.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [
            new OA\Response(response: 200, description: 'Identified report', content: new OA\JsonContent(ref: '#/components/schemas/RegisteredTimeResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Missing scope/native rights or ineligible identity'),
            new OA\Response(response: 422, description: 'Invalid filters or oversized report', content: new OA\JsonContent(ref: '#/components/schemas/RegisteredTimeError'))], x: ['required-scopes' => ['reports.time.read']])]
#[OA\Get(path: '/api/v1/reports/time/lookups/{kind}', operationId: 'getRegisteredTimeLookups', tags: ['Reports'],
    summary: 'Find permitted employees, Clients, Tickets and Tasks', security: [['bearerAuth' => []]],
    description: 'Same personal identity and native rights as the report. Employee names include inactive staff with native internal roles/permissions; no system or portal identities. Names only, no email/private notes. Empty search is valid; follow next_page to reach every match.',
    parameters: [
        new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['employee', 'client', 'ticket', 'task'])),
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 100)),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100000)),
        new OA\Parameter(name: 'id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
    ], responses: [
        new OA\Response(response: 200, description: '50 suggestions per page, with explicit continuation', content: new OA\JsonContent(ref: '#/components/schemas/TimeReportLookupResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Denied'),
        new OA\Response(response: 404, description: 'Unknown lookup'), new OA\Response(response: 422, description: 'Invalid input'),
    ], x: ['required-scopes' => ['reports.time.read']])]
#[OA\Get(path: '/api/v1/reports/time/export', operationId: 'exportRegisteredTimeReport', tags: ['Reports'], summary: 'Download the complete internal registered-time report',
    description: 'Same actor, filters, source/privacy checks and 10,000 entry limit as report reads. Export includes all matching groups and entries, never just the selected page. Contains names and internal notes; no automatic sharing. Both read and export abilities required.',
    security: [['bearerAuth' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', description: 'Inclusive original work date; defaults to start of current Oslo month.', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive original work date; defaults to today in Oslo.', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'employee_id', in: 'query', description: 'Exact employee ID; omit for all permitted employees.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'client_id', in: 'query', description: 'Exact Client ID; omit to report across Clients.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'ticket_id', in: 'query', description: 'Ticket including its visible Task effort.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'task_id', in: 'query', description: 'Exact Task, excluding descendants.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'source', in: 'query', description: 'Source filter; all, ticket or task.', schema: new OA\Schema(type: 'string', enum: ['all', 'ticket', 'task'])),
        new OA\Parameter(name: 'context', in: 'query', description: 'Work context; all, client or internal.', schema: new OA\Schema(type: 'string', enum: ['all', 'client', 'internal'])),
        new OA\Parameter(name: 'billable', in: 'query', description: 'Source billable flag; all, yes or no; not an invoice total.', schema: new OA\Schema(type: 'string', enum: ['all', 'yes', 'no'])),
        new OA\Parameter(name: 'status', in: 'query', description: 'Source-qualified status: ticket:ID or task:ID.', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'group_by', in: 'query', description: 'employee, client, record, date or employee_client.', schema: new OA\Schema(type: 'string', enum: ['employee', 'client', 'record', 'date', 'employee_client'])),
        new OA\Parameter(name: 'all_history', in: 'query', description: 'Allow open-ended dates instead of month defaults. Oversized reports fail.', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'page', in: 'query', description: 'One-based entry page.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'group_page', in: 'query', description: 'One-based group page; groups summarize all matching entries.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', description: 'Page size, 1 to 100.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        new OA\Parameter(name: 'format', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf']))], responses: [
            new OA\Response(response: 200, description: 'Complete internal XLSX or PDF', content: [new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')), new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', schema: new OA\Schema(type: 'string', format: 'binary'))]),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Denied'), new OA\Response(response: 422, description: 'Invalid filter/format or oversized report')], x: ['required-scopes' => ['reports.time.read', 'reports.time.export']])]
class RegisteredTimeOpenApi {}
