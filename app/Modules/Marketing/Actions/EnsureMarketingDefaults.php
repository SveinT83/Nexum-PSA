<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
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

        $this->ensureMarketingAiAgent();
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

    private function ensureMarketingAiAgent(): void
    {
        if (! Schema::hasTable('ai_agents') || ! Schema::hasTable('ai_providers')) {
            return;
        }

        $provider = AiProvider::query()
            ->where('status', 'active')
            ->orderByDesc('is_healthy')
            ->orderBy('name')
            ->first();

        $agent = AiAgent::query()->firstOrNew(['slug' => 'marketing-campaign-agent']);

        if (! $agent->exists) {
            $agent->fill([
                'ai_provider_id' => $provider?->id,
                'name' => 'Marketing Campaign Agent',
                'model' => $provider?->default_model,
                'instructions' => $this->marketingAiAgentInstructions(),
                'data_sources' => ['knowledge'],
                'allowed_tools' => ['knowledge.search'],
                'allowed_api_scopes' => [],
                'can_execute_actions' => false,
                'is_default' => false,
                'default_domains' => ['marketing'],
                'is_active' => (bool) $provider,
            ]);
            $agent->save();

            return;
        }

        $domains = $agent->default_domains ?? [];

        if (! in_array('marketing', $domains, true)) {
            $agent->default_domains = array_values([...$domains, 'marketing']);
        }

        if (! $agent->ai_provider_id && $provider) {
            $agent->ai_provider_id = $provider->id;
            $agent->model = $agent->model ?: $provider->default_model;
            $agent->is_active = true;
        }

        $agent->name = $agent->name ?: 'Marketing Campaign Agent';
        $agent->instructions = $agent->instructions ?: $this->marketingAiAgentInstructions();
        $agent->data_sources = $agent->data_sources ?? ['knowledge'];
        $agent->allowed_tools = $agent->allowed_tools ?? ['knowledge.search'];
        $agent->allowed_api_scopes = $agent->allowed_api_scopes ?? [];
        $agent->save();
    }

    private function marketingAiAgentInstructions(): string
    {
        return <<<'TEXT'
You help technicians plan Nexum PSA marketing campaigns and draft campaign emails.
Use the campaign, mailing list, existing email sequence, and provided template context.
Return practical, editable suggestions. Do not approve, send, or save campaigns.
Do not invent WordPress content or external links that were not provided as context.
TEXT;
    }
}
