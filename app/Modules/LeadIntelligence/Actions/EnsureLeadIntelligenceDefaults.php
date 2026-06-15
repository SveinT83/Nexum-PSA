<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use Illuminate\Support\Facades\Schema;

class EnsureLeadIntelligenceDefaults
{
    public function handle(): void
    {
        $this->ensureAiAgent();
    }

    private function ensureAiAgent(): void
    {
        if (! Schema::hasTable('ai_agents') || ! Schema::hasTable('ai_providers')) {
            return;
        }

        $provider = AiProvider::query()
            ->where('status', 'active')
            ->orderByDesc('is_healthy')
            ->orderBy('name')
            ->first();

        $agent = AiAgent::query()->firstOrNew(['slug' => 'lead-intelligence-agent']);

        if (! $agent->exists) {
            $agent->fill([
                'ai_provider_id' => $provider?->id,
                'name' => 'Lead Intelligence Agent',
                'model' => $provider?->default_model,
                'instructions' => $this->instructions(),
                'data_sources' => ['knowledge'],
                'allowed_tools' => ['knowledge.search'],
                'allowed_api_scopes' => [],
                'can_execute_actions' => false,
                'is_default' => false,
                'default_domains' => ['lead_intelligence'],
                'is_active' => (bool) $provider,
            ]);
            $agent->save();

            return;
        }

        $domains = $agent->default_domains ?? [];

        if (! in_array('lead_intelligence', $domains, true)) {
            $agent->default_domains = array_values([...$domains, 'lead_intelligence']);
        }

        if (! $agent->ai_provider_id && $provider) {
            $agent->ai_provider_id = $provider->id;
            $agent->model = $agent->model ?: $provider->default_model;
            $agent->is_active = true;
        }

        $agent->name = $agent->name ?: 'Lead Intelligence Agent';
        $agent->instructions = $agent->instructions ?: $this->instructions();
        $agent->data_sources = $agent->data_sources ?? ['knowledge'];
        $agent->allowed_tools = $agent->allowed_tools ?? ['knowledge.search'];
        $agent->allowed_api_scopes = $agent->allowed_api_scopes ?? [];
        $agent->save();
    }

    private function instructions(): string
    {
        return <<<'TEXT'
You help technicians define Nexum PSA Lead Intelligence segments for Norwegian B2B prospecting.
Return practical, editable segment settings. Do not claim that you crawled websites, queried BRREG, created leads, or added marketing recipients.
TEXT;
    }
}

