<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CostServicePackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ms = \App\Models\Doc\Vendor::where('name', 'Microsoft')->first();
        $lenovo = \App\Models\Doc\Vendor::where('name', 'Lenovo')->first();
        $stk = \App\Modules\Commercial\Models\Economy\Units::where('name', 'Stykk')->first();
        $mnd = \App\Modules\Commercial\Models\Economy\Units::where('name', 'Måned')->first();
        $bruker = \App\Modules\Commercial\Models\Economy\Units::where('name', 'Bruker')->first();

        $admin = \App\Models\Core\User::where('email', 'admin@tdpsa.com')->first();
        $adminId = $admin ? $admin->id : 1;

        // 1. Seed Costs
        $costs = [
            [
                'name' => 'Microsoft 365 Business Premium Cost',
                'cost' => 180.00,
                'unitId' => $bruker->id,
                'recurrence' => 'month',
                'vendor_id' => $ms->id,
                'note' => 'Standard M365 BP cost',
                'created_by_user_id' => $adminId,
                'updated_by_user_id' => $adminId,
            ],
            [
                'name' => 'Lenovo ThinkPad X1 Carbon Cost',
                'cost' => 15000.00,
                'unitId' => $stk->id,
                'recurrence' => 'none',
                'vendor_id' => $lenovo->id,
                'note' => 'High-end laptop cost',
                'created_by_user_id' => $adminId,
                'updated_by_user_id' => $adminId,
            ],
            [
                'name' => 'Cloud Storage Base Cost',
                'cost' => 500.00,
                'unitId' => $mnd->id,
                'recurrence' => 'month',
                'vendor_id' => $ms->id,
                'note' => 'Cloud storage base fee cost',
                'created_by_user_id' => $adminId,
                'updated_by_user_id' => $adminId,
            ]
        ];

        $costModels = [];
        foreach ($costs as $costData) {
            $costModels[] = \App\Modules\Commercial\Models\Cost::updateOrCreate(['name' => $costData['name']], $costData);
        }

        // 2. Seed Services
        $services = [
            [
                'sku' => 'M365-BP',
                'name' => 'Microsoft 365 Business Premium',
                'unitId' => $bruker->id,
                'status' => 'Active',
                'billing_cycle' => 'monthly',
                'price_ex_vat' => 220.00,
                'short_description' => 'Microsoft 365 Business Premium subscription',
                'created_by_user_id' => $adminId,
                'updated_by_user_id' => $adminId,
            ],
            [
                'sku' => 'HW-LAPTOP-X1',
                'name' => 'Lenovo ThinkPad X1 Carbon',
                'unitId' => $stk->id,
                'status' => 'Active',
                'billing_cycle' => 'one_time',
                'price_ex_vat' => 18500.00,
                'one_time_fee' => 18500.00,
                'short_description' => 'High-end business laptop',
                'created_by_user_id' => $adminId,
                'updated_by_user_id' => $adminId,
            ],
            [
                'sku' => 'SVC-SUPPORT-STD',
                'name' => 'Standard Support Agreement',
                'unitId' => $mnd->id,
                'status' => 'Active',
                'billing_cycle' => 'monthly',
                'price_ex_vat' => 1500.00,
                'short_description' => 'Basic IT support agreement',
                'created_by_user_id' => $adminId,
                'updated_by_user_id' => $adminId,
            ]
        ];

        $serviceModels = [];
        foreach ($services as $serviceData) {
            $service = \App\Modules\Commercial\Models\Services\Services::updateOrCreate(['sku' => $serviceData['sku']], $serviceData);
            $serviceModels[] = $service;
        }

        // 3. Seed Packages
        $packages = [
            [
                'name' => 'Standard Modern Workplace',
                'description' => 'Complete package with laptop and M365',
                'sales_price_user' => 2500.00,
                'created_by_user_id' => $adminId,
            ],
            [
                'name' => 'Support Only Package',
                'description' => 'Monthly support package',
                'sales_price_client' => 5000.00,
                'created_by_user_id' => $adminId,
            ]
        ];

        foreach ($packages as $packageData) {
            $package = \App\Modules\Commercial\Models\Packages\Package::updateOrCreate(['name' => $packageData['name']], $packageData);

            if ($package->name === 'Standard Modern Workplace') {
                $serviceIds = \App\Modules\Commercial\Models\Services\Services::whereIn('sku', ['M365-BP', 'HW-LAPTOP-X1'])->pluck('id');
                $package->services()->sync($serviceIds);
            } elseif ($package->name === 'Support Only Package') {
                $serviceId = \App\Modules\Commercial\Models\Services\Services::where('sku', 'SVC-SUPPORT-STD')->value('id');
                $package->services()->sync([$serviceId]);
            }
        }
    }
}
