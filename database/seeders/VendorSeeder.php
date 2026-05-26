<?php

namespace Database\Seeders;

use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendors = [
            ['name' => 'Microsoft', 'email' => 'support@microsoft.com', 'is_vendor' => true, 'is_manufacturer' => true],
            ['name' => 'Amazon Web Services', 'email' => 'support@aws.amazon.com', 'is_vendor' => true, 'is_supplier' => true],
            ['name' => 'Google Cloud', 'email' => 'support@google.com', 'is_vendor' => true, 'is_supplier' => true],
            ['name' => 'Apple', 'email' => 'support@apple.com', 'is_vendor' => true, 'is_manufacturer' => true],
            ['name' => 'Lenovo', 'email' => 'support@lenovo.com', 'is_vendor' => true, 'is_manufacturer' => true],
            ['name' => 'Dell', 'email' => 'support@dell.com', 'is_vendor' => true, 'is_manufacturer' => true, 'is_supplier' => true],
            ['name' => 'HP', 'email' => 'support@hp.com', 'is_vendor' => true, 'is_manufacturer' => true],
            ['name' => 'Cisco', 'email' => 'support@cisco.com', 'is_vendor' => true, 'is_manufacturer' => true],
        ];

        foreach ($vendors as $vendor) {
            Vendor::updateOrCreate(['name' => $vendor['name']], $vendor);
        }
    }
}
