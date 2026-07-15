<?php

namespace App\Modules\Signal\Actions;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EnsureSignalDefaults
{
    public const PERMISSIONS = [
        'signal.view',
        'signal.rule.manage',
        'signal.webhook.manage',
        'signal.action.execute',
    ];

    public function handle(): void
    {
        $this->ensurePermissions();
        $this->ensureSignalAiAgent();
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

    private function ensureSignalAiAgent(): void
    {
        if (! Schema::hasTable('ai_agents') || ! Schema::hasTable('ai_providers')) {
            return;
        }

        $provider = AiProvider::query()
            ->where('status', 'active')
            ->orderByDesc('is_healthy')
            ->orderBy('name')
            ->first();

        $agent = AiAgent::query()->firstOrNew(['slug' => 'signal-classification-agent']);

        if (! $agent->exists) {
            $agent->fill([
                'ai_provider_id' => $provider?->id,
                'name' => 'Signal Classification Agent',
                'model' => $provider?->default_model,
                'instructions' => $this->instructions(),
                'data_sources' => [],
                'allowed_tools' => ['context.summarize'],
                'allowed_api_scopes' => [],
                'can_execute_actions' => false,
                'is_default' => false,
                'default_domains' => ['signal'],
                'is_active' => (bool) $provider,
            ]);
            $agent->save();

            return;
        }

        $domains = $agent->default_domains ?? [];

        if (! in_array('signal', $domains, true)) {
            $agent->default_domains = array_values([...$domains, 'signal']);
        }

        if (! $agent->ai_provider_id && $provider) {
            $agent->ai_provider_id = $provider->id;
            $agent->model = $agent->model ?: $provider->default_model;
            $agent->is_active = true;
        }

        $agent->name = $agent->name ?: 'Signal Classification Agent';
        $agent->instructions = $agent->instructions ?: $this->instructions();
        $agent->data_sources = $agent->data_sources ?? [];
        $agent->allowed_tools = $agent->allowed_tools ?? ['context.summarize'];
        $agent->allowed_api_scopes = $agent->allowed_api_scopes ?? [];
        $agent->can_execute_actions = false;
        $agent->save();
    }

    private function instructions(): string
    {
        return <<<'TEXT'
You classify Nexum PSA source payloads into normalized Signal events.
Return JSON only. Do not execute actions, create tickets, change contacts, or invent missing facts.
TEXT;
    }
}
