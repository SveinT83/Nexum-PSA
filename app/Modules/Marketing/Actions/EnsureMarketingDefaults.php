<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingConsentCategory;
use App\Modules\Marketing\Models\MarketingInterestTag;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EnsureMarketingDefaults
{
    public const PERMISSIONS = [
        'marketing.view',
        'marketing.list.manage',
        'marketing.campaign.create',
        'marketing.campaign.edit',
        'marketing.campaign.approve',
        'marketing.campaign.send',
        'marketing.analytics.view',
        'marketing.settings.manage',
    ];

    public function handle(): void
    {
        $this->ensurePermissions();

        foreach ($this->consentCategories() as $category) {
            MarketingConsentCategory::query()->updateOrCreate(
                ['key' => $category['key']],
                $category,
            );
        }

        foreach ($this->interestTags() as $tag) {
            MarketingInterestTag::query()->updateOrCreate(
                ['key' => $tag['key']],
                $tag,
            );
        }
    }

    private function consentCategories(): array
    {
        return [
            [
                'key' => 'newsletter',
                'name' => 'Newsletter',
                'description' => 'General newsletters, product news, and company updates.',
                'is_active' => true,
            ],
            [
                'key' => 'security',
                'name' => 'Security',
                'description' => 'Security awareness, audits, hardening, and incident prevention.',
                'is_active' => true,
            ],
            [
                'key' => 'websites',
                'name' => 'Websites',
                'description' => 'Website, hosting, and digital presence campaigns.',
                'is_active' => true,
            ],
            [
                'key' => 'cloud',
                'name' => 'Cloud',
                'description' => 'Microsoft 365, backup, collaboration, and cloud service campaigns.',
                'is_active' => true,
            ],
        ];
    }

    private function interestTags(): array
    {
        return [
            [
                'key' => 'opened-newsletter',
                'name' => 'Opened newsletter',
                'description' => 'Recipient opened a marketing newsletter.',
                'is_active' => true,
            ],
            [
                'key' => 'clicked-security',
                'name' => 'Clicked security content',
                'description' => 'Recipient clicked security-related campaign content.',
                'is_active' => true,
            ],
            [
                'key' => 'clicked-website',
                'name' => 'Clicked website content',
                'description' => 'Recipient clicked website-related campaign content.',
                'is_active' => true,
            ],
            [
                'key' => 'clicked-cloud',
                'name' => 'Clicked cloud content',
                'description' => 'Recipient clicked cloud or Microsoft 365 campaign content.',
                'is_active' => true,
            ],
        ];
    }

    private function ensurePermissions(): void
    {
        if (! class_exists(Permission::class) || ! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Role::query()->whereIn('name', ['Admin', 'Superuser'])->get() as $role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }
    }
}
