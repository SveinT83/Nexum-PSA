<?php

namespace App\Modules\Calendar\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Explicit contracts; GET projections never perform initialization. */
#[OA\Schema(schema: 'CalendarApiContainer', type: 'object', properties: [

    new OA\Property(property: 'uuid', type: 'string'), new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(property: 'type', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'color', type: 'string'), new OA\Property(property: 'owner_type', type: 'string', nullable: true),
    new OA\Property(property: 'owner_initials', type: 'string'), new OA\Property(property: 'owner_badge', type: 'string'),
    new OA\Property(property: 'calendar_type', type: 'string'), new OA\Property(property: 'calendar_type_label', type: 'string'),
    new OA\Property(property: 'is_owned_by_viewer', type: 'boolean'), new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'is_default', type: 'boolean'), new OA\Property(property: 'is_visible_by_default', type: 'boolean'),
    new OA\Property(property: 'visibility_default', type: 'string'), new OA\Property(property: 'transparency_default', type: 'string'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'links', type: 'object', properties: [new OA\Property(property: 'events', type: 'string')]),

    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'can_manage', type: 'boolean'),
    new OA\Property(property: 'owner_id', type: 'integer', nullable: true), new OA\Property(property: 'owner_kind', type: 'string'),
    new OA\Property(property: 'owner_label', type: 'string', nullable: true), new OA\Property(property: 'ownership_group', type: 'string'),
])]
#[OA\Schema(schema: 'CalendarApiContext', type: 'object', required: ['setup_required', 'timezone', 'default_view', 'calendars', 'capabilities', 'dependencies'], properties: [
    new OA\Property(property: 'setup_required', type: 'boolean'), new OA\Property(property: 'timezone', type: 'string'),
    new OA\Property(property: 'default_view', type: 'string', enum: ['day', 'week', 'month', 'list']),
    new OA\Property(property: 'workday_start', type: 'string'), new OA\Property(property: 'workday_end', type: 'string'),
    new OA\Property(property: 'default_calendar_id', type: 'integer', nullable: true),
    new OA\Property(property: 'calendars', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarApiContainer')),
    new OA\Property(property: 'capabilities', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'boolean')),
    new OA\Property(property: 'ownership_groups', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'availability_users', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string')])),
    new OA\Property(property: 'references', type: 'object', properties: [
        new OA\Property(property: 'calendars_total', type: 'integer'), new OA\Property(property: 'calendars_complete', type: 'boolean'),
        new OA\Property(property: 'calendars_url', type: 'string'), new OA\Property(property: 'users_url', type: 'string'),
        new OA\Property(property: 'users_complete', type: 'boolean'),
    ]),
    new OA\Property(property: 'dependencies', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'object', properties: [new OA\Property(property: 'available', type: 'boolean'), new OA\Property(property: 'owner', type: 'string'), new OA\Property(property: 'reason', type: 'string')])),
])]
#[OA\Schema(schema: 'CalendarApiEventList', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarApiEventDetail')),
    new OA\Property(property: 'meta', type: 'object', properties: [
        new OA\Property(property: 'count', type: 'integer'), new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'complete', type: 'boolean'),
        new OA\Property(property: 'recurrence_limited', type: 'boolean'), new OA\Property(property: 'recurrence_expansion_limit', type: 'integer'), new OA\Property(property: 'from', type: 'string', format: 'date-time'),
        new OA\Property(property: 'to', type: 'string', format: 'date-time'), new OA\Property(property: 'timezone', type: 'string'),
        new OA\Property(property: 'view', type: 'string'), new OA\Property(property: 'previous_date', type: 'string', format: 'date'),
        new OA\Property(property: 'next_date', type: 'string', format: 'date'),
    ]),
])]
#[OA\Schema(schema: 'CalendarApiOccurrence', type: 'object', properties: [

    new OA\Property(property: 'work_context_id', type: 'integer', nullable: true), new OA\Property(property: 'work_context_type', type: 'string', nullable: true),
    new OA\Property(property: 'owner_id', type: 'integer', nullable: true), new OA\Property(property: 'owner_kind', type: 'string'),
    new OA\Property(property: 'owner_label', type: 'string', nullable: true), new OA\Property(property: 'owner_initials', type: 'string'),
    new OA\Property(property: 'owner_badge', type: 'string'), new OA\Property(property: 'ownership_badge', type: 'string'),
    new OA\Property(property: 'calendar_color', type: 'string'), new OA\Property(property: 'calendar_type', type: 'string'),
    new OA\Property(property: 'calendar_type_label', type: 'string'), new OA\Property(property: 'ownership_group', type: 'string'),
    new OA\Property(property: 'is_owned_by_viewer', type: 'boolean'),

    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'uuid', type: 'string'),
    new OA\Property(property: 'calendar_id', type: 'integer'), new OA\Property(property: 'calendar_name', type: 'string'),
    new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'location', type: 'string', nullable: true), new OA\Property(property: 'meeting_url', type: 'string', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'all_day', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'transparency', type: 'string'),
    new OA\Property(property: 'visibility', type: 'string'), new OA\Property(property: 'details_visible', type: 'boolean'),
    new OA\Property(property: 'is_private', type: 'boolean'), new OA\Property(property: 'is_recurring', type: 'boolean'),
    new OA\Property(property: 'occurrence_key', type: 'string', nullable: true), new OA\Property(property: 'source_owned', type: 'boolean'),
    new OA\Property(property: 'source_edit_url', type: 'string', nullable: true),
    new OA\Property(property: 'participants', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'participant_type', type: 'string'),
        new OA\Property(property: 'participant_id', type: 'integer', nullable: true), new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true), new OA\Property(property: 'role', type: 'string'),
        new OA\Property(property: 'response_status', type: 'string'), new OA\Property(property: 'notify', type: 'boolean'),
    ])),
])]
#[OA\Schema(schema: 'CalendarApiParticipantWrite', type: 'object', required: ['email'], additionalProperties: false, properties: [
    new OA\Property(property: 'email', type: 'string', format: 'email'), new OA\Property(property: 'name', type: 'string', nullable: true),
    new OA\Property(property: 'participant_type', type: 'string', enum: ['email']), new OA\Property(property: 'role', type: 'string', enum: ['required', 'optional', 'resource']),
    new OA\Property(property: 'notify', type: 'boolean', description: 'Local record flag only; does not dispatch an invitation.'),
])]
#[OA\Schema(schema: 'CalendarApiEventWrite', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'calendar_id', type: 'integer'), new OA\Property(property: 'title', type: 'string', maxLength: 255),
    new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'location', type: 'string', nullable: true),
    new OA\Property(property: 'meeting_url', type: 'string', format: 'uri', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'all_day', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'tentative', 'cancelled']),
    new OA\Property(property: 'transparency', type: 'string', enum: ['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere']),
    new OA\Property(property: 'visibility', type: 'string', enum: ['default', 'public', 'private', 'confidential']),
    new OA\Property(property: 'participants', type: 'array', nullable: true, maxItems: 100, items: new OA\Items(ref: '#/components/schemas/CalendarApiParticipantWrite')),
    new OA\Property(property: 'revision', type: 'string', minLength: 64, maxLength: 64),
])]
#[OA\Schema(schema: 'CalendarApiEventCreate', type: 'object', additionalProperties: false, required: ['title', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'calendar_id', type: 'integer'), new OA\Property(property: 'title', type: 'string', maxLength: 255),
    new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'location', type: 'string', nullable: true),
    new OA\Property(property: 'meeting_url', type: 'string', format: 'uri', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'all_day', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'tentative', 'cancelled']),
    new OA\Property(property: 'transparency', type: 'string', enum: ['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere']),
    new OA\Property(property: 'visibility', type: 'string', enum: ['default', 'public', 'private', 'confidential']),
    new OA\Property(property: 'participants', type: 'array', nullable: true, maxItems: 100, items: new OA\Items(ref: '#/components/schemas/CalendarApiParticipantWrite')),
    new OA\Property(property: 'recurrence_frequency', type: 'string', enum: ['none', 'daily', 'weekly', 'monthly'], nullable: true),
    new OA\Property(property: 'recurrence_ends_at', type: 'string', format: 'date-time', nullable: true),

])]
#[OA\Schema(schema: 'CalendarApiEventDetail', allOf: [new OA\Schema(ref: '#/components/schemas/CalendarApiOccurrence')], properties: [

    new OA\Property(property: 'work_context_id', type: 'integer', nullable: true), new OA\Property(property: 'priority', type: 'string', nullable: true),
    new OA\Property(property: 'created_by', type: 'integer', nullable: true), new OA\Property(property: 'updated_by', type: 'integer', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'calendar', ref: '#/components/schemas/CalendarApiContainer'),
    new OA\Property(property: 'work_context', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer', nullable: true), new OA\Property(property: 'type', type: 'string', nullable: true), new OA\Property(property: 'name', type: 'string', nullable: true)]),
    new OA\Property(property: 'links', type: 'object', properties: [new OA\Property(property: 'self', type: 'string')]),

    new OA\Property(property: 'revision', type: 'string', nullable: true),
    new OA\Property(property: 'series_id', type: 'integer', nullable: true),
    new OA\Property(property: 'recurrence', type: 'object', nullable: true, properties: [new OA\Property(property: 'frequency', type: 'string'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true)]),
    new OA\Property(property: 'source', type: 'string', nullable: true), new OA\Property(property: 'external_source', type: 'string', nullable: true),
    new OA\Property(property: 'external_calendar_id', type: 'string', nullable: true), new OA\Property(property: 'external_event_id', type: 'string', nullable: true),
    new OA\Property(property: 'external_uid', type: 'string', nullable: true), new OA\Property(property: 'sync_status', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'CalendarApiDeletePreviewInput', type: 'object', additionalProperties: false, required: ['scope'], properties: [
    new OA\Property(property: 'scope', type: 'string', enum: ['record', 'event', 'series']),
    new OA\Property(property: 'original_starts_at', type: 'string', format: 'date-time', nullable: true, description: 'Required for one occurrence.'),
])]
#[OA\Schema(schema: 'CalendarApiDelete', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'scope', type: 'string', enum: ['record', 'event', 'series']),
    new OA\Property(property: 'original_starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'revision', type: 'string'), new OA\Property(property: 'preview', type: 'string'),
])]
#[OA\Schema(schema: 'CalendarApiDeletePreview', type: 'object', properties: [
    new OA\Property(property: 'event_id', type: 'integer'), new OA\Property(property: 'calendar_id', type: 'integer'), new OA\Property(property: 'series_id', type: 'integer', nullable: true),
    new OA\Property(property: 'scope', type: 'string', enum: ['record', 'event', 'series']),
    new OA\Property(property: 'original_starts_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'revision', type: 'string'), new OA\Property(property: 'preview', type: 'string'),
])]
final class CalendarApiOpenApi {}
