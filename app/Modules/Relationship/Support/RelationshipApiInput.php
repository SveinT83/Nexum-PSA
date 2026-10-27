<?php

namespace App\Modules\Relationship\Support;

use App\Modules\Relationship\Actions\RelationshipConfiguration;
use App\Modules\Relationship\Models\NexumRelationship;
use Illuminate\Http\Request;

/** Typed API values normalize into the authoritative shared browser form rules. */
class RelationshipApiInput
{
    public static function configuration(Request $request, NexumRelationship $row): array
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255', 'direction' => 'sometimes|required|string',
            'relationship_type' => 'sometimes|required|string', 'client_id' => 'sometimes|nullable|integer',
            'vendor_id' => 'sometimes|nullable|integer', 'remote_base_url' => 'sometimes|nullable|url|max:255',
            'remote_instance_id' => 'sometimes|nullable|string|max:255', 'remote_organization_name' => 'sometimes|nullable|string|max:255',
            'remote_organization_identifier' => 'sometimes|nullable|string|max:255', 'status' => 'sometimes|required|string',
            'capabilities' => 'sometimes|array', 'capabilities.*' => 'string',
            'ticket_queue_id' => 'sometimes|nullable|integer', 'ticket_auto_create_queue' => 'sometimes|boolean',
            'documentation_two_way' => 'sometimes|boolean', 'attachment_max_mb' => 'sometimes|integer|min:1|max:100',
            'attachment_allowed_content_types' => 'sometimes|array', 'attachment_allowed_content_types.*' => 'string|max:255',
            'service_areas' => 'sometimes|array', 'service_areas.*' => 'string|max:255',
            'status_mapping' => 'sometimes|array', 'status_mapping.*' => 'string|max:255',
            'outbound_token' => 'sometimes|nullable|string|max:2000', 'inbound_token' => 'sometimes|nullable|string|max:2000',
            'webhook_secret' => 'sometimes|nullable|string|max:2000',
        ]);
        foreach (['capabilities', 'attachment_allowed_content_types', 'service_areas'] as $list) {
            abort_if(isset($data[$list]) && ! array_is_list($data[$list]), 422, 'Expected an ordered list.');
        }
        if (isset($data['status_mapping'])) {
            foreach ($data['status_mapping'] as $key => $value) {
                abort_if(! is_string($key) || trim($key) === '' || preg_match('/[=\r\n]/', $key.$value), 422, 'Invalid status mapping.');
            }
        }
        $base = array_merge($row->only(['name', 'direction', 'relationship_type', 'client_id', 'vendor_id', 'remote_base_url',
            'remote_instance_id', 'remote_organization_name', 'remote_organization_identifier', 'status']), [
                'direction' => $row->direction ?? RelationshipDirection::WE_ARE_PROVIDER,
                'relationship_type' => $row->relationship_type ?? RelationshipType::CUSTOMER_PROVIDER,
                'status' => $row->status ?? RelationshipStatus::DRAFT,
                'capabilities' => array_keys(array_filter($row->capabilities ?? [])),
                'ticket_queue_id' => $row->ticket_policy['queue_id'] ?? null,
                'ticket_auto_create_queue' => $row->ticket_policy['auto_create_queue'] ?? true,
                'documentation_two_way' => $row->documentation_policy['two_way'] ?? true,
                'attachment_max_mb' => $row->attachment_policy['max_mb'] ?? 10,
                'attachment_allowed_content_types' => $row->attachment_policy['allowed_content_types'] ?? [],
                'service_areas' => $row->service_areas ?? ['it'], 'status_mapping' => $row->status_mapping ?? [],
            ]);
        $values = array_replace($base, $data);
        foreach (['service_areas', 'attachment_allowed_content_types'] as $field) {
            abort_if(collect($values[$field])->contains(fn ($value) => preg_match('/[\r\n,]/', $value)), 422, 'List items cannot contain separators.');
            $values[$field] = implode("\n", $values[$field]);
        }
        $values['status_mapping'] = collect($values['status_mapping'])->map(fn ($v, $k) => $k.'='.$v)->implode("\n");
        $validated = app(RelationshipConfiguration::class)->validateForm(Request::create('/', 'POST', $values), $row);
        if ($validated['status'] === RelationshipStatus::ACTIVE) {
            abort_unless(str_starts_with((string) $validated['remote_base_url'], 'https://'), 422, 'Active API relationships require trusted HTTPS.');
        }

        return $validated;
    }

    public static function page(Request $request): int
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return $data['per_page'] ?? 20;
    }
}
