<?php

namespace App\Modules\Nextcloud\Support;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Database\Eloquent\Model;

/** Allowlisted contracts: no raw provider errors, credentials, capabilities or private snapshots. */
class NextcloudApiProjection
{
    public static function connection(NextcloudConnection $c): array
    {
        $out = $c->only(['id', 'uuid', 'name', 'scope', 'mode', 'client_id', 'client_site_id', 'is_default', 'is_active',
            'base_url', 'admin_url', 'root_folder', 'documents_folder', 'sync_interval_minutes', 'service_username',
            'allow_user_credentials', 'supports_managed_writes', 'health_status']);
        foreach (['last_health_check_at', 'last_successful_sync_at', 'last_sync_requested_at'] as $field) {
            $out[$field] = $c->$field?->toIso8601String();
        }
        $out['service_password_configured'] = filled($c->service_password);
        $out['has_error'] = filled($c->last_error);
        $out['settings'] = collect(['calendar_sync_enabled', 'file_browser_enabled', 'users_groups_read_enabled', 'automatic_calendar_sync_enabled'])
            ->mapWithKeys(fn ($k) => [$k => (bool) ($c->settings[$k] ?? false)])->all();

        return $out;
    }

    public static function talk(NextcloudConnection $c): array
    {
        return ['talk_bot_id' => $c->talk_bot_id, 'talk_bot_secret_configured' => filled($c->talk_bot_secret),
            'talk_default_conversation_token' => $c->talk_default_conversation_token,
            'talk_bot_features' => $c->talk_bot_features ?? [], 'configured' => $c->hasTalkBot()];
    }

    public static function mapping(Model $m): array
    {
        $fields = match (class_basename($m)) {
            'NextcloudUserMapping' => ['id', 'connection_id', 'remote_user_id', 'remote_username', 'remote_email', 'user_id', 'identity_type', 'is_active'],
            'NextcloudGroupMapping' => ['id', 'connection_id', 'remote_group_id', 'remote_group_name', 'role_id', 'client_id', 'client_role', 'sync_mode', 'is_managed', 'is_active'],
            'NextcloudCalendarMapping' => ['id', 'connection_id', 'remote_calendar_id', 'remote_display_name', 'calendar_id', 'user_id', 'sync_direction', 'is_active'],
            default => ['id', 'connection_id', 'mappable_id', 'purpose', 'remote_path', 'is_active', 'auto_created'],
        };
        $out = $m->only($fields);
        if (class_basename($m) === 'NextcloudUserMapping') {
            $out['client_user_id'] = $m->identity_model_type === \App\Models\Clients\ClientUser::class ? $m->identity_model_id : null;
            $out['client_id'] = $m->metadata['client_id'] ?? null;
            $out['mapping_action'] = $m->metadata['mapping_action'] ?? null;
        }

        return $out;
    }

    public static function attempt(NextcloudSyncLog $log): array
    {
        return ['id' => $log->id, 'connection_id' => $log->connection_id, 'operation' => $log->operation, 'status' => $log->status,
            'records_seen' => (int) $log->records_seen, 'records_changed' => (int) $log->records_changed,
            'records_failed' => (int) $log->records_failed, 'started_at' => $log->started_at?->toIso8601String(),
            'finished_at' => $log->finished_at?->toIso8601String(),
            'summary' => array_map('intval', array_intersect_key($log->context['summary'] ?? [], array_flip([
                'users', 'groups', 'group_memberships', 'client_contacts_imported', 'client_contacts_updated', 'calendars', 'files',
                'calendar_events_seen', 'calendar_events_changed', 'calendar_events_imported', 'calendar_events_pushed', 'calendar_event_conflicts']))),
            'has_error' => $log->status === 'failed'];
    }
}
