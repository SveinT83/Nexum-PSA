<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Support\Facades\DB;

/**
 * Creates a client with its required default site and primary contact.
 */
class CreateClientWithDefaults
{
    public function __construct(private readonly SuggestClientNumber $suggestClientNumber) {}

    /**
     * @return array{client: Client, warning: string|null}
     */
    public function handle(array $data): array
    {
        $warning = null;
        $client = DB::transaction(function () use ($data, &$warning): Client {
            $client = Client::query()->create([
                'name' => $data['name'],
                'client_number' => $data['client_number'] ?? $this->suggestClientNumber->handle(),
                'org_no' => $data['org_no'] ?? null,
                'client_format_id' => $data['client_format_id'] ?? null,
                'website' => $data['website'] ?? null,
                'sales_category_id' => $data['sales_category_id'] ?? null,
                'lead_temperature' => $data['lead_temperature'] ?? 3,
                'billing_email' => $data['billing_email'] ?? null,
                'notes' => $data['notes'] ?? null,
                'active' => $data['active'] ?? true,
            ]);

            $site = ClientSite::query()->create([
                'client_id' => $client->id,
                'name' => $data['site_name'],
                'address' => $data['site_address'] ?? null,
                'co_address' => $data['site_co_address'] ?? null,
                'zip' => $data['site_zip'] ?? null,
                'city' => $data['site_city'] ?? null,
                'county' => $data['site_county'] ?? null,
                'country' => $data['site_country'] ?? null,
                'is_default' => true,
            ]);

            ClientUser::query()->create([
                'client_site_id' => $site->id,
                'user_id' => null,
                'role' => $data['user_role'] ?? null,
                'name' => $data['user_name'],
                'email' => $data['user_email'] ?? null,
                'phone' => $data['user_phone'] ?? null,
                'is_default_for_site' => true,
                'is_default_for_client' => true,
                'active' => true,
            ]);

            if (! empty($data['create_in_rmm'])) {
                $integration = Integration::query()->where('type', 'rmm')->where('status', 'active')->first();

                if ($integration) {
                    $result = (new NAbleRmmClient($integration))->addClient($client->client_number.' - '.$client->name);

                    if (($result['success'] ?? false) && ! empty($result['clientid'])) {
                        ClientRmmLink::query()->create([
                            'integration_id' => $integration->id,
                            'external_id' => $result['clientid'],
                            'linkable_type' => Client::class,
                            'linkable_id' => $client->id,
                        ]);
                    } else {
                        $warning = 'Client created locally, but failed to create in N-able RMM: '.($result['error'] ?? 'Unknown error');
                    }
                }
            }

            return $client;
        });

        return ['client' => $client, 'warning' => $warning];
    }
}
