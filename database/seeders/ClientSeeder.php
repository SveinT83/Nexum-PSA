<?php

namespace Database\Seeders;

use App\Models\Clients\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        if (Client::count() > 0) {
            return; // avoid duplicating basic demo data
        }

        Client::create([
            'client_number' => '1001',
            'name' => 'Acme AS',
            'org_no' => 'NO999888777',
            'billing_email' => 'billing@acme.test',
            'notes' => 'Demo klient for test.',
            'active' => true,
        ]);

        Client::create([
            'client_number' => '1002',
            'name' => 'Beta Konsulent',
            'org_no' => 'NO111222333',
            'billing_email' => 'post@beta.test',
            'notes' => 'Andre klient for listevisning.',
            'active' => true,
        ]);
    }
}
