<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Browser form replacement and API partial update share the same settings effect. */
class UpdateClientSettings
{
    public function handle(Request $request, Client $client, array $validated, bool $patch = false): void
    {
        DB::transaction(function () use ($request, $client, $validated, $patch) {
            $customFields = app(SyncCustomFieldValues::class);
            $attributes = [
                'name' => $validated['name'],
                'client_number' => $validated['client_number'] ?? null,
                'org_no' => $validated['org_no'] ?? null,
                'client_format_id' => $validated['client_format_id'] ?? null,
                'website' => $validated['website'] ?? null,
                'billing_email' => $validated['billing_email'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'active' => $request->boolean('active'),
            ];
            if ($patch) {
                $attributes = array_intersect_key($attributes, $validated);
            }
            $client->forceFill($attributes)->save();

            $rmmIntegration = Integration::where('type', 'rmm')->where('status', 'active')->first();
            if ($rmmIntegration && $request->has('rmm_external_id')) {
                if ($validated['rmm_external_id'] ?? null) {
                    \App\Models\System\Integrations\ClientRmmLink::updateOrCreate(
                        [
                            'integration_id' => $rmmIntegration->id,
                            'linkable_type' => Client::class,
                            'linkable_id' => $client->id,
                        ],
                        [
                            'external_id' => $request->rmm_external_id,
                        ]
                    );
                } else {
                    $client->rmmLinks()->where('integration_id', $rmmIntegration->id)->delete();
                }
            }

            $customFields->handle($client, (array) $request->input('custom_fields', []), $request->user(), 'ui');

        });
    }
}
