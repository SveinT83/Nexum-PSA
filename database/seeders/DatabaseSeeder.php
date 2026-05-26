<?php

namespace Database\Seeders;

use App\Models\Core\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            ClientPermissionsSeeder::class,
            ClientSeeder::class,
            CategorySeeder::class,
            DocumentationTemplateSeeder::class,
            EmailTemplateSeeder::class,
            SlaSeeder::class,
            LegalTermsSeeder::class,
            VendorSeeder::class,
            UnitSeeder::class,
            CostServicePackageSeeder::class,
            SalesKnowledgeDocumentationSeeder::class,
            StorageKnowledgeDocumentationSeeder::class,
            TaskKnowledgeDocumentationSeeder::class,
        ]);
    }
}
