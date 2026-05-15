<div>
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-3">
        {{-- Provider configuration --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between gap-3">
                    <h2 class="h5 mb-0">Providers</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-light border">{{ $providers->count() }} configured</span>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="openCreateProvider">
                            <i class="bi bi-plus-lg"></i>
                            Add provider
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Provider</th>
                                    <th>Model</th>
                                    <th>Status</th>
                                    <th>Agents</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($providers as $provider)
                                    <tr>
                                        <td class="fw-semibold">{{ $provider->name }}</td>
                                        <td>{{ $providerOptions[$provider->provider_key] ?? $provider->provider_key }}</td>
                                        <td>
                                            <div>{{ $provider->default_model ?: '-' }}</div>
                                            @if($provider->base_url)
                                                <div class="small text-muted text-truncate" style="max-width: 20rem;">{{ $provider->base_url }}</div>
                                            @endif
                                        </td>
                                        <td><span class="badge {{ $provider->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($provider->status) }}</span></td>
                                        <td>{{ $provider->agents_count }}</td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editProvider('{{ $provider->id }}')">Edit</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-muted">No AI providers configured yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tool policy overview --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between gap-3">
                    <div>
                        <h2 class="h5 mb-0">Tools</h2>
                        <div class="small text-muted">Read tools can only inspect context. Write tools require API actions and matching scopes.</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#aiToolsOverview" aria-expanded="false" aria-controls="aiToolsOverview">
                        <i class="bi bi-chevron-down"></i>
                        Overview
                    </button>
                </div>
                <div id="aiToolsOverview" class="collapse">
                    <div class="card-body">
                        <div class="row g-3">
                            @foreach($toolGroups as $mode => $tools)
                                <div class="col-md-6">
                                    <div class="border rounded-2 p-3 h-100">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge {{ $mode === 'read' ? 'text-bg-info' : 'text-bg-warning' }}">{{ ucfirst($mode) }}</span>
                                            <span class="fw-semibold">{{ $mode === 'read' ? 'Read-only tools' : 'Action tools' }}</span>
                                        </div>
                                        <div class="vstack gap-2">
                                            @foreach($tools as $key => $tool)
                                                <div>
                                                    <div class="fw-semibold small">{{ $tool['label'] }}</div>
                                                    <div class="small text-muted">{{ $tool['description'] }}</div>
                                                    <div class="small text-muted">
                                                        <code>{{ $key }}</code>
                                                        @if($tool['requires_scope'])
                                                            <span class="ms-2">Scope: <code>{{ $tool['requires_scope'] }}</code></span>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- AI memory and retention settings --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between gap-3">
                    <div>
                        <h2 class="h5 mb-0">Retention & Memory</h2>
                        <div class="small text-muted">Control how much chat history is sent to providers and when stored chats are cleaned up.</div>
                    </div>
                    @if($aiSystemSettings->last_cleanup_at)
                        <span class="badge text-bg-light border">Last cleanup {{ $aiSystemSettings->last_cleanup_at->diffForHumans() }}</span>
                    @endif
                </div>
                <div class="card-body">
                    <form wire:submit.prevent="saveRetentionSettings">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label for="context_message_limit" class="form-label">Context messages</label>
                                <input id="context_message_limit" type="number" min="1" max="100" wire:model="retentionForm.context_message_limit" class="form-control @error('retentionForm.context_message_limit') is-invalid @enderror">
                                @error('retentionForm.context_message_limit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2">
                                <label for="chat_retention_days" class="form-label">Keep chats days</label>
                                <input id="chat_retention_days" type="number" min="1" max="3650" wire:model="retentionForm.chat_retention_days" class="form-control @error('retentionForm.chat_retention_days') is-invalid @enderror">
                                @error('retentionForm.chat_retention_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2">
                                <label for="delete_empty_chats_after_days" class="form-label">Empty chats days</label>
                                <input id="delete_empty_chats_after_days" type="number" min="1" max="365" wire:model="retentionForm.delete_empty_chats_after_days" class="form-control @error('retentionForm.delete_empty_chats_after_days') is-invalid @enderror">
                                @error('retentionForm.delete_empty_chats_after_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2">
                                <label for="delete_failed_pending_after_hours" class="form-label">Pending hours</label>
                                <input id="delete_failed_pending_after_hours" type="number" min="1" max="168" wire:model="retentionForm.delete_failed_pending_after_hours" class="form-control @error('retentionForm.delete_failed_pending_after_hours') is-invalid @enderror">
                                @error('retentionForm.delete_failed_pending_after_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" wire:model="retentionForm.cleanup_enabled" id="cleanup_enabled">
                                    <label class="form-check-label" for="cleanup_enabled">Weekly cleanup</label>
                                </div>
                                <div class="small text-muted">Runs through queue.</div>
                            </div>
                            <div class="col-md-2 text-md-end">
                                <button type="submit" class="btn btn-primary">Save settings</button>
                            </div>
                        </div>
                    </form>

                    @if($aiSystemSettings->last_cleanup_summary)
                        <div class="small text-muted mt-3">
                            Last summary:
                            old chats {{ $aiSystemSettings->last_cleanup_summary['deleted_old_chats'] ?? 0 }},
                            empty chats {{ $aiSystemSettings->last_cleanup_summary['deleted_empty_chats'] ?? 0 }},
                            expired pending {{ $aiSystemSettings->last_cleanup_summary['expired_pending_messages'] ?? 0 }}.
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Agent configuration --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between gap-3">
                    <h2 class="h5 mb-0">Agents</h2>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-light border">{{ $agents->count() }} configured</span>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="openCreateAgent">
                            <i class="bi bi-plus-lg"></i>
                            Add agent
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Provider</th>
                                    <th>Access</th>
                                    <th>Roles</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($agents as $agent)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $agent->name }}</div>
                                            <div class="small text-muted">{{ $agent->slug }}</div>
                                        </td>
                                        <td>{{ $agent->provider?->name ?? '-' }}</td>
                                        <td class="small">
                                            <div>{{ implode(', ', $agent->data_sources ?? []) }}</div>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                @foreach($agent->allowed_tools ?? [] as $tool)
                                                    <span class="badge text-bg-light border">{{ $toolOptions[$tool] ?? $tool }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="small">
                                            <div>{{ $agent->roles->pluck('name')->join(', ') ?: '-' }}</div>
                                            @if($agent->default_domains)
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                    @foreach($agent->default_domains as $domain)
                                                        <span class="badge text-bg-primary-subtle border text-primary-emphasis">{{ $domainOptions[$domain] ?? $domain }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($agent->is_default)
                                                <span class="badge text-bg-primary">Default</span>
                                            @endif
                                            <span class="badge {{ $agent->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $agent->is_active ? 'Active' : 'Disabled' }}</span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editAgent({{ $agent->id }})">Edit</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-muted">No AI agents configured yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($providerModalOpen)
        {{-- Provider form modal --}}
        <div class="modal d-block overflow-y-auto" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable my-4">
                <div class="modal-content" style="max-height: calc(100vh - 3rem);">
                    <form wire:submit.prevent="saveProvider">
                        <div class="modal-header">
                            <h2 class="modal-title h5">{{ $editingProviderId ? 'Edit provider' : 'Add provider' }}</h2>
                            <button type="button" class="btn-close" aria-label="Close" wire:click="closeProviderModal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="provider_name" class="form-label">Name</label>
                                    <input id="provider_name" wire:model="providerForm.name" class="form-control @error('providerForm.name') is-invalid @enderror" placeholder="OpenAI production" required>
                                    @error('providerForm.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="col-md-4">
                                    <label for="provider_key" class="form-label">Provider</label>
                                    <select id="provider_key" wire:model.live="providerForm.provider_key" class="form-select @error('providerForm.provider_key') is-invalid @enderror" required>
                                        @foreach($providerOptions as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('providerForm.provider_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="col-md-4">
                                    <label for="api_key" class="form-label">API key</label>
                                    <input id="api_key" wire:model="providerForm.api_key" type="password" class="form-control @error('providerForm.api_key') is-invalid @enderror" autocomplete="new-password" placeholder="{{ $editingProviderId ? 'Stored - leave blank' : 'Optional for Ollama' }}">
                                    @error('providerForm.api_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                @if($this->providerRequiresBaseUrl())
                                    <div class="col-md-6">
                                        <label for="base_url" class="form-label">Base URL</label>
                                        <input id="base_url" wire:model="providerForm.base_url" type="url" class="form-control @error('providerForm.base_url') is-invalid @enderror" placeholder="https://ollama.example.test">
                                        @error('providerForm.base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endif

                                @if(($providerForm['provider_key'] ?? '') === 'azure_openai')
                                    <div class="col-md-3">
                                        <label for="api_version" class="form-label">API version</label>
                                        <input id="api_version" wire:model="providerForm.api_version" class="form-control @error('providerForm.api_version') is-invalid @enderror" placeholder="2024-02-01">
                                        @error('providerForm.api_version')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endif

                                @if(($providerForm['provider_key'] ?? '') === 'openai')
                                    <div class="col-md-3">
                                        <label for="organization_id" class="form-label">Organization</label>
                                        <input id="organization_id" wire:model="providerForm.organization_id" class="form-control @error('providerForm.organization_id') is-invalid @enderror" placeholder="Optional">
                                        @error('providerForm.organization_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endif

                                <div class="col-md-4">
                                    <label for="default_model" class="form-label">Default model</label>
                                    <select id="default_model" wire:model="providerForm.default_model" class="form-select @error('providerForm.default_model') is-invalid @enderror">
                                        <option value="">Select after fetching models</option>
                                        @foreach($modelOptions as $model)
                                            <option value="{{ $model }}">{{ $model }}</option>
                                        @endforeach
                                    </select>
                                    @error('providerForm.default_model')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                </div>

                                <div class="col-md-4">
                                    <label for="embedding_model" class="form-label">Embedding model</label>
                                    <input id="embedding_model" wire:model="providerForm.embedding_model" class="form-control @error('providerForm.embedding_model') is-invalid @enderror" placeholder="Optional">
                                    @error('providerForm.embedding_model')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>

                                <div class="col-md-2">
                                    <label for="provider_status" class="form-label">Status</label>
                                    <select id="provider_status" wire:model="providerForm.status" class="form-select">
                                        <option value="active">Active</option>
                                        <option value="disabled">Disabled</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-primary me-auto" wire:click="fetchModels" wire:loading.attr="disabled" wire:target="fetchModels">
                                <i class="bi bi-cloud-arrow-down"></i>
                                Fetch models
                            </button>
                            @if($editingProviderId)
                                <button type="button" class="btn btn-outline-danger" wire:click="confirmDeleteProvider">Delete provider</button>
                            @endif
                            <button type="button" class="btn btn-light" wire:click="closeProviderModal">Cancel</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveProvider">
                                {{ $editingProviderId ? 'Save provider' : 'Add provider' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop show"></div>
    @endif

    @if($agentModalOpen)
        {{-- Agent form modal --}}
        <div class="modal d-block overflow-y-auto" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable my-4">
                <div class="modal-content" style="max-height: calc(100vh - 3rem);">
                    <form wire:submit.prevent="saveAgent">
                        <div class="modal-header">
                            <h2 class="modal-title h5">{{ $editingAgentId ? 'Edit agent' : 'Add agent' }}</h2>
                            <button type="button" class="btn-close" aria-label="Close" wire:click="closeAgentModal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                        <div class="col-md-3">
                            <label for="agent_name" class="form-label">Name</label>
                            <input id="agent_name" wire:model="agentForm.name" class="form-control @error('agentForm.name') is-invalid @enderror" required>
                            @error('agentForm.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="agent_provider" class="form-label">Provider</label>
                            <select id="agent_provider" wire:model="agentForm.ai_provider_id" class="form-select">
                                <option value="">No provider yet</option>
                                @foreach($providers as $provider)
                                    <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="agent_model" class="form-label">Model override</label>
                            <input id="agent_model" wire:model="agentForm.model" class="form-control" placeholder="Use provider default">
                        </div>
                        <div class="col-md-3">
                            <label for="agent_slug" class="form-label">Slug</label>
                            <input id="agent_slug" wire:model="agentForm.slug" class="form-control" placeholder="Auto from name">
                        </div>
                        <div class="col-12">
                            <label for="agent_instructions" class="form-label">Instructions</label>
                            <textarea id="agent_instructions" wire:model="agentForm.instructions" rows="5" class="form-control @error('agentForm.instructions') is-invalid @enderror" required></textarea>
                            @error('agentForm.instructions')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-3">
                            <div class="fw-semibold mb-2">Data access</div>
                            @foreach($dataSourceOptions as $key => $label)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" wire:model="agentForm.data_sources" value="{{ $key }}" id="data_source_{{ $key }}">
                                    <label class="form-check-label" for="data_source_{{ $key }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                        <div class="col-md-3">
                            <div class="fw-semibold mb-2">Tools</div>
                            @foreach($toolGroups as $mode => $tools)
                                <div class="mb-3">
                                    <div class="small text-muted text-uppercase">{{ $mode }}</div>
                                    @foreach($tools as $key => $tool)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" wire:model="agentForm.allowed_tools" value="{{ $key }}" id="tool_{{ str_replace('.', '_', $key) }}">
                                            <label class="form-check-label" for="tool_{{ str_replace('.', '_', $key) }}">{{ $tool['label'] }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                            <div class="small text-muted">Write tools are ignored unless API actions are enabled.</div>
                        </div>
                        <div class="col-md-3">
                            <div class="fw-semibold mb-2">API scopes</div>
                            @foreach($apiScopeOptions as $key => $label)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" wire:model="agentForm.allowed_api_scopes" value="{{ $key }}" id="api_scope_{{ str_replace('.', '_', $key) }}">
                                    <label class="form-check-label" for="api_scope_{{ str_replace('.', '_', $key) }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                        <div class="col-md-3">
                            <div class="fw-semibold mb-2">Role access</div>
                            @forelse($roles as $role)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" wire:model="agentForm.role_ids" value="{{ $role->id }}" id="role_{{ $role->id }}">
                                    <label class="form-check-label" for="role_{{ $role->id }}">{{ $role->name }}</label>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">No roles exist yet.</p>
                            @endforelse
                        </div>

                        <div class="col-12">
                            <div class="border rounded-2 p-3">
                                <div class="fw-semibold mb-2">Domain defaults</div>
                                <div class="row g-2">
                                    @foreach($domainOptions as $key => $label)
                                        <div class="col-sm-6 col-lg-4">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" wire:model="agentForm.default_domains" value="{{ $key }}" id="default_domain_{{ $key }}">
                                                <label class="form-check-label" for="default_domain_{{ $key }}">{{ $label }}</label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="small text-muted mt-2">Domain defaults select this agent first for matching pages. The global default agent remains the fallback.</div>
                            </div>
                        </div>

                            </div>
                        </div>
                        <div class="modal-footer d-flex flex-wrap align-items-center gap-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" wire:model="agentForm.is_active" id="agent_is_active">
                                <label class="form-check-label" for="agent_is_active">Active</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" wire:model="agentForm.is_default" id="agent_is_default">
                                <label class="form-check-label" for="agent_is_default">Default agent</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" wire:model="agentForm.can_execute_actions" id="agent_can_execute_actions">
                                <label class="form-check-label" for="agent_can_execute_actions">Allow API actions</label>
                            </div>
                            <button type="button" class="btn btn-light ms-auto" wire:click="closeAgentModal">Cancel</button>
                            @if($editingAgentId)
                                <button type="button" class="btn btn-outline-danger" wire:click="confirmDeleteAgent">Delete agent</button>
                            @endif
                            <button type="submit" class="btn btn-primary">
                                {{ $editingAgentId ? 'Save agent' : 'Add agent' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop show"></div>
    @endif

    @if($deleteModalOpen)
        {{-- Destructive confirmation modal --}}
        <div class="modal d-block overflow-y-auto" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog">
                <div class="modal-content border-danger">
                    <div class="modal-header">
                        <h2 class="modal-title h5">Confirm delete</h2>
                        <button type="button" class="btn-close" aria-label="Close" wire:click="cancelDelete"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            Delete {{ $pendingDeleteType }} <strong>{{ $pendingDeleteName }}</strong>?
                        </p>
                        <p class="text-muted mb-0">
                            This cannot be undone. Providers can be removed even when agents reference them; those agents will keep their configuration but lose the provider link.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="cancelDelete">Cancel</button>
                        <button type="button" class="btn btn-danger" wire:click="deleteConfirmed">Delete</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop show"></div>
    @endif
</div>
