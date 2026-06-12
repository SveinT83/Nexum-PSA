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
            PermissionSeeder::class,
            RoleSeeder::class,
            AdminUserSeeder::class,
            ClientSeeder::class,
            CategorySeeder::class,
            DocumentationTemplateSeeder::class,
            EmailTemplateSeeder::class,
            MarketingSeeder::class,
            SlaSeeder::class,
            LegalTermsSeeder::class,
            VendorSeeder::class,
            UnitSeeder::class,
            CostServicePackageSeeder::class,
            CommercialKnowledgeDocumentationSeeder::class,
            ContactKnowledgeDocumentationSeeder::class,
            EconomyKnowledgeDocumentationSeeder::class,
            NextcloudKnowledgeDocumentationSeeder::class,
            NotificationKnowledgeDocumentationSeeder::class,
            SalesKnowledgeDocumentationSeeder::class,
            StorageKnowledgeDocumentationSeeder::class,
            SystemKnowledgeDocumentationSeeder::class,
            TaskKnowledgeDocumentationSeeder::class,
            TicketKnowledgeDocumentationSeeder::class,
            UserManagementKnowledgeDocumentationSeeder::class,
        ]);
    }
}
