<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiToolCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class AiIntegrationController extends Controller
{
    /**
     * Show provider and agent configuration for the AI integration.
     */
    public function index(): View
    {
        return view('integration::Tech.Admin.System.Integrations.ai.index', [
            'providers' => AiProvider::query()->withCount('agents')->orderBy('name')->get(),
            'agents' => AiAgent::query()->with(['provider', 'roles'])->orderByDesc('is_default')->orderBy('name')->get(),
            'roles' => Role::query()->orderBy('name')->get(),
            'providerOptions' => $this->providerOptions(),
            'dataSourceOptions' => $this->dataSourceOptions(),
            'toolOptions' => $this->toolOptions(),
            'toolGroups' => app(AiToolCatalog::class)->grouped(),
            'apiScopeOptions' => $this->apiScopeOptions(),
            'domainOptions' => app(AiAgentResolver::class)->domainOptions(),
        ]);
    }

    /**
     * Store a new AI provider. API keys are optional for local providers such as Ollama.
     */
    public function storeProvider(Request $request): RedirectResponse
    {
        $data = $this->validateProvider($request);

        $provider = new AiProvider($this->providerAttributes($data));
        $provider->setSecret('api_key', $data['api_key'] ?? null);
        $provider->save();

        return back()->with('success', 'AI provider saved.');
    }

    /**
     * Update an existing AI provider without overwriting its secret when blank.
     */
    public function updateProvider(Request $request, AiProvider $provider): RedirectResponse
    {
        $data = $this->validateProvider($request);

        $provider->fill($this->providerAttributes($data));
        $provider->setSecret('api_key', $data['api_key'] ?? null);
        $provider->save();

        return back()->with('success', 'AI provider updated.');
    }

    /**
     * Remove a provider. Existing agents are retained and become provider-less.
     */
    public function destroyProvider(AiProvider $provider): RedirectResponse
    {
        $provider->delete();

        return back()->with('success', 'AI provider deleted.');
    }

    /**
     * Store a new agent and assign role access.
     */
    public function storeAgent(Request $request): RedirectResponse
    {
        $data = $this->validateAgent($request);

        DB::transaction(function () use ($data) {
            $agent = AiAgent::create($this->agentAttributes($data));
            $agent->roles()->sync($data['role_ids'] ?? []);

            $this->enforceSingleDefaultAgent($agent);
            $this->enforceSingleDomainDefaultAgent($agent);
        });

        return back()->with('success', 'AI agent saved.');
    }

    /**
     * Update an agent, including its policy controls and role access.
     */
    public function updateAgent(Request $request, AiAgent $agent): RedirectResponse
    {
        $data = $this->validateAgent($request, $agent);

        DB::transaction(function () use ($agent, $data) {
            $agent->fill($this->agentAttributes($data));
            $agent->save();
            $agent->roles()->sync($data['role_ids'] ?? []);

            $this->enforceSingleDefaultAgent($agent);
            $this->enforceSingleDomainDefaultAgent($agent);
        });

        return back()->with('success', 'AI agent updated.');
    }

    /**
     * Delete an agent configuration.
     */
    public function destroyAgent(AiAgent $agent): RedirectResponse
    {
        $agent->delete();

        return back()->with('success', 'AI agent deleted.');
    }

    private function validateProvider(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'provider_key' => ['required', 'string', Rule::in(array_keys($this->providerOptions()))],
            'base_url' => 'nullable|url|max:2048',
            'default_model' => 'nullable|string|max:255',
            'embedding_model' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:4096',
            'status' => 'required|string|in:active,disabled',
            'api_version' => 'nullable|string|max:100',
            'organization_id' => 'nullable|string|max:255',
        ]);
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
            ],
            'is_healthy' => false,
            'last_error' => null,
        ];
    }

    private function validateAgent(Request $request, ?AiAgent $agent = null): array
    {
        return $request->validate([
            'ai_provider_id' => 'nullable|exists:ai_providers,id',
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('ai_agents', 'slug')->ignore($agent?->id),
            ],
            'model' => 'nullable|string|max:255',
            'instructions' => 'required|string|max:20000',
            'data_sources' => 'nullable|array',
            'data_sources.*' => ['string', Rule::in(array_keys($this->dataSourceOptions()))],
            'allowed_tools' => 'nullable|array',
            'allowed_tools.*' => ['string', Rule::in(app(AiToolCatalog::class)->acceptedKeys())],
            'allowed_api_scopes' => 'nullable|array',
            'allowed_api_scopes.*' => ['string', Rule::in(array_keys($this->apiScopeOptions()))],
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'integer|exists:roles,id',
            'can_execute_actions' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'default_domains' => 'nullable|array',
            'default_domains.*' => ['string', Rule::in(array_keys(app(AiAgentResolver::class)->domainOptions()))],
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function agentAttributes(array $data): array
    {
        $slug = filled($data['slug'] ?? null) ? Str::slug($data['slug']) : Str::slug($data['name']);

        return [
            'ai_provider_id' => $data['ai_provider_id'] ?? null,
            'name' => $data['name'],
            'slug' => $slug,
            'model' => $data['model'] ?? null,
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

    private function enforceSingleDefaultAgent(AiAgent $agent): void
    {
        if (! $agent->is_default) {
            return;
        }

        AiAgent::query()
            ->whereKeyNot($agent->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
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
        return [
            'tickets.read' => 'Read tickets',
            'tickets.write' => 'Update tickets',
            'knowledge.read' => 'Read knowledge',
            'knowledge.write' => 'Update knowledge',
            'clients.read' => 'Read clients',
            'assets.read' => 'Read assets',
        ];
    }

    private function normalizeApiScopes(array $scopes, bool $canExecuteActions): array
    {
        return collect($scopes)
            ->when(! $canExecuteActions, fn ($items) => $items->reject(fn ($scope) => Str::endsWith($scope, '.write')))
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
}
