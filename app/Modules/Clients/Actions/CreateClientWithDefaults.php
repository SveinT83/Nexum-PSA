<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Actions\StoreContact;

/**
 * Creates a client with its required default site and primary contact.
 */
class CreateClientWithDefaults
{
    public function __construct(
        private readonly CreateClientRecord $createClientRecord,
        private readonly StoreContact $storeContact,
    ) {}

    /**
     * @return array{client: Client, warning: string|null}
     */
    public function handle(array $data): array
    {
        $warning = null;
        $client = $this->createClientRecord->handle([
            'tripletex_site' => [
                'address' => $data['site_address'] ?? '', 'co_address' => $data['site_co_address'] ?? '',
                'zip' => $data['site_zip'] ?? '', 'city' => $data['site_city'] ?? '', 'country' => $data['site_country'] ?? '',
            ],
            'tripletex_number_mode' => $data['tripletex_number_mode'] ?? false,
            'tripletex_request_key' => $data['tripletex_request_key'] ?? null,
            'tripletex_customer_id' => $data['tripletex_customer_id'] ?? null,
            'name' => $data['name'],
            'client_number' => $data['client_number'] ?? null,
            'org_no' => $data['org_no'] ?? null,
            'client_format_id' => $data['client_format_id'] ?? null,
            'website' => $data['website'] ?? null,
            'sales_category_id' => $data['sales_category_id'] ?? null,
            'lead_temperature' => $data['lead_temperature'] ?? 3,
            'billing_email' => $data['billing_email'] ?? null,
            'notes' => $data['notes'] ?? null,
            'active' => $data['active'] ?? true,
        ], function (Client $client) use ($data, &$warning): void {

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

            $this->storeContact->handle([
                'display_name' => $data['user_name'],
                'email' => $data['user_email'] ?? null,
                'phone' => $data['user_phone'] ?? null,
                'job_title' => $data['user_role'] ?? null,
                'relation_type' => 'contact',
                'client_id' => $client->id,
                'site_id' => $site->id,
                'is_default_for_site' => true,
                'is_default_for_client' => true,
                'active' => true,
                'created_from' => 'client_creation',
            ]);

        });

        if (! empty($data['create_in_rmm'])) {
            try {
                $result = app(ProvisionClientRmm::class)->handle($client, auth()->id());
                $warning = $result['state'] === 'confirmed' ? null : 'Client created locally; RMM provisioning requires reconciliation.';
            } catch (\Throwable $error) {
                report($error);
                $warning = 'Client created locally; RMM provisioning was not verified.';
            }
        }

        return ['client' => $client, 'warning' => $warning];
    }
}
