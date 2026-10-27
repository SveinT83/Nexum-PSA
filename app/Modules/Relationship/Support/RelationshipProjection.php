<?php

namespace App\Modules\Relationship\Support;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncEvent;
use App\Modules\Relationship\Models\NexumSyncLink;
use Illuminate\Database\Eloquent\Model;

/** Exclude credentials, arbitrary metadata, transport errors and machine identities. */
class RelationshipProjection
{
    public static function version(Model $row): string
    {
        $fields = match (true) {
            $row instanceof \App\Modules\Documentation\Models\Documentation => ['id', 'title', 'client_id', 'site_id', 'scope_type', 'work_context_id', 'template_id', 'category_id', 'data_json', 'template_snapshot_json', 'updated_at'],
            $row instanceof \App\Models\Knowledge\Article => ['id', 'title', 'body_markdown', 'visibility', 'status', 'client_scope_id', 'published_revision_id', 'updated_at'],
            default => array_merge(['id', 'updated_at'], $row->getFillable()),
        };
        $state = [];
        foreach ($fields as $field) {
            $state[$field] = $row->getAttribute($field);
        }

        return '"'.hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), (string) config('app.key')).'"';
    }

    public static function relationship(NexumRelationship $row): array
    {
        return array_merge($row->only(['id', 'name', 'direction', 'relationship_type', 'client_id', 'vendor_id',
            'remote_base_url', 'remote_instance_id', 'remote_organization_name', 'remote_organization_identifier',
            'status', 'health_status', 'capabilities', 'ticket_policy', 'documentation_policy',
            'attachment_policy', 'status_mapping', 'service_areas', 'created_by', 'updated_by']),
            ['status_mapping' => (object) ($row->status_mapping ?? []), 'version' => self::version($row), 'created_at' => $row->created_at?->toISOString(),
                'updated_at' => $row->updated_at?->toISOString(), 'credentials' => [
                    'inbound_configured' => filled($row->inbound_token_hash),
                    'outbound_configured' => filled($row->getRawOriginal('outbound_token_encrypted')),
                    'signing_configured' => filled($row->getRawOriginal('webhook_secret_encrypted')),
                    'rotated_at' => $row->token_rotated_at?->toISOString()],
                'health' => self::health($row)]);
    }

    public static function health(NexumRelationship $row): array
    {
        return ['status' => $row->health_status, 'last_successful_sync_at' => $row->last_successful_sync_at?->toISOString(),
            'last_failure_at' => $row->last_failure_at?->toISOString(), 'health_checked_at' => $row->health_checked_at?->toISOString(),
            'failure_present' => filled($row->failure_summary), 'sync_link_count' => $row->syncLinks()->count(), 'sync_event_count' => $row->syncEvents()->count()];
    }

    public static function link(NexumSyncLink $row): array
    {
        return array_merge($row->only(['id', 'relationship_id', 'domain', 'local_id', 'remote_type', 'remote_id',
            'remote_version', 'remote_checksum', 'direction', 'sync_status', 'conflict_status']),
            ['last_synced_at' => $row->last_synced_at?->toISOString(), 'error_present' => filled($row->last_error)]);
    }

    public static function event(NexumSyncEvent $row): array
    {
        return array_merge($row->only(['id', 'relationship_id', 'sync_link_id', 'direction', 'capability',
            'local_id', 'remote_type', 'remote_id', 'event_type', 'actor_id', 'payload_checksum', 'outcome', 'error_code']),
            ['occurred_at' => $row->occurred_at?->toISOString(), 'error_present' => filled($row->error_message)]);
    }
}
