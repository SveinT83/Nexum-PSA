<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendors = [
            ['name' => 'Microsoft', 'email' => 'support@microsoft.com'],
            ['name' => 'Amazon Web Services', 'email' => 'support@aws.amazon.com'],
            ['name' => 'Google Cloud', 'email' => 'support@google.com'],
            ['name' => 'Apple', 'email' => 'support@apple.com'],
            ['name' => 'Lenovo', 'email' => 'support@lenovo.com'],
            ['name' => 'Dell', 'email' => 'support@dell.com'],
            ['name' => 'HP', 'email' => 'support@hp.com'],
            ['name' => 'Cisco', 'email' => 'support@cisco.com'],
        ];

        foreach ($vendors as $vendor) {
            \App\Models\Doc\Vendor::updateOrCreate(['name' => $vendor['name']], $vendor);
        }
    }
}
