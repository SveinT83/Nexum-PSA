<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A legacy cascading/nulling FK must not erase history or turn Site-only portal access client-wide. */
class DeleteClientSite
{
    public function handle(ClientSite $site): void
    {
        DB::transaction(function () use ($site) {
            Client::query()->lockForUpdate()->findOrFail($site->client_id);
            $site = ClientSite::query()->lockForUpdate()->findOrFail($site->id);
            foreach ([
                'client_users' => 'client_site_id', 'documentations' => 'site_id', 'assets' => 'site_id',
                'customer_portal_memberships' => 'site_id', 'customer_portal_invitations' => 'site_id',
                'customer_portal_audit_events' => 'site_id', 'nextcloud_connections' => 'client_site_id',
                'intake_submissions' => 'matched_site_id', 'telephony_calls' => 'site_id',
                'task_template_runs' => 'site_id', 'tasks' => 'site_id', 'tripletex_customer_profiles' => 'site_id',
            ] as $table => $column) {
                if (Schema::hasColumn($table, $column)) {
                    abort_if(DB::table($table)->where($column, $site->id)->exists(), 409, 'This Site has linked or historical records. Review them in their owning workflow before deleting the Site.');
                }
            }
            $types = app(CustomFieldModelRegistry::class)->storageTypesFor($site->getMorphClass());
            abort_if(DB::table('contact_relations')->whereIn('related_type', $types)->where('related_id', $site->id)->exists(), 409, 'Canonical Contact relations must be reviewed before deleting this Site.');
            abort_if($site->rmmLinks()->exists(), 409, 'The Site still has a provider mapping.');
            if (Schema::hasTable('client_rmm_intents')) {
                abort_if(DB::table('client_rmm_intents')->where('target_type', 'site')->where('target_id', $site->id)->exists(), 409, 'Retain the Site until its provider history is reviewed.');
            }
            $site->delete();
        });
    }
}
