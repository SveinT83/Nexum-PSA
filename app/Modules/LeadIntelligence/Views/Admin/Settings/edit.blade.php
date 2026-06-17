@extends('layouts.default_tech')

@section('title', 'Lead Intelligence Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Lead Intelligence Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Lead Intelligence settings form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.lead-intelligence.update') }}" class="d-grid gap-3">
        @csrf
        @method('PUT')

        @if(session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Automation Policy</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach([
                        'enabled' => 'Enable Lead Intelligence',
                        'auto_create_clients' => 'Allow automatic Client lead candidates',
                        'auto_create_contacts' => 'Allow automatic Contact creation',
                        'auto_add_to_marketing_lists' => 'Allow automatic marketing-list promotion',
                    ] as $key => $label)
                        <div class="col-lg-6">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <div class="form-check">
                                <input type="checkbox" id="{{ $key }}" name="{{ $key }}" value="1" class="form-check-input" @checked(old($key, $settings[$key]))>
                                <label for="{{ $key }}" class="form-check-label">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach

                    <div class="col-lg-6">
                        <label for="default_client_status" class="form-label">Default client status</label>
                        <input type="text" id="default_client_status" name="default_client_status" class="form-control @error('default_client_status') is-invalid @enderror" value="{{ old('default_client_status', $settings['default_client_status']) }}">
                        @error('default_client_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="default_rescan_days" class="form-label">Default rescan days</label>
                        <input type="number" min="1" max="3650" id="default_rescan_days" name="default_rescan_days" class="form-control @error('default_rescan_days') is-invalid @enderror" value="{{ old('default_rescan_days', $settings['default_rescan_days']) }}">
                        @error('default_rescan_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Contact Eligibility</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach([
                        'allow_generic_company_emails' => 'Allow generic company emails',
                        'allow_role_based_emails' => 'Allow role-based emails',
                        'allow_named_work_emails' => 'Allow named work emails',
                        'never_auto_use_private_email_domains' => 'Never auto-use private email domains',
                        'require_source_url_for_contacts' => 'Require source URL for contacts',
                        'require_role_for_named_contacts' => 'Require role for named contacts',
                    ] as $key => $label)
                        <div class="col-lg-6">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <div class="form-check">
                                <input type="checkbox" id="{{ $key }}" name="{{ $key }}" value="1" class="form-check-input" @checked(old($key, $settings[$key]))>
                                <label for="{{ $key }}" class="form-check-label">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach

                    <div class="col-lg-8">
                        <label for="allowed_roles" class="form-label">Allowed roles</label>
                        <textarea id="allowed_roles" name="allowed_roles" rows="5" class="form-control @error('allowed_roles') is-invalid @enderror">{{ old('allowed_roles', implode(PHP_EOL, $settings['allowed_roles'])) }}</textarea>
                        @error('allowed_roles') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-2">
                        <label for="minimum_company_score" class="form-label">Min company score</label>
                        <input type="number" min="0" max="100" id="minimum_company_score" name="minimum_company_score" class="form-control @error('minimum_company_score') is-invalid @enderror" value="{{ old('minimum_company_score', $settings['minimum_company_score']) }}">
                        @error('minimum_company_score') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-2">
                        <label for="minimum_contact_score" class="form-label">Min contact score</label>
                        <input type="number" min="0" max="100" id="minimum_contact_score" name="minimum_contact_score" class="form-control @error('minimum_contact_score') is-invalid @enderror" value="{{ old('minimum_contact_score', $settings['minimum_contact_score']) }}">
                        @error('minimum_contact_score') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Run Limits</h2>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-lg-4">
                        <label for="max_pages_per_domain" class="form-label">Max pages per domain</label>
                        <input type="number" min="1" id="max_pages_per_domain" name="max_pages_per_domain" class="form-control @error('max_pages_per_domain') is-invalid @enderror" value="{{ old('max_pages_per_domain', $settings['max_pages_per_domain']) }}">
                        @error('max_pages_per_domain') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="max_tokens_per_run" class="form-label">Max tokens per run</label>
                        <input type="number" min="1" id="max_tokens_per_run" name="max_tokens_per_run" class="form-control @error('max_tokens_per_run') is-invalid @enderror" value="{{ old('max_tokens_per_run', $settings['max_tokens_per_run']) }}">
                        @error('max_tokens_per_run') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="max_new_leads_per_run" class="form-label">Max new leads per run</label>
                        <input type="number" min="1" id="max_new_leads_per_run" name="max_new_leads_per_run" class="form-control @error('max_new_leads_per_run') is-invalid @enderror" value="{{ old('max_new_leads_per_run', $settings['max_new_leads_per_run']) }}">
                        @error('max_new_leads_per_run') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">AI Discovery Planning</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach([
                        'ai_discovery_planning_enabled' => 'Use AI to plan discovery searches',
                        'ai_discovery_planning_required' => 'Require AI discovery plan before worker execution',
                    ] as $key => $label)
                        <div class="col-lg-6">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <div class="form-check">
                                <input type="checkbox" id="{{ $key }}" name="{{ $key }}" value="1" class="form-check-input" @checked(old($key, $settings[$key]))>
                                <label for="{{ $key }}" class="form-check-label">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach

                    <div class="col-lg-8">
                        <label for="ai_discovery_planning_prompt" class="form-label">AI discovery prompt</label>
                        <textarea id="ai_discovery_planning_prompt" name="ai_discovery_planning_prompt" rows="13" class="form-control font-monospace @error('ai_discovery_planning_prompt') is-invalid @enderror">{{ old('ai_discovery_planning_prompt', $settings['ai_discovery_planning_prompt']) }}</textarea>
                        @error('ai_discovery_planning_prompt') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="discovery_sources" class="form-label">Discovery sources</label>
                        <textarea id="discovery_sources" name="discovery_sources" rows="4" class="form-control @error('discovery_sources') is-invalid @enderror">{{ old('discovery_sources', implode(PHP_EOL, $settings['discovery_sources'])) }}</textarea>
                        @error('discovery_sources') <div class="invalid-feedback">{{ $message }}</div> @enderror

                        <label for="brreg_base_url" class="form-label mt-3">BRREG base URL</label>
                        <input type="url" id="brreg_base_url" name="brreg_base_url" class="form-control @error('brreg_base_url') is-invalid @enderror" value="{{ old('brreg_base_url', $settings['brreg_base_url']) }}">
                        @error('brreg_base_url') <div class="invalid-feedback">{{ $message }}</div> @enderror

                        <input type="hidden" name="web_search_enabled" value="0">
                        <div class="form-check mt-3">
                            <input type="checkbox" id="web_search_enabled" name="web_search_enabled" value="1" class="form-check-input" @checked(old('web_search_enabled', $settings['web_search_enabled']))>
                            <label for="web_search_enabled" class="form-check-label">Use web search to find prospect websites</label>
                        </div>

                        <label for="web_search_provider" class="form-label mt-3">Web-search provider</label>
                        <select id="web_search_provider" name="web_search_provider" class="form-select @error('web_search_provider') is-invalid @enderror">
                            @foreach([
                                'ai_provider' => 'AI provider web search',
                                'endpoint' => 'Custom search endpoint',
                                'disabled' => 'Disabled',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('web_search_provider', $settings['web_search_provider']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('web_search_provider') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">AI provider web search uses the active Lead Intelligence OpenAI agent and its existing API key. No endpoint URL is needed, but the agent model must support Responses API web search.</div>

                        <label for="web_search_endpoint_url" class="form-label mt-3">Custom endpoint URL</label>
                        <input type="url" id="web_search_endpoint_url" name="web_search_endpoint_url" class="form-control @error('web_search_endpoint_url') is-invalid @enderror" value="{{ old('web_search_endpoint_url', $settings['web_search_endpoint_url']) }}">
                        @error('web_search_endpoint_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Only used when provider is Custom search endpoint. It must accept <code>q</code> and <code>limit</code> query parameters and return result URLs.</div>

                        <label for="web_search_results_per_query" class="form-label mt-3">Results per query</label>
                        <input type="number" min="1" max="50" id="web_search_results_per_query" name="web_search_results_per_query" class="form-control @error('web_search_results_per_query') is-invalid @enderror" value="{{ old('web_search_results_per_query', $settings['web_search_results_per_query']) }}">
                        @error('web_search_results_per_query') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">AI Candidate Review</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach([
                        'ai_candidate_review_enabled' => 'Use AI to review discovered candidates',
                        'ai_candidate_review_required' => 'Require AI approval before automatic creation',
                    ] as $key => $label)
                        <div class="col-lg-6">
                            <input type="hidden" name="{{ $key }}" value="0">
                            <div class="form-check">
                                <input type="checkbox" id="{{ $key }}" name="{{ $key }}" value="1" class="form-check-input" @checked(old($key, $settings[$key]))>
                                <label for="{{ $key }}" class="form-check-label">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach

                    <div class="col-12">
                        <label for="ai_candidate_review_prompt" class="form-label">AI review prompt</label>
                        <textarea id="ai_candidate_review_prompt" name="ai_candidate_review_prompt" rows="14" class="form-control font-monospace @error('ai_candidate_review_prompt') is-invalid @enderror">{{ old('ai_candidate_review_prompt', $settings['ai_candidate_review_prompt']) }}</textarea>
                        @error('ai_candidate_review_prompt') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="lead-intelligence" />
@endsection

@section('rightbar')
    <!-- ------------------------------------------------- -->
    <!-- Lead Intelligence scope note -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Current Slice</h2>
        </div>
        <div class="card-body small">
            <p class="mb-0">This slice stores policy, segments, executable runs, evidence, eligibility, suppression, and candidate promotion into Clients, Contacts, and Marketing lists. It can use AI discovery planning, BRREG, AI-provider or endpoint web search, shallow website email discovery, and grounded AI candidate review. It does not run deep crawling, invent contacts, or send email.</p>
        </div>
    </div>
@endsection
