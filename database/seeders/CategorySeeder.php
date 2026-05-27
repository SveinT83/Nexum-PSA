<?php

namespace Database\Seeders;

use App\Modules\Taxonomy\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'Asset',
            'Client',
            'Risk Management',
            'Domains',
            'LAN',
            'Internet/WAN',
            'SSL Sertificates',
            'Application',
            'Backup',
            'Email',
            'File Sharing',
            'Printing',
            'Remote Access',
            'Virtualization',
            'Voice/PBX',
            'Wireless',
            'Contacts',
            'Licensing',
            'Locations',
            'Vendors',
            'Suppliers',
            'Files',
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['slug' => Str::slug($category)],
                ['name' => $category, 'is_active' => true]
            );
        }
    }
}
