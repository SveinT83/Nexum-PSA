<?php

namespace App\Modules\Integration\Livewire\Tech\Admin\System\Integrations;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiSystemSetting;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiProviderModelCatalog;
use App\Modules\Integration\Services\AiToolCatalog;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class AiSettings extends Component
{
    public ?string $editingProviderId = null;

    public ?int $editingAgentId = null;

    public bool $providerModalOpen = false;

    public bool $agentModalOpen = false;

    public bool $deleteModalOpen = false;

    public ?string $pendingDeleteType = null;

    public string|int|null $pendingDeleteId = null;

    public ?string $pendingDeleteName = null;

    public array $providerForm = [];

    public array $agentForm = [];

    public array $retentionForm = [];

    public array $modelOptions = [];

    public function mount(): void
    {
        $this->resetProviderForm();
        $this->resetAgentForm();
        $this->loadRetentionForm();
    }

    public function updatedProviderFormProviderKey(): void
    {
        $this->providerForm['base_url'] = $this->providerRequiresBaseUrl() ? ($this->providerForm['base_url'] ?? '') : '';
        $this->modelOptions = app(AiProviderModelCatalog::class)->defaultsFor($this->providerForm['provider_key'] ?? '');
    }

    public function resetProviderForm(): void
    {
        $this->editingProviderId = null;
        $this->providerModalOpen = false;
        $this->providerForm = [
            'name' => '',
            'provider_key' => 'openai',
            'base_url' => '',
            'default_model' => '',
            'embedding_model' => '',
            'api_key' => '',
            'status' => 'active',
            'api_version' => '',
            'organization_id' => '',
        ];
        $this->modelOptions = app(AiProviderModelCatalog::class)->defaultsFor('openai');
    }

    public function openCreateProvider(): void
    {
        $this->resetProviderForm();
        $this->providerModalOpen = true;
    }

    public function closeProviderModal(): void
    {
        $this->resetProviderForm();
    }

    public function editProvider(string $providerId): void
    {
        $provider = AiProvider::findOrFail($providerId);
        $this->editingProviderId = $provider->id;
        $this->providerModalOpen = true;
        $this->providerForm = [
            'name' => $provider->name,
            'provider_key' => $provider->provider_key,
            'base_url' => $provider->base_url,
            'default_model' => $provider->default_model,
            'embedding_model' => $provider->embedding_model,
            'api_key' => '',
            'status' => $provider->status,
            'api_version' => $provider->config['api_version'] ?? '',
            'organization_id' => $provider->config['organization_id'] ?? '',
        ];
        $this->modelOptions = $provider->config['available_models']
            ?? app(AiProviderModelCatalog::class)->defaultsFor($provider->provider_key);
    }

    public function fetchModels(AiProviderModelCatalog $catalog): void
    {
        $data = $this->validate($this->providerRules())['providerForm'];
        $provider = $this->providerFromForm($data);
        $apiKey = $data['api_key'] ?: ($this->editingProviderId ? AiProvider::find($this->editingProviderId)?->getSecret('api_key') : null);

        try {
            $this->modelOptions = $catalog->fetch($provider, $apiKey);
        } catch (\Throwable $exception) {
            $this->addError('providerForm.default_model', $exception->getMessage());

            return;
        }

        if ($this->modelOptions !== [] && ! in_array($this->providerForm['default_model'], $this->modelOptions, true)) {
            $this->providerForm['default_model'] = $this->modelOptions[0];
        }
    }

    public function saveProvider(): void
    {
        $data = $this->validate($this->providerRules())['providerForm'];
        $provider = $this->editingProviderId ? AiProvider::findOrFail($this->editingProviderId) : new AiProvider;
        $provider->fill($this->providerAttributes($data));
        $provider->setSecret('api_key', $data['api_key'] ?? null);
        $provider->save();

        session()->flash('success', 'AI provider saved.');
        $this->resetProviderForm();
    }

    public function deleteProvider(string $providerId): void
    {
        AiProvider::findOrFail($providerId)->delete();
        session()->flash('success', 'AI provider deleted.');
        $this->resetProviderForm();
        $this->resetDeleteConfirmation();
    }

    public function resetAgentForm(): void
    {
        $this->editingAgentId = null;
        $this->agentModalOpen = false;
        $this->agentForm = [
            'ai_provider_id' => '',
            'name' => 'Knowledge Assistant',
            'slug' => '',
            'model' => '',
            'instructions' => 'Answer using the allowed Nexum PSA data sources. Prefer Knowledge articles when available, cite the records used, and do not perform actions unless this agent explicitly has API action permission.',
            'data_sources' => ['knowledge'],
            'allowed_tools' => ['knowledge.search'],
            'allowed_api_scopes' => [],
            'role_ids' => [],
            'can_execute_actions' => false,
            'is_default' => false,
            'default_domains' => [],
            'is_active' => true,
        ];
    }

    public function loadRetentionForm(): void
    {
        $settings = AiSystemSetting::current();

        $this->retentionForm = [
            'context_message_limit' => $settings->context_message_limit,
            'chat_retention_days' => $settings->chat_retention_days,
            'delete_empty_chats_after_days' => $settings->delete_empty_chats_after_days,
            'delete_failed_pending_after_hours' => $settings->delete_failed_pending_after_hours,
            'cleanup_enabled' => $settings->cleanup_enabled,
        ];
    }

    public function saveRetentionSettings(): void
    {
        $data = $this->validate($this->retentionRules())['retentionForm'];

        AiSystemSetting::current()
            ->forceFill([
                'context_message_limit' => (int) $data['context_message_limit'],
                'chat_retention_days' => (int) $data['chat_retention_days'],
                'delete_empty_chats_after_days' => (int) $data['delete_empty_chats_after_days'],
                'delete_failed_pending_after_hours' => (int) $data['delete_failed_pending_after_hours'],
                'cleanup_enabled' => (bool) ($data['cleanup_enabled'] ?? false),
            ])
            ->save();

        session()->flash('success', 'AI retention settings saved.');
        $this->loadRetentionForm();
    }

    public function openCreateAgent(): void
    {
        $this->resetAgentForm();
        $this->agentModalOpen = true;
    }

    public function closeAgentModal(): void
    {
        $this->resetAgentForm();
    }

    public function editAgent(int $agentId): void
    {
        $agent = AiAgent::with('roles')->findOrFail($agentId);
        $this->editingAgentId = $agent->id;
        $this->agentModalOpen = true;
        $this->agentForm = [
            'ai_provider_id' => $agent->ai_provider_id ?? '',
            'name' => $agent->name,
            'slug' => $agent->slug,
            'model' => $agent->model ?? '',
            'instructions' => $agent->instructions,
            'data_sources' => $agent->data_sources ?? ['knowledge'],
            'allowed_tools' => app(AiToolCatalog::class)->normalize($agent->allowed_tools ?? ['knowledge.search'], $agent->can_execute_actions),
            'allowed_api_scopes' => $agent->allowed_api_scopes ?? [],
            'role_ids' => $agent->roles->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'can_execute_actions' => $agent->can_execute_actions,
            'is_default' => $agent->is_default,
            'default_domains' => $agent->default_domains ?? [],
            'is_active' => $agent->is_active,
        ];
    }

    public function saveAgent(): void
    {
        $data = $this->validate($this->agentRules())['agentForm'];

        DB::transaction(function () use ($data) {
            $agent = $this->editingAgentId ? AiAgent::findOrFail($this->editingAgentId) : new AiAgent;
            $agent->fill($this->agentAttributes($data));
            $agent->save();
            $agent->roles()->sync($data['role_ids'] ?? []);

            if ($agent->is_default) {
                AiAgent::query()->whereKeyNot($agent->id)->update(['is_default' => false]);
            }

            $this->enforceSingleDomainDefaultAgent($agent);
        });

        session()->flash('success', 'AI agent saved.');
        $this->resetAgentForm();
    }

    public function deleteAgent(int $agentId): void
    {
        AiAgent::findOrFail($agentId)->delete();
        session()->flash('success', 'AI agent deleted.');
        $this->resetAgentForm();
        $this->resetDeleteConfirmation();
    }

    public function confirmDeleteProvider(): void
    {
        if (! $this->editingProviderId) {
            return;
        }

        $provider = AiProvider::findOrFail($this->editingProviderId);
        $this->pendingDeleteType = 'provider';
        $this->pendingDeleteId = $provider->id;
        $this->pendingDeleteName = $provider->name;
        $this->deleteModalOpen = true;
    }

    public function confirmDeleteAgent(): void
    {
        if (! $this->editingAgentId) {
            return;
        }

        $agent = AiAgent::findOrFail($this->editingAgentId);
        $this->pendingDeleteType = 'agent';
        $this->pendingDeleteId = $agent->id;
        $this->pendingDeleteName = $agent->name;
        $this->deleteModalOpen = true;
    }

    public function cancelDelete(): void
    {
        $this->resetDeleteConfirmation();
    }

    public function deleteConfirmed(): void
    {
        if ($this->pendingDeleteType === 'provider' && $this->pendingDeleteId) {
            $this->deleteProvider((string) $this->pendingDeleteId);

            return;
        }

        if ($this->pendingDeleteType === 'agent' && $this->pendingDeleteId) {
            $this->deleteAgent((int) $this->pendingDeleteId);
        }
    }

    private function resetDeleteConfirmation(): void
    {
        $this->deleteModalOpen = false;
        $this->pendingDeleteType = null;
        $this->pendingDeleteId = null;
        $this->pendingDeleteName = null;
    }

    public function providerRequiresBaseUrl(): bool
    {
        return in_array($this->providerForm['provider_key'] ?? '', ['azure_openai', 'ollama', 'custom_openai_compatible'], true);
    }

    public function render()
    {
        return view('integration::Livewire.Tech.Admin.System.Integrations.ai-settings', [
            'providers' => AiProvider::query()->withCount('agents')->orderBy('name')->get(),
            'agents' => AiAgent::query()->with(['provider', 'roles'])->orderByDesc('is_default')->orderBy('name')->get(),
            'roles' => Role::query()->orderBy('name')->get(),
            'providerOptions' => $this->providerOptions(),
            'dataSourceOptions' => $this->dataSourceOptions(),
            'toolOptions' => $this->toolOptions(),
            'toolGroups' => app(AiToolCatalog::class)->grouped(),
            'apiScopeOptions' => $this->apiScopeOptions(),
            'domainOptions' => app(AiAgentResolver::class)->domainOptions(),
            'aiSystemSettings' => AiSystemSetting::current(),
        ]);
    }

    private function retentionRules(): array
    {
        return [
            'retentionForm.context_message_limit' => 'required|integer|min:1|max:100',
            'retentionForm.chat_retention_days' => 'required|integer|min:1|max:3650',
            'retentionForm.delete_empty_chats_after_days' => 'required|integer|min:1|max:365',
            'retentionForm.delete_failed_pending_after_hours' => 'required|integer|min:1|max:168',
            'retentionForm.cleanup_enabled' => 'boolean',
        ];
    }

    private function providerRules(): array
    {
        return [
            'providerForm.name' => 'required|string|max:255',
            'providerForm.provider_key' => ['required', 'string', Rule::in(array_keys($this->providerOptions()))],
            'providerForm.base_url' => [$this->providerRequiresBaseUrl() ? 'required' : 'nullable', 'url', 'max:2048'],
            'providerForm.default_model' => 'nullable|string|max:255',
            'providerForm.embedding_model' => 'nullable|string|max:255',
            'providerForm.api_key' => 'nullable|string|max:4096',
            'providerForm.status' => 'required|string|in:active,disabled',
            'providerForm.api_version' => 'nullable|string|max:100',
            'providerForm.organization_id' => 'nullable|string|max:255',
        ];
    }

    private function agentRules(): array
    {
        return [
            'agentForm.ai_provider_id' => 'nullable|exists:ai_providers,id',
            'agentForm.name' => 'required|string|max:255',
            'agentForm.slug' => ['nullable', 'string', 'max:255', Rule::unique('ai_agents', 'slug')->ignore($this->editingAgentId)],
            'agentForm.model' => 'nullable|string|max:255',
            'agentForm.instructions' => 'required|string|max:20000',
            'agentForm.data_sources' => 'nullable|array',
            'agentForm.data_sources.*' => ['string', Rule::in(array_keys($this->dataSourceOptions()))],
            'agentForm.allowed_tools' => 'nullable|array',
            'agentForm.allowed_tools.*' => ['string', Rule::in(app(AiToolCatalog::class)->acceptedKeys())],
            'agentForm.allowed_api_scopes' => 'nullable|array',
            'agentForm.allowed_api_scopes.*' => ['string', Rule::in(array_keys($this->apiScopeOptions()))],
            'agentForm.role_ids' => 'nullable|array',
            'agentForm.role_ids.*' => 'integer|exists:roles,id',
            'agentForm.can_execute_actions' => 'boolean',
            'agentForm.is_default' => 'boolean',
            'agentForm.default_domains' => 'nullable|array',
            'agentForm.default_domains.*' => ['string', Rule::in(array_keys(app(AiAgentResolver::class)->domainOptions()))],
            'agentForm.is_active' => 'boolean',
        ];
    }

    private function providerFromForm(array $data): AiProvider
    {
        return new AiProvider($this->providerAttributes($data));
    }

    private function providerAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'provider_key' => $data['provider_key'],
            'base_url' => filled($data['base_url'] ?? null) ? rtrim($data['base_url'], '/') : null,
            'default_model' => $data['default_model'] ?? null,
            'embedding_model' => $data['embedding_model'] ?? null,
            'status' => $data['status'],
            'config' => [
                'api_version' => $data['api_version'] ?? null,
                'organization_id' => $data['organization_id'] ?? null,
                'available_models' => $this->modelOptions,
            ],
            'is_healthy' => false,
            'last_error' => null,
        ];
    }

    private function agentAttributes(array $data): array
    {
        return [
            'ai_provider_id' => $data['ai_provider_id'] ?: null,
            'name' => $data['name'],
            'slug' => filled($data['slug'] ?? null) ? Str::slug($data['slug']) : Str::slug($data['name']),
            'model' => $data['model'] ?: null,
            'instructions' => $data['instructions'],
            'data_sources' => $data['data_sources'] ?? ['knowledge'],
            'allowed_tools' => app(AiToolCatalog::class)->normalize($data['allowed_tools'] ?? ['knowledge.search'], (bool) ($data['can_execute_actions'] ?? false)),
            'allowed_api_scopes' => $this->normalizeApiScopes($data['allowed_api_scopes'] ?? [], (bool) ($data['can_execute_actions'] ?? false)),
            'can_execute_actions' => (bool) ($data['can_execute_actions'] ?? false),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'default_domains' => $this->normalizeDefaultDomains($data['default_domains'] ?? []),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function providerOptions(): array
    {
        return [
            'openai' => 'OpenAI',
            'azure_openai' => 'Azure OpenAI',
            'anthropic' => 'Anthropic Claude',
            'google_gemini' => 'Google Gemini',
            'mistral' => 'Mistral AI',
            'cohere' => 'Cohere',
            'openrouter' => 'OpenRouter',
            'ollama' => 'Ollama',
            'custom_openai_compatible' => 'Custom OpenAI-compatible',
        ];
    }

    private function dataSourceOptions(): array
    {
        return [
            'knowledge' => 'Knowledge',
            'active_tickets' => 'Active tickets',
            'clients' => 'Clients',
            'assets' => 'Assets',
            'email' => 'Email metadata',
            'documentation' => 'Documentation',
        ];
    }

    private function toolOptions(): array
    {
        return app(AiToolCatalog::class)->options();
    }

    private function apiScopeOptions(): array
    {
        return collect(app(ApiAbilityCatalog::class)->all())
            ->mapWithKeys(fn (array $ability, string $key) => [$key => $ability['label']])
            ->all();
    }

    private function normalizeApiScopes(array $scopes, bool $canExecuteActions): array
    {
        return collect($scopes)
            ->when(! $canExecuteActions, fn ($items) => $items->filter(fn ($scope) => Str::endsWith($scope, '.read')))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeDefaultDomains(array $domains): array
    {
        $validDomains = array_keys(app(AiAgentResolver::class)->domainOptions());

        return collect($domains)
            ->filter(fn ($domain) => in_array($domain, $validDomains, true))
            ->unique()
            ->values()
            ->all();
    }

    private function enforceSingleDomainDefaultAgent(AiAgent $agent): void
    {
        $domains = $agent->default_domains ?? [];

        if ($domains === []) {
            return;
        }

        AiAgent::query()
            ->whereKeyNot($agent->id)
            ->get()
            ->each(function (AiAgent $otherAgent) use ($domains) {
                $remainingDomains = collect($otherAgent->default_domains ?? [])
                    ->reject(fn ($domain) => in_array($domain, $domains, true))
                    ->values()
                    ->all();

                if ($remainingDomains !== ($otherAgent->default_domains ?? [])) {
                    $otherAgent->forceFill(['default_domains' => $remainingDomains])->save();
                }
            });
    }
}
