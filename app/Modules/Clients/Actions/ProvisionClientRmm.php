<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Persist target intent before transport and reconcile unknown outcomes without another create. */
class ProvisionClientRmm
{
    public function handle(Model $target, ?int $actorId = null, bool $reconcileOnly = false, ?string $externalId = null): array
    {
        abort_unless(Schema::hasTable('client_rmm_intents'), 503, 'Apply the Client command migration before RMM provisioning.');
        abort_if(DB::transactionLevel() > 0, 409, 'RMM provisioning must follow the committed local record.');
        $integration = Integration::query()->where('type', 'rmm')->where('status', 'active')->first();
        abort_unless($integration, 409, 'RMM is not active.');
        $site = $target instanceof ClientSite;
        $client = $site ? $target->client : $target;
        $kind = $site ? 'site' : 'client';
        $name = $site ? $target->name : $client->client_number.' - '.$client->name;
        $parent = $site ? $client->rmmLinks()->where('integration_id', $integration->id)->value('external_id') : null;
        abort_if($site && ! $parent, 409, 'The Client needs an RMM mapping before Site provisioning.');
        $link = $target->rmmLinks()->where('integration_id', $integration->id)->first();
        if ($link) {
            return ['state' => 'confirmed', 'external_id' => $link->external_id, 'replayed' => true];
        }
        $claim = DB::transaction(function () use ($integration, $target, $kind, $client, $name, $actorId, $reconcileOnly) {
            Client::query()->lockForUpdate()->findOrFail($client->id);
            if ($target instanceof ClientSite) {
                ClientSite::query()->where('client_id', $client->id)->lockForUpdate()->findOrFail($target->id);
            }
            $row = DB::table('client_rmm_intents')->where('integration_id', $integration->id)->where('target_type', $kind)->where('target_id', $target->id)->first();
            if ($row) {
                return ['row' => $row, 'new' => false];
            }
            abort_if($reconcileOnly, 404, 'No provisioning attempt exists for this target.');
            $id = (string) Str::uuid();
            DB::table('client_rmm_intents')->insert(['id' => $id, 'integration_id' => $integration->id, 'target_type' => $kind,
                'target_id' => $target->id, 'client_id' => $client->id, 'actor_id' => $actorId, 'expected_name' => $name,
                'state' => 'running', 'created_at' => now(), 'updated_at' => now()]);

            return ['row' => DB::table('client_rmm_intents')->find($id), 'new' => true];
        });
        $row = $claim['row'];
        $api = app(NAbleRmmClient::class, ['integration' => $integration]);
        $id = $row->external_id ?? $externalId;
        if ($claim['new']) {
            try {
                $response = $site ? $api->addSite($parent, $name) : $api->addClient($name);
                $id = $response[$site ? 'siteid' : 'clientid'] ?? null;
                DB::table('client_rmm_intents')->where('id', $row->id)->update(['external_id' => $id, 'state' => 'unknown', 'updated_at' => now()]);
            } catch (\Throwable $error) {
                report($error);
            }
        }
        if (! $id) {
            DB::table('client_rmm_intents')->where('id', $row->id)->update(['state' => 'unknown', 'updated_at' => now()]);

            return ['intent_id' => $row->id, 'state' => 'unknown', 'external_id' => null, 'reconciliation_required' => true];
        }
        // Read-only retry can finish a known identity; it never repeats addClient/addSite.
        $remote = $site ? $api->listSites($parent) : $api->listClients();
        $found = collect(isset($remote['error']) ? [] : $remote)->filter(fn ($v) => is_array($v)
            && (string) ($v[$site ? 'siteid' : 'clientid'] ?? $v['id'] ?? '') === (string) $id
            && (string) ($v['name'] ?? $v['sitename'] ?? '') === $row->expected_name);
        if ($found->count() !== 1) {
            DB::table('client_rmm_intents')->where('id', $row->id)->update(['state' => 'unknown', 'external_id' => (string) $id, 'updated_at' => now()]);

            return ['intent_id' => $row->id, 'state' => 'unknown', 'external_id' => (string) $id, 'reconciliation_required' => true];
        }
        DB::transaction(function () use ($target, $integration, $row, $id) {
            $current = DB::table('client_rmm_intents')->where('id', $row->id)->lockForUpdate()->first();
            $link = $target->rmmLinks()->where('integration_id', $integration->id)->first();
            abort_if($link && (string) $link->external_id !== (string) $id, 409, 'The target mapping changed.');
            ClientRmmLink::query()->updateOrCreate(['integration_id' => $integration->id, 'linkable_type' => $target->getMorphClass(), 'linkable_id' => $target->id], ['external_id' => (string) $id]);
            DB::table('client_rmm_intents')->where('id', $row->id)->update(['state' => 'confirmed', 'external_id' => (string) $id, 'updated_at' => now()]);
        });

        return ['intent_id' => $row->id, 'state' => 'confirmed', 'external_id' => (string) $id, 'replayed' => ! $claim['new']];
    }
}
